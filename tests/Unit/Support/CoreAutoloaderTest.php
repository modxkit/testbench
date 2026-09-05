<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Support;

use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\CoreAutoloader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * All four tests below require `#[RunInSeparateProcess]`: any test based on
 * `ModxKit\Testbench\Unit\UnitTestCase` that ran earlier in the same PHPUnit process (for example
 * `tests/Unit/UnitTestCaseTest.php`) calls `CoreAutoloader::register()` successfully and thereby
 * flips the static flag `CoreAutoloader::$registered` to `true` FOREVER — the `tearDown()` here
 * resets it back only between the tests of THIS file, not between files. Without isolation the
 * result depends on the alphabetical order of directories (`tests/Unit/Support/` against
 * `tests/Unit/UnitTestCaseTest.php`) and breaks under `--order-by=reverse`/`--order-by=random` —
 * exactly the class of defect seen before.
 */
#[Group('unit')]
final class CoreAutoloaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/modx-core-autoloader-' . bin2hex(random_bytes(4));

        mkdir($this->tempDir, 0o775, true);

        // `phpunit.xml` now loads `bootstrap.php`, and that one registers the real core through
        // `CoreAutoloader::register()` on its own even BEFORE the first test — that is, before and
        // inside the `#[RunInSeparateProcess]` child process too (the isolation re-executes the whole
        // bootstrap). The flag is cleared right here rather than only in tearDown(): otherwise both
        // tests of this file would start with `isRegistered() === true` and would fail exactly where
        // they used to prove the opposite. The modX class itself is not parsed again in the process —
        // `CoreAutoloader::register()` only registers the core's PSR-4 autoloader (a lazy one) rather
        // than loading modX eagerly, so this is safe.
        $this->setRegisteredFlag(false);
        (new ReflectionProperty(CoreAutoloader::class, 'registeredPath'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));

        // Reset the process-wide registration flag so tests remain order-independent.
        $this->setRegisteredFlag(false);
        (new ReflectionProperty(CoreAutoloader::class, 'registeredPath'))->setValue(null, null);
    }

    #[RunInSeparateProcess]
    public function testThrowsAClearExceptionWhenCoreAutoloadFileIsMissing(): void
    {
        try {
            CoreAutoloader::register($this->tempDir);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString(
                $this->tempDir . '/vendor/autoload.php',
                $exception->getMessage()
            );
            self::assertStringContainsString('MODX_TESTBENCH_CORE_PATH', $exception->getMessage());
            // A documentation debt: the earlier edition asserted a bare `bin/modx-testbench install` —
            // advice that does NOT fix a failure on this path (reproduced against a real extra). What is
            // asserted is exactly the action that does fix it: rebuilding the working directory through
            // `--force`.
            self::assertStringContainsString('bin/modx-testbench install --force', $exception->getMessage());
            self::assertStringContainsString('index.php, setup/ and core/vendor/autoload.php', $exception->getMessage());
        }

        // Whether isRegistered() is true depends on whether some *other* test in this
        // process already loaded the real modX class — that is not this failure path's
        // concern. What this test must prove is that a failed register() call did not
        // flip CoreAutoloader's own registration flag, so check that flag directly.
        self::assertFalse($this->registeredFlag());
        self::assertNull(CoreAutoloader::registeredPath());
    }

    #[RunInSeparateProcess]
    public function testRegistersASyntheticAutoloadFileWithoutTouchingTheRealCore(): void
    {
        $markerFile = $this->tempDir . '/loaded.marker';
        mkdir($this->tempDir . '/vendor', 0o775, true);
        file_put_contents(
            $this->tempDir . '/vendor/autoload.php',
            '<?php file_put_contents(' . var_export($markerFile, true) . ', "x", FILE_APPEND);'
        );

        self::assertFalse($this->registeredFlag());

        CoreAutoloader::register($this->tempDir);

        self::assertTrue($this->registeredFlag());
        self::assertTrue(CoreAutoloader::isRegistered());
        self::assertSame('x', file_get_contents($markerFile));

        // One `registeredPath()` snapshot into a separate variable rather than a repeated call inside
        // assertSame() (and all the more so before and after another assertSame on the same
        // expression) — PHPStan (`phpstan-phpunit`) under a tight alternation of
        // assert-mutation-assert-mutation-assert on ONE AND THE SAME static call loses the coherence
        // of the type between the narrowings and reports `argument.unresolvableType`, even though the
        // code itself is correct (reproduced empirically and recorded as a known limitation of the
        // analysis, not as a reason to drop the check).
        $registeredPath = CoreAutoloader::registeredPath();
        self::assertSame($this->tempDir, $registeredPath);

        // Registering again must be a no-op: it must not require the autoload file a
        // second time, so the marker file's contents must not grow.
        CoreAutoloader::register($this->tempDir);

        self::assertSame('x', file_get_contents($markerFile));
    }

    /**
     * `registerIfAvailable()` is LEVEL 1 code, and until this was written it was executed only in
     * the `integration` suite (through the `bootstrap.php` of the child process in
     * `BootstrapGuardTest`): a run of `--testsuite unit` alone never entered it once. Here both
     * non-fatal branches are covered by a unit test — with a synthetic directory, like the
     * neighbouring `register()` test above, that is, without a real MODX distribution and without a
     * database.
     *
     * The third branch (the file is in place but corrupted) is not covered by a unit test here — but
     * NOT because it "cannot be intercepted", as the first edition of this comment claimed (that
     * claim was refuted by a direct run on PHP 8.4.8 — a `require_once` of an unparseable file
     * throws an ORDINARY catchable `\ParseError`, and the process carries on). The real reason is
     * the subject of the check: a corrupted autoloader matters not in itself but because
     * `bootstrap.php` is NOT obliged to muffle it (`CoreAutoloader::registerIfAvailable()`), that is,
     * what has to be checked is the whole bootstrap path down to the first test. That path is no
     * longer available inside the same PHPUnit process — the bootstrap ran before any test method.
     * So the branch is checked by
     * {@see \ModxKit\Testbench\Tests\Integration\Bootstrap\BootstrapGuardTest} with a real child
     * process.
     */
    #[RunInSeparateProcess]
    public function testRegisterIfAvailableStaysSilentWhenTheAutoloadFileIsMissing(): void
    {
        self::assertFalse($this->registeredFlag());

        // A contrast with `register()` (the test above): that one throws a TestbenchException on the
        // same input. The soft variant must RETURN false without throwing — otherwise the `catch` in
        // `bootstrap.php` would spawn a second exception that nobody catches any more.
        self::assertFalse(CoreAutoloader::registerIfAvailable($this->tempDir));

        self::assertFalse($this->registeredFlag());
        self::assertNull(CoreAutoloader::registeredPath());
    }

    #[RunInSeparateProcess]
    public function testRegisterIfAvailableLoadsTheAutoloadFileWhenItIsOnDisk(): void
    {
        $markerFile = $this->tempDir . '/loaded.marker';
        mkdir($this->tempDir . '/vendor', 0o775, true);
        file_put_contents(
            $this->tempDir . '/vendor/autoload.php',
            '<?php file_put_contents(' . var_export($markerFile, true) . ', "x", FILE_APPEND);'
        );

        self::assertFalse($this->registeredFlag());

        self::assertTrue(CoreAutoloader::registerIfAvailable($this->tempDir));

        self::assertTrue($this->registeredFlag());
        self::assertSame('x', file_get_contents($markerFile));

        $registeredPath = CoreAutoloader::registeredPath();
        self::assertSame($this->tempDir, $registeredPath);

        // A repeated call is a no-op: the file must not be included a second time (the marker does not grow).
        self::assertTrue(CoreAutoloader::registerIfAvailable($this->tempDir));

        self::assertSame('x', file_get_contents($markerFile));
    }

    /**
     * A second registration by a DIFFERENT path used to be a silent no-op: the caller got a
     * "success", while the core from the first directory stayed in the process. From there the
     * consequences diverged — a test worked against one distribution believing it was working
     * against another.
     *
     * Level 2 is protected from this by `KernelBootstrapper::assertSingleCorePerProcess()`, but that
     * one is called only when the core is loaded — a direct call to `register()` (level 1,
     * `Unit\UnitTestCase::setUp()` through `MODX_TESTBENCH_CORE_PATH`) went past it silently.
     */
    #[RunInSeparateProcess]
    public function testSecondRegistrationFromAnotherPathIsRefusedInsteadOfSilentlyIgnored(): void
    {
        mkdir($this->tempDir . '/vendor', 0o775, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        CoreAutoloader::register($this->tempDir);

        try {
            CoreAutoloader::register($this->tempDir . '-other');
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString($this->tempDir, $exception->getMessage());
            self::assertStringContainsString($this->tempDir . '-other', $exception->getMessage());
        }

        // A trailing slash means the very same directory, not another one: `bootstrap.php` passes the
        // path with it (`Workspace::corePath()`), and `UnitTestCase` without.
        CoreAutoloader::register($this->tempDir . '/');

        $registeredPath = CoreAutoloader::registeredPath();
        self::assertSame($this->tempDir, $registeredPath);
    }

    private function registeredFlag(): bool
    {
        return (bool) (new ReflectionProperty(CoreAutoloader::class, 'registered'))->getValue();
    }

    private function setRegisteredFlag(bool $value): void
    {
        (new ReflectionProperty(CoreAutoloader::class, 'registered'))->setValue(null, $value);
    }
}

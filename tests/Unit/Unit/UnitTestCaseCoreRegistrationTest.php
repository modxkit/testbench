<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Unit;

use MODX\Revolution\modX;
use ModxKit\Testbench\Bootstrap\BootstrapFailure;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\CoreAutoloader;
use ModxKit\Testbench\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * `TestbenchModx extends modX`, so without the core autoloader registered PHP cannot resolve the
 * parent class when loading `TestbenchModx`. Since PHP 8 that is a catchable `\Error`
 * ("Class "MODX\Revolution\modX" not found") rather than an "uncatchable fatal", as the first
 * version of this comment wrongly claimed (visible as `Error:` rather than `Fatal error:` in the
 * output of a mutation run with the guard removed). `setUp()` must still check the registration
 * beforehand: without that the test would get a bare `\Error` rather than a
 * `ModxKit\Testbench\Exception\TestbenchException`, which breaks the specification's contract that
 * "every exception of the package carries a cause and a next action".
 *
 * Every test of the class must run in a separate process: `CoreAutoloader::isRegistered()` also
 * returns `true` if the real `modX` class has ever been loaded in this PHP process (and any other
 * unit test based on `UnitTestCase` loads it) — without isolation the result would depend on which
 * tests ran earlier, which is not allowed.
 */
#[Group('unit')]
final class UnitTestCaseCoreRegistrationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testSetUpThrowsAClearExceptionInsteadOfAFatalErrorWhenCoreIsMissing(): void
    {
        // The environment variable is cleared explicitly inside the isolated process: it could have
        // been inherited from the process that launched PHPUnit.
        putenv('MODX_TESTBENCH_CORE_PATH');
        unset($_SERVER['MODX_TESTBENCH_CORE_PATH'], $_ENV['MODX_TESTBENCH_CORE_PATH']);

        $this->resetCoreAutoloaderRegistration();

        self::assertFalse(
            CoreAutoloader::isRegistered(),
            'Precondition of the test: in this (fresh, isolated) process the core must not be loaded yet.'
        );

        $testCase = new class ('probe') extends UnitTestCase {
            public function callSetUp(): void
            {
                $this->setUp();
            }
        };

        try {
            $testCase->callSetUp();

            self::fail('A TestbenchException was expected instead of a successful setUp().');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('bootstrap.php', $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_CORE_PATH', $exception->getMessage());
        }
    }

    /**
     * `bootstrap.php` must swallow a failure to prepare, but not erase it. The message below
     * explains the failure with the single hypothesis "the core is not on disk" — and that is wrong
     * if what really happened is that the DBMS did not come up; the genuine cause reaches the test
     * as `previous`.
     */
    #[RunInSeparateProcess]
    public function testSetUpAttachesTheSwallowedBootstrapFailureAsPrevious(): void
    {
        putenv('MODX_TESTBENCH_CORE_PATH');
        unset($_SERVER['MODX_TESTBENCH_CORE_PATH'], $_ENV['MODX_TESTBENCH_CORE_PATH']);

        $this->resetCoreAutoloaderRegistration();

        $cause = new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        BootstrapFailure::record($cause);

        $testCase = new class ('probe') extends UnitTestCase {
            public function callSetUp(): void
            {
                $this->setUp();
            }
        };

        try {
            $testCase->callSetUp();

            self::fail('A TestbenchException was expected instead of a successful setUp().');
        } catch (TestbenchException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        } finally {
            BootstrapFailure::forget();
        }
    }

    /**
     * Without this test, lines 24-30 of `UnitTestCase::setUp()` — the registration path through
     * `MODX_TESTBENCH_CORE_PATH`, including the very construction of
     * `rtrim($corePath, '/') . '/core'` — are covered by nothing at all: `bootstrap.php` registers
     * the core first in EVERY process, isolated child processes included, so
     * `!CoreAutoloader::isRegistered()` in `setUp()` is never true and `register()` on line 28 is
     * never called by any existing test — measured by mutation.
     *
     * The key idea (proposed and checked by hand by a reviewer): `MODX_TESTBENCH_CORE_PATH` is
     * pointed at the very same distribution whose `/core` subdirectory `bootstrap.php` of THIS
     * process has already registered. `rtrim($corePath, '/') . '/core'` in `setUp()` then resolves
     * to LITERALLY the same `vendor/autoload.php` file that `bootstrap.php` has already included —
     * the `require_once` inside `CoreAutoloader::register()` becomes a no-op by path rather than a
     * second, different copy of the distribution: the `ComposerAutoloaderInit…` collision that
     * would have wrecked an attempt to run this against an ARBITRARY other distribution simply has
     * nowhere to come from here.
     *
     * The path is taken from `CoreAutoloader::registeredPath()` and NOT from
     * `TestbenchKernel::instance()->prepare()->path()`, as it used to be. The earlier variant relied
     * on `prepare()` being a no-op in a child process against an already installed workspace, and
     * that holds exactly as long as the database is up: with the database off, `prepare()` goes into
     * the reinstall branch and fails, and this test fell with it.
     *
     * The defect was precisely THAT — a unit test depending on a live DBMS, which docs/SPEC.md:31
     * does not allow ("level 1 … must work with the database off"). The restriction "level 1 does
     * not pull in level 2" has nothing to do with it: that one is addressed to production code
     * (`src/Unit/`, `src/Stubs/`, `src/Support/CoreAutoloader.php`), while in `tests/Unit/` level 2
     * imports are the norm (`tests/Unit/Environment/WorkspaceTest.php`,
     * `tests/Unit/Database/SnapshotManagerTest.php`), so the `use TestbenchKernel` that disappeared
     * from here fixed nothing by itself. `registeredPath()` was chosen because it gives the "the
     * very same distribution" guarantee strictly more strongly (it is literally the path
     * `register()` was called with by this process's bootstrap) and does not touch the database
     * while doing so.
     */
    #[RunInSeparateProcess]
    public function testSetUpRegistersTheCoreFromTheEnvironmentVariable(): void
    {
        $registeredCorePath = CoreAutoloader::registeredPath();

        if ($registeredCorePath === null) {
            // `fail`, and NOT `markTestSkipped`. This is the only test pinning the
            // `MODX_TESTBENCH_CORE_PATH` branch in `UnitTestCase::setUp()`
            // (src/Unit/UnitTestCase.php:24-29); with a `skip` it could quietly drop out of
            // coverage without colouring the run — whereas the same scenario used to produce an
            // error, that is, used to be noticeable. A red test here is not a false alarm:
            // `bootstrap.php` failed to lay the core out on disk, and for that same reason the
            // whole rest of the unit suite is red right now (`UnitTestCase::setUp()` throws its
            // own diagnosable exception).
            self::fail(
                'The bootstrap.php of this process did not register the core: the distribution files are not '
                . 'on disk (they did not download or did not unpack). The test has nothing to put into '
                . 'MODX_TESTBENCH_CORE_PATH, and it must not prepare the environment itself — that would take '
                . 'a unit test into a dependency on a live DBMS (docs/SPEC.md:31).'
            );
        }

        // `register()` was called from bootstrap.php as `$workspace->corePath()` = `<root>/core/`, and
        // `CoreAutoloader` stores it without the trailing slash — so the root of the distribution is
        // exactly the parent directory. That is what `UnitTestCase::setUp()` expects, and it appends
        // `/core` back itself.
        $distributionRoot = \dirname($registeredCorePath);

        $this->resetCoreAutoloaderRegistration();

        self::assertFalse(
            CoreAutoloader::isRegistered(),
            'Precondition of the test: in this (fresh, isolated) process the registration must still be '
            . 'cleared — otherwise the setUp() below will not reach the MODX_TESTBENCH_CORE_PATH branch.'
        );

        putenv('MODX_TESTBENCH_CORE_PATH=' . $distributionRoot);
        $_SERVER['MODX_TESTBENCH_CORE_PATH'] = $distributionRoot;

        $testCase = new class ('probe') extends UnitTestCase {
            public function callSetUp(): void
            {
                $this->setUp();
            }

            public function probeModx(): modX
            {
                return $this->modx;
            }
        };

        $testCase->callSetUp();

        self::assertSame($registeredCorePath, CoreAutoloader::registeredPath());

        // Not assertInstanceOf(modX::class, ...): probeModx() is already declared as returning modX
        // (PHPStan knows the type provably and statically — staticMethod.alreadyNarrowedType, the same
        // technique as in testStubIsARealModxInstance). The real, non-trivial check is that setUp()
        // actually built a WORKING stub — that it did not open a database connection, even though by
        // type it is a full modX.
        self::assertNull($testCase->probeModx()->pdo, 'A level 1 stub must not open a database connection.');
    }

    /**
     * `phpunit.xml` now points at `bootstrap.php`, and that one registers the real core through
     * `CoreAutoloader::register()` BEFORE the first test — in this isolated child process included
     * (the isolation re-executes the whole bootstrap). The registration is cleared right here so
     * that every test of this class decides for itself what happens next in `UnitTestCase::setUp()`.
     * The `modX` class is not parsed at that point (`CoreAutoloader::register()` only registers the
     * core's lazy PSR-4 autoloader), so clearing the flag makes `isRegistered()` honest again — the
     * class itself could no longer be taken off the stage.
     */
    private function resetCoreAutoloaderRegistration(): void
    {
        (new ReflectionProperty(CoreAutoloader::class, 'registered'))->setValue(null, false);
        (new ReflectionProperty(CoreAutoloader::class, 'registeredPath'))->setValue(null, null);
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Unit;

use ModxKit\Testbench\Bootstrap\BootstrapFailure;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Stubs\TestbenchModx;
use ModxKit\Testbench\Support\CoreAutoloader;
use ModxKit\Testbench\Support\Env;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * The base class of level 1 tests: `$this->modx` is a core stub without a database (see
 * `TestbenchModx`).
 */
abstract class UnitTestCase extends PHPUnitTestCase
{
    protected TestbenchModx $modx;

    protected function setUp(): void
    {
        parent::setUp();

        if (!CoreAutoloader::isRegistered()) {
            $corePath = Env::get('MODX_TESTBENCH_CORE_PATH');

            if ($corePath !== null) {
                CoreAutoloader::register(rtrim($corePath, '/') . '/core');
            }
        }

        // `TestbenchModx extends modX`, so without a registered core autoloader PHP cannot resolve
        // the parent class while loading `TestbenchModx`. Since PHP 8 that is a CATCHABLE `\Error`
        // (`Class "MODX\Revolution\modX" not found`), not an "uncatchable fatal" as the first
        // version of this comment claimed (visible plainly as `Error:` rather than `Fatal error:` in
        // the output of the mutation test). The check here is still needed: without it the test
        // would get a bare `\Error` instead of a `TestbenchException` — and would thereby break the
        // specification's contract "every exception of the package carries a cause and a next
        // action" (an `\Error` does not obey that contract at all, it is not an exception of the
        // package).
        if (!CoreAutoloader::isRegistered()) {
            // The `previous` is the genuine cause of the `bootstrap.php` failure, if it ran at all
            // and failed (for example the DBMS did not come up). Without it the message below is the
            // only hypothesis, and it is sometimes wrong; see Bootstrap\BootstrapFailure.
            throw new TestbenchException(
                'The MODX core was not found: even the level 1 stub needs the real '
                . '`MODX\\Revolution\\modX`/`xPDO\\xPDO` classes (albeit without a database '
                . 'connection). If the tests are run through the package `bootstrap.php` (see the DX '
                . 'guide), that is usually enough — it prepares the environment and registers the '
                . 'core autoloader itself; make sure your `phpunit.xml` really does reference it as '
                . '`bootstrap`. If `bootstrap.php` is NOT used, set the MODX_TESTBENCH_CORE_PATH '
                . 'environment variable to the root of an installed MODX 3 distribution — with '
                . '`bootstrap.php` in use and the default provider (`zip`) that variable still goes '
                . 'into the fingerprint of the working directory (TestbenchConfig::fingerprint()) and '
                . 'silently steers the preparation into another, not yet installed directory instead '
                . 'of solving this problem. If this exception has a `previous`, that is the real '
                . 'cause `bootstrap.php` stumbled over; look at it first.',
                0,
                BootstrapFailure::last()
            );
        }

        $this->modx = TestbenchModx::create($this->stubOptions());
    }

    /**
     * The system settings available to the stub through getOption().
     *
     * @return array<string, mixed>
     */
    protected function stubOptions(): array
    {
        return [];
    }

    protected function assertEventInvoked(string $name): void
    {
        $names = array_column($this->modx->recorder()->events(), 'name');

        self::assertContains($name, $names, "Event \"{$name}\" was never fired.");
    }

    protected function assertLogged(string $needle): void
    {
        $messages = array_column($this->modx->recorder()->logs(), 'message');

        $matches = array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, $needle)
        );

        self::assertNotEmpty($matches, "The log holds no entry containing \"{$needle}\".");
    }

    protected function assertLexiconUsed(string $key): void
    {
        self::assertContains($key, $this->modx->recorder()->lexiconKeys(), "Lexicon key \"{$key}\" was never requested.");
    }
}

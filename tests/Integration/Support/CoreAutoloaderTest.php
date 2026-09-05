<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Support;

use MODX\Revolution\modX;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Support\CoreAutoloader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * This test used to call `CoreAutoloader::register()` itself on the directory from
 * `MODX_TESTBENCH_CORE_PATH`, proving that the "`/core`" path suffix really does load the real
 * distribution. Now `bootstrap.php` (which `phpunit.xml` points at) calls
 * `CoreAutoloader::register()` itself before the FIRST test in EVERY process — the
 * `#[RunInSeparateProcess]` child processes included, since the isolation re-executes the whole
 * bootstrap. That makes the original scenario ("a clean process, the core not yet registered")
 * structurally unreachable inside this suite: forcibly clearing `CoreAutoloader::$registered` here
 * does not help but harms — `register()` then tries to `require_once` a SECOND, physically
 * different copy of the distribution, and both carry one and the same class name
 * `ComposerAutoloaderInit…` (frozen when the official release was built), which gives the very
 * uncatchable "Cannot redeclare class" fatal that wiring the bootstrap in was meant to remove
 * (verified empirically — that is exactly how this version of the test used to fail).
 *
 * Verified and NOT fixable within this file: "loads ANY given `MODX_TESTBENCH_CORE_PATH` from
 * scratch" is no longer covered by a test of its own. Instead it is proved continuously by the very
 * fact that the suite runs at all — if `bootstrap.php` could not register the core,
 * `TestbenchKernel::instance()->modx()` below (and any level 2 test) would fail immediately. What
 * is checked here is the only thing that CAN be checked without a repeated collision: that
 * `CoreAutoloader::register()`, called AGAIN on the directory of `TestbenchKernel` itself (that is,
 * literally the same file `bootstrap.php` already loaded), stays a safe no-op and correctly returns
 * `registeredPath()` — a prerequisite of
 * {@see \ModxKit\Testbench\Bootstrap\KernelBootstrapper::assertSingleCorePerProcess()}.
 */
#[Group('integration')]
final class CoreAutoloaderTest extends TestCase
{
    public function testRegisteringTheSameCoreAgainIsASafeNoOp(): void
    {
        $workspace = TestbenchKernel::instance()->prepare();

        CoreAutoloader::register($workspace->corePath());

        self::assertTrue(CoreAutoloader::isRegistered());
        self::assertTrue(class_exists(modX::class));
        self::assertSame(rtrim($workspace->corePath(), '/'), CoreAutoloader::registeredPath());
    }
}

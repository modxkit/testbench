<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Bootstrap;

use ModxKit\Testbench\Environment\TestbenchKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FR-BOOT-5: the package's `bootstrap.php` is wired in from the consumer's `phpunit.xml` and must
 * prepare the environment before the first test. The package's own suite does not make it the root
 * bootstrap: in PHPUnit there is one root bootstrap for all suites, and the unit suite runs against
 * stubs and must require neither a DBMS nor an installed MODX.
 */
#[Group('integration')]
final class BootstrapFileTest extends TestCase
{
    protected function tearDown(): void
    {
        TestbenchKernel::reset();
    }

    public function testBootstrapFilePreparesEnvironment(): void
    {
        $bootstrap = dirname(__DIR__, 3) . '/bootstrap.php';

        self::assertFileExists($bootstrap);

        // Resetting the singleton is mandatory: the working directory survives runs on disk, so the
        // check "the environment files exist" would be true even without bootstrap.php doing anything.
        // The test must fail exactly when bootstrap.php stops calling prepare().
        TestbenchKernel::reset();

        self::assertFalse(TestbenchKernel::instance()->isPrepared());

        require $bootstrap;

        $kernel = TestbenchKernel::instance();

        self::assertTrue($kernel->isPrepared());

        $workspace = $kernel->workspace();

        self::assertFileExists($workspace->lockPath());
        self::assertFileExists($workspace->configFile());
        self::assertTrue($workspace->isInstalledWith($kernel->config()->fingerprint()));
    }
}

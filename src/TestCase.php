<?php

declare(strict_types=1);

namespace ModxKit\Testbench;

use MODX\Revolution\Error\modError;
use MODX\Revolution\modX;
use ModxKit\Testbench\Concerns\InteractsWithModx;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Isolation\IsolationStrategy;
use ModxKit\Testbench\Isolation\TransactionIsolation;
use ModxKit\Testbench\Package\PackageDefinition;
use ModxKit\Testbench\Package\PackageRegistrar;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * The base class of integration tests: it hands a booted core in `$this->modx` and rolls back any
 * database changes after every test.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use InteractsWithModx;

    protected modX $modx;

    /** Filled in only after a successful `begin()` — see `setUp()`. */
    private ?IsolationStrategy $isolation = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modx = TestbenchKernel::instance()->modx();

        // `$modx->error` is a core service that lives until the end of the PHPUnit process: neither
        // a transaction nor a snapshot rolls it back. Otherwise the error of a failed processor is
        // inherited by the NEXT test in the response of its own processor and explains an entirely
        // different test. The service is created lazily (`KernelBootstrapper::ensureServices()` puts
        // it into the container, but nothing stops it being replaced from outside), so we check the
        // type.
        if ($this->modx->error instanceof modError) {
            $this->modx->error->reset();
        }

        $isolation = $this->isolationStrategy();
        $isolation->begin($this->modx);

        // The strategy is recorded only after the isolation has really started: otherwise
        // `tearDown()` would try to close what was never opened and would substitute a secondary
        // error for the real cause of the failure.
        $this->isolation = $isolation;

        // The package registration is placed strictly after the strategy is recorded, not between
        // `begin()` and the assignment: a failed registration in that gap would leave the isolation
        // open with no strategy recorded, and `tearDown()` would be unable to close it — the next
        // test would start `begin()` on top of it, giving a cascade of failures with a substituted
        // root cause.
        $definition = $this->packageDefinition();

        if ($definition instanceof PackageDefinition) {
            // `PackageRegistrar::applySettings()` writes into `$modx->config` through
            // `setOption()`, past the transaction/snapshot rollback — there is one core per process
            // (`TestbenchKernel::modx()`), so without a backup the value would survive the end of
            // the test and leak into the next one, right up to the end of the whole run. The keys
            // are backed up BEFORE the registration with the same bookkeeping `setSetting()` uses
            // (`InteractsWithModx::backupModxOption()`): `restoreModxRuntimeState()` in
            // `tearDown()` will take them off as usual, no separate code is needed for that.
            foreach (array_keys($definition->getSettings()) as $key) {
                $this->backupModxOption($key);
            }

            (new PackageRegistrar($this->modx))->register($definition);

            $this->afterPackageRegistered();
        }
    }

    /**
     * An extension point for a step that needs the package ALREADY registered and the isolation
     * ALREADY open: filling a reference table of its own model, placing a file into its own `core/`,
     * substituting a service from `PackageDefinition::service()`.
     *
     * There was nowhere to put such a step: `parent::setUp()` opens the isolation and registers the
     * package in one call, and everything the consumer writes after it stands outside that pair
     * already. It is called only if `packageDefinition()` returned a definition — otherwise there
     * was no registration, and the name of the hook must not claim otherwise.
     */
    protected function afterPackageRegistered(): void
    {
    }

    protected function tearDown(): void
    {
        // A transaction rolls back the database but not the core's memory, so the changes to
        // `$modx->user` and `$modx->config` are taken off separately. The `finally` guarantees the
        // transaction is closed even if the restoration fails: otherwise the next test would start
        // `begin()` on top of an unclosed transaction.
        try {
            $this->restoreModxRuntimeState();
        } finally {
            // PHPUnit calls `tearDown()` after a failed `setUp()` too
            // (`vendor/phpunit/phpunit/src/Framework/TestCase.php:620-648`), when there is no
            // strategy yet: in that case there is nothing to close.
            $this->isolation?->end($this->modx);
        }

        parent::tearDown();
    }

    protected function isolationStrategy(): IsolationStrategy
    {
        return new TransactionIsolation();
    }

    /**
     * The definition of the extra under test. Overridden in the consuming package's tests.
     */
    protected function packageDefinition(): ?PackageDefinition
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration;

use MODX\Revolution\modNamespace;
use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\Exception\PackageRegistrationException;
use ModxKit\Testbench\Isolation\IsolationStrategy;
use ModxKit\Testbench\Isolation\TransactionIsolation;
use ModxKit\Testbench\Package\PackageDefinition;
use ModxKit\Testbench\TestCase;
use ModxKit\Testbench\Tests\Integration\Isolation\RecordingIsolation;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The extension points of the base `TestCase`: the chosen strategy really is called around the test,
 * and there is no extra declaration by default.
 */
#[Group('integration')]
final class TestCaseContractTest extends TestCase
{
    private RecordingIsolation $recorder;

    protected function setUp(): void
    {
        $this->recorder = new RecordingIsolation(new TransactionIsolation());

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The only place where the call to `end()` is visible: the test itself finishes earlier.
        self::assertSame(['begin', 'end'], $this->recorder->calls());
    }

    protected function isolationStrategy(): IsolationStrategy
    {
        return $this->recorder;
    }

    public function testSetUpOpensTransactionThroughSelectedStrategy(): void
    {
        self::assertSame(['begin'], $this->recorder->calls());
        self::assertNotNull($this->modx->pdo);
        self::assertTrue($this->modx->pdo->inTransaction());
    }

    public function testPackageDefinitionIsAbsentUntilTestDeclaresOne(): void
    {
        self::assertNull($this->packageDefinition());
    }

    /**
     * `setUp()` may fail before the isolation has begun (the core did not load, the database is
     * unreachable), and PHPUnit will call `tearDown()` all the same
     * (`vendor/phpunit/phpunit/src/Framework/TestCase.php:620-648`). A secondary error at that point
     * would clutter the report and lead away from the real cause of the failure.
     */
    public function testTearDownIsSilentWhenSetUpFailedBeforeIsolationStarted(): void
    {
        $untouched = new class ('neverRun') extends TestCase {};

        (new ReflectionMethod($untouched, 'tearDown'))->invoke($untouched);

        // This also confirms that a foreign `tearDown()` did not touch the current test's transaction.
        self::assertNotNull($this->modx->pdo);
        self::assertTrue($this->modx->pdo->inTransaction());
    }

    /**
     * Proves that the package registration is inserted strictly after `$this->isolation = $isolation;`
     * rather than between `begin()` and the assignment. Had the order been the other way round, a
     * failed registration would have left `$isolation` empty, and `tearDown()` would not have been
     * able to close an isolation that was already open — the next test would start `begin()` on top
     * of an unclosed one.
     */
    public function testFailedPackageRegistrationStillLeavesIsolationRecordedForTearDown(): void
    {
        // `RefreshesDatabase` rather than the default transaction: the current test already holds its
        // own transaction open on the same connection, and `TransactionIsolation::begin()` on the nested
        // instance would start a second transaction on top of it and would fail before the test gets to
        // the registration failure it is interested in. `SnapshotIsolation::begin()` is a no-op, so
        // there is no conflict.
        $failing = new class ('failingRegistration') extends TestCase {
            use RefreshesDatabase;

            protected function packageDefinition(): PackageDefinition
            {
                return PackageDefinition::make('testcase-16a')
                    ->settings([str_repeat('x', 51) => 'value']);
            }
        };

        try {
            (new ReflectionMethod($failing, 'setUp'))->invoke($failing);

            self::fail('A PackageRegistrationException was expected: an over-long setting key must not be saved.');
        } catch (PackageRegistrationException) {
            // The registration failure is expected — what matters is the state of `$isolation` after it.
        }

        // `isolation` is declared `private` in `TestCase` rather than in the nested anonymous class:
        // `ReflectionProperty` has to be pointed at the declaring class, otherwise
        // `ReflectionProperty::__construct()` does not find the private property of the ancestor.
        $isolation = (new ReflectionProperty(TestCase::class, 'isolation'))->getValue($failing);

        self::assertInstanceOf(
            IsolationStrategy::class,
            $isolation,
            'The isolation strategy must be recorded before the package is registered: otherwise tearDown() '
            . 'will not be able to close an isolation that is already open.'
        );

        // The `tearDown()` of the nested instance is not called: `SnapshotIsolation::begin()` is a
        // no-op, there is nothing to close, and `end()` would reload the database from the snapshot in
        // the middle of an outer test that is still running.
    }

    /**
     * The `afterPackageRegistered()` extension point: there was nowhere to insert a step that needs
     * the package ALREADY registered and the isolation ALREADY open — `parent::setUp()` opens the
     * isolation and registers the package in a single call, and everything the consumer writes after
     * it stands outside that pair.
     */
    public function testAfterPackageRegisteredRunsWithTheNamespaceInPlace(): void
    {
        $probe = new class ('withHook') extends TestCase {
            use RefreshesDatabase;

            public bool $hookRan = false;

            public bool $namespaceWasAlreadyRegistered = false;

            public bool $isolationWasOpen = false;

            protected function packageDefinition(): PackageDefinition
            {
                return PackageDefinition::make('testcase-25-hook');
            }

            protected function afterPackageRegistered(): void
            {
                $this->hookRan = true;
                $this->namespaceWasAlreadyRegistered = $this->modx->getObject(
                    modNamespace::class,
                    ['name' => 'testcase-25-hook']
                ) instanceof modNamespace;
                $this->isolationWasOpen = $this->modx->pdo instanceof PDO
                    && $this->modx->pdo->inTransaction();
            }
        };

        (new ReflectionMethod($probe, 'setUp'))->invoke($probe);

        self::assertTrue($probe->hookRan, 'The hook must be called from setUp().');
        self::assertTrue($probe->namespaceWasAlreadyRegistered, 'By the time of the hook the package is already registered.');
        // The transaction here is the OUTER test's transaction on the same core connection: it is what
        // will roll back everything the hook writes, so "the isolation is open" is not a formality.
        self::assertTrue($probe->isolationWasOpen, 'By the time of the hook the isolation is already open.');
    }

    /**
     * The other side: with no package description there is nothing to register, and a hook named
     * "after the package is registered" must not claim that it was.
     */
    public function testAfterPackageRegisteredIsNotCalledWithoutAPackageDefinition(): void
    {
        $probe = new class ('withoutDefinition') extends TestCase {
            use RefreshesDatabase;

            public bool $hookRan = false;

            protected function afterPackageRegistered(): void
            {
                $this->hookRan = true;
            }
        };

        (new ReflectionMethod($probe, 'setUp'))->invoke($probe);

        self::assertFalse($probe->hookRan);
    }

    /**
     * Proves that a setting applied by the package registration through `setOption()` writes past the
     * database rollback (a snapshot or a transaction rolls back the `modSystemSetting` row, but not
     * the core's memory) — there is one core per process, so without a backup the value would
     * survive the test and leak into the next one, all the way to the end of the run.
     * `TestCase::setUp()` must back the key up BEFORE the registration through the same bookkeeping
     * that `setSetting()` uses (`InteractsWithModx::backupModxOption()`), so that `tearDown()`
     * returns `$modx->config` to its state before the test.
     *
     * The fact of the backup is observed (through the trait's private property) rather than the
     * `tearDown()` of the nested instance being called: `SnapshotIsolation::end()` would reload the
     * database from the snapshot in the middle of an outer test that is still running — the same
     * risk as in the test above.
     */
    public function testPackageSettingIsBackedUpBeforeRegistrationForTearDownToRestore(): void
    {
        $key = 'testcase16e_setting';

        self::assertArrayNotHasKey($key, $this->modx->config, 'The key must not exist in the config before the test.');

        $withSetting = new class ('withSetting') extends TestCase {
            use RefreshesDatabase;

            protected function packageDefinition(): PackageDefinition
            {
                return PackageDefinition::make('testcase-16e')->settings(['testcase16e_setting' => 'leaked']);
            }
        };

        (new ReflectionMethod($withSetting, 'setUp'))->invoke($withSetting);

        self::assertSame(
            'leaked',
            $this->modx->getOption($key),
            'The registration should have applied the setting into the core memory.'
        );

        // `modxOptionBackups` is declared `private` in the `InteractsWithModx` trait, which `TestCase`
        // uses directly — that is the declaring class for Reflection
        // (the same caveat as for `isolation` above).
        $backups = (new ReflectionProperty(TestCase::class, 'modxOptionBackups'))->getValue($withSetting);

        self::assertIsArray($backups);
        self::assertArrayHasKey(
            $key,
            $backups,
            'The key of a setting applied by the package registration must be accounted for in the '
            . 'InteractsWithModx::backupModxOption() backup — otherwise restoreModxRuntimeState() '
            . 'in tearDown() will not return $modx->config to its state before the test.'
        );

        // The config is put back by hand — the nested `setUp()` changed it, and `tearDown()` is not
        // called (see the docblock of the method); otherwise the key would leak into the next test of this file.
        unset($this->modx->config[$key]);
    }
}

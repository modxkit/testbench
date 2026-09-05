<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Bootstrap;

use MODX\Revolution\Error\modError;
use MODX\Revolution\modLexicon;
use MODX\Revolution\modSystemSetting;
use ModxKit\Testbench\Bootstrap\KernelBootstrapper;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Environment\Workspace;
use ModxKit\Testbench\Exception\KernelBootFailedException;
use ModxKit\Testbench\Support\CoreAutoloader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use xPDO\xPDO;

#[Group('integration')]
final class KernelBootstrapperTest extends TestCase
{
    private ?string $temporaryWorkspace = null;

    private ?string $previousWorkspaceDir = null;

    protected function tearDown(): void
    {
        if ($this->temporaryWorkspace !== null) {
            exec('rm -rf ' . escapeshellarg($this->temporaryWorkspace));
            $this->temporaryWorkspace = null;
        }

        if ($this->previousWorkspaceDir === null) {
            unset($_SERVER['MODX_TESTBENCH_WORKSPACE']);
        } else {
            $_SERVER['MODX_TESTBENCH_WORKSPACE'] = $this->previousWorkspaceDir;
        }

        $this->previousWorkspaceDir = null;
    }

    public function testBootedKernelIsUsable(): void
    {
        $modx = TestbenchKernel::instance()->modx();

        self::assertTrue(defined('MODX_API_MODE'));
        self::assertTrue(constant('MODX_API_MODE'));
        self::assertTrue($modx->services->has('error'));
        self::assertTrue($modx->services->has('lexicon'));
        self::assertInstanceOf(modError::class, $modx->error);
        self::assertInstanceOf(modLexicon::class, $modx->lexicon);

        $siteUrl = $modx->getOption('site_url');

        self::assertIsString($siteUrl);
        self::assertNotSame('', $siteUrl);
    }

    public function testBootProducesNoOutput(): void
    {
        $level = ob_get_level();

        ob_start();
        TestbenchKernel::instance()->modx();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
        // index.php:37 leaves an output buffer of its own open in API mode — the bootstrapper must
        // return the buffering level to its original value, otherwise PHPUnit marks the test as risky.
        self::assertSame($level, ob_get_level());
    }

    /**
     * FR-ISO-6: xPDO's result cache survives a transaction rollback, so state isolation is only
     * possible with `cache_db` turned off.
     *
     * What is asserted here is the INVARIANT "the cache is off" rather than a particular value of
     * the setting: `setOption()` puts a literal `false` into `$modx->config` while the core loads,
     * but `modX::reloadConfig()` (called by at least 20 of the core's own processors,
     * `system/settings/update` among them) re-reads the settings from the database and the value
     * becomes a string. An `assertFalse()` against the literal went red on a random-order run when a
     * test with a processor managed to run earlier — even though the cache stayed off the whole time.
     *
     * The cast weakens the assertion, so the bootstrapper's guard itself is checked separately —
     * {@see self::testBootDisablesXpdoResultCacheEvenWhenTheSettingIsOn()}. This test sees only the
     * VALUE of the setting, not the invariant the setting provides: a mutation check (removing the
     * `setOption()` line in the bootstrapper) showed that it stays green even without it —
     * `cache_db` in the database itself is empty (that is, falsy) by default, and matching the
     * expected value proves nothing. The FR-ISO-6 invariant itself (a cached object does not survive
     * a rollback) is checked by {@see self::testCachedObjectDoesNotSurviveRollback()}.
     */
    public function testBootDisablesXpdoResultCache(): void
    {
        $modx = TestbenchKernel::instance()->modx();

        self::assertFalse((bool) $modx->getOption(xPDO::OPT_CACHE_DB));
    }

    /**
     * The FR-ISO-6 guard apart from the state of the database: even if the `cache_db` setting is on,
     * the bootstrapper must turn it off — otherwise a consumer's environment that has the cache on
     * would run without isolation, and a cached object would survive a transaction rollback.
     *
     * Loading again is safe: `requireGateway()` includes `index.php` through `require_once`, so the
     * second call does not execute the core gateway anew, and `modX::getInstance()` returns the same
     * instance — verified by this test.
     */
    public function testBootDisablesXpdoResultCacheEvenWhenTheSettingIsOn(): void
    {
        $kernel = TestbenchKernel::instance();
        $modx = $kernel->modx();

        $modx->setOption(xPDO::OPT_CACHE_DB, true);
        self::assertTrue((bool) $modx->getOption(xPDO::OPT_CACHE_DB));

        try {
            $booted = (new KernelBootstrapper())->boot($kernel->workspace());

            self::assertSame($modx, $booted);
            self::assertFalse($modx->getOption(xPDO::OPT_CACHE_DB));
        } finally {
            // If the load failed, an enabled cache must not leak into the rest of the run's tests.
            $modx->setOption(xPDO::OPT_CACHE_DB, false);
        }
    }

    /**
     * The very substance of FR-ISO-6: "a cached object survives the rollback of a transaction and
     * breaks isolation" is a requirement about BEHAVIOUR, not about the value of a setting. The two
     * tests above check the setting; this one reproduces the mechanism directly and checks its
     * consequence.
     *
     * `cache_db` is turned on explicitly BEFORE the repeated `boot()` — just as in
     * {@see self::testBootDisablesXpdoResultCacheEvenWhenTheSettingIsOn()} — because the database
     * itself returns empty (falsy) for this setting by default: without turning it on explicitly
     * the cache would be off ANYWAY, and the test would not tell a working bootstrapper from a
     * removed `setOption()` line (measured by running with that line deleted:
     * {@see self::testBootDisablesXpdoResultCache()}, the test but one above, stays green for
     * exactly that reason, see its docblock).
     *
     * The scenario: a probe row in `modx_system_settings` is read through `getObject()` — the first
     * read puts the object into xPDO's file cache (if `cache_db` is on, which is what happens here
     * before `boot()`). Then the row is changed and saved INSIDE the transaction, the transaction is
     * rolled back — the database returns to its original value — and the row is read again with a
     * fresh `getObject()`. If the bootstrapper did not turn the cache off, `fromCache()` returns the
     * version from the file, written by the last `save()` BEFORE the rollback, that is, the value
     * that was cancelled: the cache knows nothing about the SQL rollback because it lives outside
     * the transaction (a file on disk). Measured: with a working bootstrapper a script reproducing
     * this scenario prints the original value; with `setOption(cache_db, true)` instead of turning
     * it off — the value cancelled by the rollback.
     */
    public function testCachedObjectDoesNotSurviveRollback(): void
    {
        $kernel = TestbenchKernel::instance();
        $modx = $kernel->modx();
        $key = 'testbench_m14_rollback_probe';

        $modx->setOption(xPDO::OPT_CACHE_DB, true);

        try {
            $booted = (new KernelBootstrapper())->boot($kernel->workspace());
            self::assertSame($modx, $booted);

            $modx->removeObject(modSystemSetting::class, ['key' => $key]);

            $setting = $modx->newObject(modSystemSetting::class);
            self::assertInstanceOf(modSystemSetting::class, $setting);
            $setting->fromArray([
                'key' => $key,
                'value' => 'original',
                'xtype' => 'textfield',
                'namespace' => 'core',
                'area' => '',
            ], '', true, true);
            self::assertTrue($setting->save(), 'the probe setting could not be created');

            try {
                self::assertNotFalse($modx->beginTransaction());

                $insideTransaction = $modx->getObject(modSystemSetting::class, ['key' => $key], true);
                self::assertNotNull($insideTransaction);
                $insideTransaction->set('value', 'changed-inside-rolled-back-transaction');
                self::assertTrue($insideTransaction->save());

                self::assertTrue($modx->rollBack());

                // A new getObject() call rather than reusing $insideTransaction: otherwise the test would be
                // checking the state of a PHP object in memory rather than what the next read actually
                // returns (FROM the database or FROM the cache).
                $afterRollback = $modx->getObject(modSystemSetting::class, ['key' => $key], true);

                self::assertNotNull($afterRollback);
                self::assertSame(
                    'original',
                    $afterRollback->get('value'),
                    'FR-ISO-6: a cached object survived the transaction rollback — the isolation is broken.'
                );
            } finally {
                if ($modx->pdo?->inTransaction() === true) {
                    $modx->rollBack();
                }

                $modx->removeObject(modSystemSetting::class, ['key' => $key]);
                $modx->cacheManager?->delete(modSystemSetting::class, [
                    xPDO::OPT_CACHE_KEY => 'db',
                    xPDO::OPT_CACHE_MULTIPLE_OBJECT_DELETE => true,
                ]);
            }
        } finally {
            // The same order as in the neighbour above: an enabled cache must not leak into the rest of
            // the run's tests, even if something in the scenario failed earlier.
            $modx->setOption(xPDO::OPT_CACHE_DB, false);
        }
    }

    /**
     * The guard for the deprecated-API log — also apart from the state of the database.
     *
     * `modX::deprecated()` reads `getOption('log_deprecated', null, true)`, that is, with the
     * DEFAULT value `true`: a missing or enabled setting means the log is on, and it writes from
     * `register_shutdown_function()` past any isolation. Writing `0` into the install itself closes
     * that, but only for databases where the key exists; the bootstrapper's guard closes the
     * remaining cases too.
     */
    public function testBootDisablesDeprecationLoggingEvenWhenTheSettingIsOn(): void
    {
        $kernel = TestbenchKernel::instance();
        $modx = $kernel->modx();

        $modx->setOption('log_deprecated', true);
        self::assertTrue((bool) $modx->getOption('log_deprecated'));

        try {
            (new KernelBootstrapper())->boot($kernel->workspace());

            self::assertFalse($modx->getOption('log_deprecated'));
        } finally {
            $modx->setOption('log_deprecated', false);
        }
    }

    public function testFailsWhenEnvironmentIsNotInstalled(): void
    {
        $workspace = $this->emptyWorkspace();

        $this->expectException(KernelBootFailedException::class);
        $this->expectExceptionMessage('the environment is not installed');

        (new KernelBootstrapper())->boot($workspace);
    }

    public function testFailsWhenCoreAutoloaderIsMissing(): void
    {
        $workspace = $this->installedWorkspaceStub();
        unlink($workspace->corePath() . 'vendor/autoload.php');

        $this->expectException(KernelBootFailedException::class);
        // With the autoloader missing, `index.php:29-34` prints HTML and calls exit(), so the failure
        // can only be diagnosable before the include.
        $this->expectExceptionMessage('vendor/autoload.php');

        (new KernelBootstrapper())->boot($workspace);
    }

    public function testFailsWhenAnotherCoreIsAlreadyLoadedInThisProcess(): void
    {
        // Make sure the core is already loaded: otherwise the bootstrapper would have nothing to detect.
        TestbenchKernel::instance()->modx();

        $workspace = $this->installedWorkspaceStub();

        try {
            (new KernelBootstrapper())->boot($workspace);
            self::fail('Expected KernelBootFailedException was not thrown.');
        } catch (KernelBootFailedException $exception) {
            self::assertStringContainsString('MODX_CORE_PATH', $exception->getMessage());
            self::assertStringContainsString(
                rtrim(TestbenchKernel::instance()->workspace()->corePath(), '/'),
                $exception->getMessage()
            );
        }
    }

    /**
     * A core registered by level 1 through `CoreAutoloader::register()` (rather than through
     * `requireGateway()`/`index.php`) does NOT define the `MODX_CORE_PATH` constant — without a
     * separate check of `registeredPath()` this case would be invisible to
     * `assertSingleCorePerProcess()`, and an attempt to load the real core would fail with an
     * uncatchable "Cannot redeclare class ComposerAutoloaderInit…" instead of a diagnosable
     * `KernelBootFailedException`. A separate process is used so as not to drag the `CoreAutoloader`
     * registration (which cannot be undone) into the rest of the tests of this class.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailsWhenAnotherCoreIsAlreadyRegisteredViaCoreAutoloader(): void
    {
        // Make sure the real environment is installed on disk (without touching the database if it is
        // already installed and captured to a snapshot — see TestbenchKernel::prepare()) before
        // registering a DIFFERENT, deliberately other directory through CoreAutoloader.
        $workspace = TestbenchKernel::instance()->prepare();

        // `phpunit.xml` now points at `bootstrap.php`, and that one calls
        // `CoreAutoloader::register($workspace->corePath())` itself before the first test (in this
        // isolated child process too — the isolation re-executes the whole bootstrap). Without the
        // reset, the registration on $otherWorkspace below would be a no-op (`register()` returns on
        // its very first line when isRegistered() is already true), and the test would not reproduce
        // the conflict at all.
        (new ReflectionProperty(CoreAutoloader::class, 'registered'))->setValue(null, false);
        (new ReflectionProperty(CoreAutoloader::class, 'registeredPath'))->setValue(null, null);

        $otherWorkspace = $this->installedWorkspaceStub();
        CoreAutoloader::register($otherWorkspace->corePath());

        try {
            (new KernelBootstrapper())->boot($workspace);
            self::fail('Expected KernelBootFailedException was not thrown.');
        } catch (KernelBootFailedException $exception) {
            self::assertStringContainsString('CoreAutoloader::registeredPath()', $exception->getMessage());
            self::assertStringContainsString(rtrim($otherWorkspace->corePath(), '/'), $exception->getMessage());
        }
    }

    /**
     * A process constant cannot be redefined, so the check lives in a separate process.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailsWhenApiModeIsDisabled(): void
    {
        define('MODX_API_MODE', false);

        $workspace = $this->installedWorkspaceStub();

        $this->expectException(KernelBootFailedException::class);
        $this->expectExceptionMessage('MODX_API_MODE');

        (new KernelBootstrapper())->boot($workspace);
    }

    public function testFailsWhenKernelInstanceIsUnavailable(): void
    {
        $workspace = TestbenchKernel::instance()->workspace();

        $this->expectException(KernelBootFailedException::class);
        $this->expectExceptionMessage('modX::getInstance() did not return a kernel instance');

        (new KernelBootstrapper(static fn (): mixed => null))->boot($workspace);
    }

    public function testFailsWhenServiceContainerIsUnavailable(): void
    {
        $modx = TestbenchKernel::instance()->modx();
        $services = new ReflectionProperty($modx, 'services');
        $container = $services->getValue($modx);

        $services->setValue($modx, null);

        try {
            (new KernelBootstrapper())->boot(TestbenchKernel::instance()->workspace());
            self::fail('Expected KernelBootFailedException was not thrown.');
        } catch (KernelBootFailedException $exception) {
            self::assertStringContainsString('the service container is unavailable', $exception->getMessage());
        } finally {
            $services->setValue($modx, $container);
        }
    }

    /**
     * A directory that passes the pre-check for an installed environment but does nothing when
     * included: this is how the failures that occur before `require index.php` are checked.
     */
    private function installedWorkspaceStub(): Workspace
    {
        $workspace = $this->emptyWorkspace();

        mkdir($workspace->path() . '/core/config', 0o775, true);
        mkdir($workspace->corePath() . 'vendor', 0o775, true);
        file_put_contents($workspace->indexFile(), "<?php\n");
        file_put_contents($workspace->configFile(), "<?php\n");
        file_put_contents($workspace->corePath() . 'vendor/autoload.php', "<?php\n");

        return $workspace;
    }

    /**
     * The invariant: a substituted MODX_TESTBENCH_WORKSPACE acts only inside the test and must NEVER
     * reach `TestbenchKernel::instance()` — the singleton lives until the end of the process and
     * would bind the rig to a temporary directory forever. The tests of this class therefore work
     * with `Workspace` directly; if a new test needs the singleton while the variable is
     * substituted, it must call `TestbenchKernel::reset()` both in `tearDown()` and before its work.
     */
    private function emptyWorkspace(): Workspace
    {
        $previous = $_SERVER['MODX_TESTBENCH_WORKSPACE'] ?? null;
        $this->previousWorkspaceDir = is_string($previous) ? $previous : null;

        $this->temporaryWorkspace = sys_get_temp_dir() . '/modx-testbench-boot-' . bin2hex(random_bytes(4));
        $_SERVER['MODX_TESTBENCH_WORKSPACE'] = $this->temporaryWorkspace;

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());
        $workspace->ensureExists();

        return $workspace;
    }
}

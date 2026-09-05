<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

use MODX\Revolution\modX;
use ModxKit\Testbench\Bootstrap\KernelBootstrapper;
use ModxKit\Testbench\Database\DatabaseCleaner;
use ModxKit\Testbench\Database\SchemaInventory;
use ModxKit\Testbench\Database\SnapshotFile;
use ModxKit\Testbench\Database\SnapshotManager;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Installer\HeadlessInstaller;
use ModxKit\Testbench\Installer\TestingDefaults;
use Throwable;

/**
 * The single entry point of the test environment: it prepares the working directory (downloading
 * the core and installing it when needed) and lazily boots the core into the PHPUnit process.
 */
final class TestbenchKernel
{
    private static ?self $instance = null;

    private ?modX $modx = null;

    private ?SnapshotManager $snapshots = null;

    private bool $prepared = false;

    private function __construct(
        private readonly TestbenchConfig $config,
        private readonly Workspace $workspace,
    ) {
    }

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            $config = TestbenchConfig::fromEnvironment();
            self::$instance = new self($config, Workspace::forConfig($config));
        }

        return self::$instance;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function config(): TestbenchConfig
    {
        return $this->config;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function coreVersion(): string
    {
        $lock = $this->workspace->readLock();

        return $lock instanceof LockFile ? $lock->modxVersion : $this->config->version;
    }

    /**
     * Whether preparing the environment managed to finish in this process.
     */
    public function isPrepared(): bool
    {
        return $this->prepared;
    }

    /**
     * Prepares the environment without booting the core. A repeated SUCCESSFUL call within one
     * process is a no-op: otherwise MODX_TESTBENCH_FORCE_INSTALL=1 would reinstall the environment
     * on every access, including after the core has already been loaded into memory. A failed
     * preparation does not count as successful: the next call retries and reports the real cause of
     * the failure.
     */
    public function prepare(): Workspace
    {
        if ($this->prepared) {
            return $this->workspace;
        }

        if (!$this->config->forceInstall
            && $this->workspace->isInstalledWith($this->config->fingerprint())
            && $this->reuseExistingEnvironment()
        ) {
            $this->prepared = true;

            return $this->workspace;
        }

        // The core is fetched into a staging directory FIRST, and the environment that is there
        // now is destroyed only once the replacement is in hand. The order used to be the other
        // way round, and then a broken network, a typo in MODX_TESTBENCH_VERSION or an unreachable
        // MODX_TESTBENCH_CORE_PATH turned a working environment into an empty directory before the
        // only step that could fail had even been attempted — the message said nothing about the
        // loss, and the exit code was indistinguishable from "nothing was touched".
        //
        // "Into a temporary place, then atomically where it belongs" is the package's own standard
        // for irreversible operations — `PhpDumper::PART_SUFFIX`, `MysqlDumper::PART_SUFFIX` and
        // the `rename()` that closes a snapshot capture. Installing an environment is irreversible
        // in exactly the same way.
        // The provider is resolved BEFORE anything is created: an unknown MODX_TESTBENCH_PROVIDER
        // is a configuration error, and a configuration error must not leave a directory behind it.
        $provider = $this->config->coreProvider();
        $staging = $this->workspace->prepareStaging();

        try {
            $staged = $provider->provide($staging->path());
        } catch (Throwable $failure) {
            // A failed delivery leaves nothing behind: a half-filled staging directory would be
            // found non-empty by the next run, and unpacked on top of by the one after it.
            $staging->destroy();

            throw $failure;
        }

        $this->workspace->destroy();

        // `destroy()` does not throw on an incomplete cleanup (no permissions on part of the tree,
        // a file in use) — it merely keeps the ownership marker so that the directory can be
        // finished off by the next call. Installing the core on top of the leftovers is not
        // allowed: the install would go through "successfully" into a directory with somebody
        // else's files. We check explicitly.
        $this->assertWorkspaceIsClearedForInstallation();

        $this->workspace->ensureExists();
        $this->workspace->adoptStagedCore($staging);

        // The provider reported the core under the staging path; after the move the same core is
        // the environment directory itself.
        $core = new CoreLocation($this->workspace->rootPath(), $staged->version);

        $cleaner = new DatabaseCleaner();

        // We create the database OURSELVES. Failing to find it, the MODX installer does not
        // refuse but silently switches all tables to MyISAM — see
        // `DatabaseCleaner::ensureDatabaseExists()`.
        $cleaner->ensureDatabaseExists($this->config->database);

        // We have just recreated the environment directory, but the database survives the
        // recreation: the installer will refuse to install MODX into a database where the table
        // prefix is already taken.
        $cleaner->purgeInstallation($this->config->database);

        (new HeadlessInstaller())->install($core, $this->config);

        $this->assertInstallationIsTransactional();

        // The test environment settings are applied BEFORE the baseline is captured: that way
        // they make it into the snapshot and survive both a restore and `modX::reloadConfig()`.
        (new TestingDefaults())->apply($this->config->database);

        // The snapshot is captured BEFORE the lock is written: a failed capture() then simply
        // leaves no lock, and the next run reinstalls the environment honestly instead of getting
        // stuck forever as "installed without a baseline" (the lock would be written with
        // hasSnapshot: false and would pass the isInstalledWith() above — the reinstall would never
        // happen).
        $this->snapshotManager()->capture();

        $this->workspace->writeLock(new LockFile(
            fingerprint: $this->config->fingerprint(),
            modxVersion: $core->version,
            provider: $this->config->provider,
            tablePrefix: $this->config->database->prefix,
            installedAt: gmdate('c'),
            hasSnapshot: true,
            tableCount: SchemaInventory::countTablesWithPrefix($this->config->database),
            snapshotFormat: $this->snapshotManager()->format(),
        ));

        $this->prepared = true;

        return $this->workspace;
    }

    /**
     * Returns the snapshot manager, preparing the environment when needed. The public entry point
     * for `ModxKit\Testbench\Concerns\RefreshesDatabase` — it uses the instance shared with
     * `prepare()` so as not to spawn a second `mysqldump` availability check process.
     */
    public function snapshots(): SnapshotManager
    {
        $this->prepare();

        return $this->snapshotManager();
    }

    /**
     * Makes sure the environment directory is empty (or absent) before the install.
     */
    private function assertWorkspaceIsClearedForInstallation(): void
    {
        if (!$this->workspace->hasLeftovers()) {
            return;
        }

        throw new TestbenchException(sprintf(
            "Workspace directory \"%s\" could not be cleared completely, installation cancelled.\n"
            . 'Files from the previous environment are left in it — usually this is missing '
            . 'permissions on part of the tree or a file in use. The directory is marked as '
            . 'belonging to the package, so a repeated run will try to finish removing it; if that '
            . 'does not help, delete the directory by hand.',
            $this->workspace->path()
        ));
    }

    /**
     * Prepares an already installed environment for reuse. The order of the steps here is not a
     * detail but the essence.
     *
     * First the INTEGRITY of the database is established — by comparing against the table count
     * recorded by the install, and by repairing from an EXISTING snapshot. And only on a database
     * whose integrity is confirmed is capturing a missing baseline allowed. The reverse order would
     * mean recapturing the snapshot from a corrupted database: the corruption is cemented into the
     * new baseline and into the lock, while the only serviceable snapshot is destroyed
     * irreversibly — the integrity gate turns into its own opposite.
     *
     * `false` means the environment is unusable and must be reinstalled in full.
     */
    private function reuseExistingEnvironment(): bool
    {
        $lock = $this->workspace->readLock();

        if (!$lock instanceof LockFile) {
            return false;
        }

        if (!$this->databaseMatchesLock($lock)) {
            return false;
        }

        if ($lock->hasSnapshot && SnapshotFile::isComplete($this->workspace->snapshotPath())) {
            return true;
        }

        // The snapshot has vanished from disk or was not written to the end, while the database is
        // known to be intact. The test install settings are applied BEFORE the capture: otherwise
        // the recapture would freeze into the baseline the very state the install corrects.
        (new TestingDefaults())->apply($this->config->database);
        $this->snapshotManager()->capture();
        $this->workspace->writeLock($lock->withSnapshot($this->snapshotManager()->format()));

        return true;
    }

    /**
     * The count of tables with the prefix against the one recorded by the install.
     *
     * A discrepancy is either a lost table or a superfluous one: either way it means the tests will
     * run against a different environment from the one the install created. We repair with a cheap
     * load of the existing baseline; if the count still does not match afterwards, the environment
     * is beyond repair and the caller reinstalls it in full.
     */
    private function databaseMatchesLock(LockFile $lock): bool
    {
        $actual = SchemaInventory::countTablesWithPrefix($this->config->database);

        if ($actual === $lock->tableCount) {
            return true;
        }

        if (!$lock->hasSnapshot || !SnapshotFile::isComplete($this->workspace->snapshotPath())) {
            // There is nothing to repair with. Recapturing now is out of the question: a snapshot
            // of a corrupted database would become the new "reference" and the corruption would
            // stay forever.
            return false;
        }

        try {
            $this->snapshotManager()->restore();
        } catch (TestbenchException) {
            // An unusable baseline is no reason to stop the preparation: the environment will be
            // reinstalled in full, and the real cause of the failure will surface there.
            return false;
        }

        return SchemaInventory::countTablesWithPrefix($this->config->database) === $lock->tableCount;
    }

    /**
     * The second line of defence: even having created the database in advance, we are obliged to
     * make sure the install really produced tables with rollback support. The installer switches to
     * MyISAM silently — the only way to know for certain is to look at the result.
     */
    private function assertInstallationIsTransactional(): void
    {
        $tables = SchemaInventory::nonTransactionalTables($this->config->database);

        if ($tables === []) {
            return;
        }

        throw new TestbenchException(sprintf(
            'The MODX installation finished, but tables %s were created by an engine with no '
            . "rollback support (MyISAM), and transaction isolation of tests will not work.\n"
            . 'That happens when the CLI setup did not find the target database and silently turned '
            . 'on OPT_OVERRIDE_TABLE_TYPE = MyISAM. Check that user "%s" has the CREATE privilege '
            . 'on database "%s", and install again (MODX_TESTBENCH_FORCE_INSTALL=1).',
            implode(', ', array_slice($tables, 0, 5)),
            $this->config->database->user,
            $this->config->database->name
        ));
    }

    /**
     * One `SnapshotManager` instance per kernel: through `pickDumper()` the constructor spawns a
     * process checking the availability of `mysqldump`, and `prepare()`/`snapshots()` must not pay
     * for it twice.
     *
     * The snapshot format is taken from the lock: the baseline must be read by the same strategy
     * that captured it. If there is no lock, the environment is being installed right now, and the
     * strategy is chosen by availability.
     */
    private function snapshotManager(): SnapshotManager
    {
        return $this->snapshots ??= new SnapshotManager(
            $this->config->database,
            $this->workspace->snapshotPath(),
            recordedFormat: $this->recordedSnapshotFormat()
        );
    }

    /**
     * The format holds exactly as long as that very snapshot lies on disk.
     *
     * A baseline that has vanished or was not written to the end means there is nothing to read and
     * a RECAPTURE is due — and the format for it must not be taken from the old lock: on a machine
     * without clients the preparation would refuse with the words "the baseline was captured by the
     * mysqldump client" even though there is no baseline at all, and a sound environment with a
     * sound database would go into a full reinstall instead of a cheap repair.
     */
    private function recordedSnapshotFormat(): string
    {
        if (!SnapshotFile::isComplete($this->workspace->snapshotPath())) {
            return '';
        }

        // `??` already suppresses reading a property with `null` on the left, so `?->` would be
        // redundant here (PHPStan, nullsafe.neverNull) — the same trick as in `StatusCommand`.
        return $this->workspace->readLock()->snapshotFormat ?? '';
    }

    public function modx(): modX
    {
        if (!$this->modx instanceof modX) {
            $this->modx = (new KernelBootstrapper())->boot($this->prepare());
        }

        return $this->modx;
    }
}

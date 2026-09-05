<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Environment;

use ModxKit\Testbench\Database\MysqlDumper;
use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Database\SnapshotFile;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\LockFile;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Tests\Support\ClientPathControl;
use ModxKit\Testbench\Tests\Support\RestoresServerVariables;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use ModxKit\Testbench\Tests\Support\RunsDeferredCleanups;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('integration')]
final class TestbenchKernelTest extends TestCase
{
    use ClientPathControl;
    use RestoresServerVariables;
    use RunsDeferredCleanups;

    /**
     * A separate database, so that a forced reinstall does not touch the environment of the other
     * tests. The name is derived from the run (the environment fingerprint plus the pid) rather than
     * hard-coded — otherwise two runs against one DBMS server wiped out each other's databases in
     * the middle of a foreign test (found live by three reviewers). The compromise of the scheme and
     * its limitations are in {@see RunScopedDatabaseName}.
     */
    private string $dbName;

    private ?string $temporaryWorkspace = null;

    protected function setUp(): void
    {
        $this->dbName = RunScopedDatabaseName::forBase('modx_testbench_kernel_test');
    }

    /**
     * Every step runs inside its own `try` ({@see RunsDeferredCleanups}), and what any of them
     * threw is raised at the very end. Written as a straight sequence — the shape this method
     * carried, without a `try`/`finally` anywhere in it — a throw above skipped everything below.
     * Measured with one throwing cleanup on the queue: the straight sequence did not reach
     * `TestbenchKernel::reset()`, and the next test got the singleton of the failed one back,
     * checked by object identity; `MODX_TESTBENCH_WORKSPACE` leaked into it as well.
     */
    protected function tearDown(): void
    {
        $this->runCleanupStep($this->removeBinDirectories(...));

        $this->runCleanupStep(function (): void {
            if ($this->temporaryWorkspace === null) {
                return;
            }

            exec('rm -rf ' . escapeshellarg($this->temporaryWorkspace));
            // The staging directory of an interrupted install is a SIBLING of the workspace, so
            // `rm -rf` on the workspace alone would leave it behind for the next run to inherit.
            exec('rm -rf ' . escapeshellarg($this->temporaryWorkspace . '.new'));
            $this->temporaryWorkspace = null;
        });

        $this->runCleanupStep($this->dropTestDatabase(...));

        $this->runDeferredCleanups();

        // The singleton outlives test boundaries, so it is returned to the environment's settings.
        $this->runCleanupStep(TestbenchKernel::reset(...));

        $this->reportCleanupFailures();
    }

    public function testPreparedEnvironmentIsReusedWithoutReinstalling(): void
    {
        $kernel = TestbenchKernel::instance();
        $workspace = $kernel->prepare();
        $first = $workspace->readLock();

        self::assertInstanceOf(LockFile::class, $first);
        self::assertTrue($workspace->isInstalledWith($kernel->config()->fingerprint()));

        self::assertTrue($kernel->isPrepared());

        $second = $kernel->prepare()->readLock();

        self::assertInstanceOf(LockFile::class, $second);
        self::assertSame($first->installedAt, $second->installedAt);
        self::assertSame($workspace->path(), $kernel->workspace()->path());
        self::assertSame($first->modxVersion, $kernel->coreVersion());
    }

    public function testForceInstallRecreatesEnvironmentOncePerProcess(): void
    {
        $this->useEnvironment([
            'MODX_TESTBENCH_WORKSPACE' => $this->temporaryWorkspacePath(),
            'MODX_TESTBENCH_DB_NAME' => $this->dbName,
            'MODX_TESTBENCH_FORCE_INSTALL' => '1',
        ]);

        $workspace = TestbenchKernel::instance()->prepare();
        $lock = $workspace->readLock();

        self::assertInstanceOf(LockFile::class, $lock);
        self::assertFileExists($workspace->configFile());

        // Branch 1 (a full install): the baseline is captured BEFORE the lock is written, so the lock
        // must carry hasSnapshot: true right away, and the snapshot file must exist on disk. This pair
        // of assertions is provable by mutation on the side effect itself: remove the capture() call in
        // prepare() (or set hasSnapshot back to false) and both assertions fail. That the order is
        // specifically capture() → writeLock() (and not the reverse), which keeps a failed capture()
        // from leaving an orphaned lock, is not what this test checks: short of an injection point for
        // Dumper (deliberately not added), there is nothing here to reproduce a real capture() failure with.
        self::assertTrue($lock->hasSnapshot);
        self::assertFileExists($workspace->snapshotPath());

        // A fresh install must write the number of prefixed tables into the lock — without it there
        // will be nothing to check the integrity of the database against.
        self::assertGreaterThan(60, $lock->tableCount);
        self::assertSame($this->countPrefixedTables(), $lock->tableCount);

        // The install must record WHAT the baseline was captured with — otherwise the next process
        // picks the strategy by PATH and risks handing the snapshot to a foreign one.
        $this->assertRecordedFormatMatchesTheSnapshotOnDisk($lock, $workspace->snapshotPath());

        $marker = $workspace->path() . '/testbench-marker.txt';
        file_put_contents($marker, 'kept');

        // A repeated call in the same process does not reinstall the environment: otherwise the install
        // would repeat on every access to the core, including after it has been loaded into memory.
        TestbenchKernel::instance()->prepare();

        self::assertFileExists($marker);
        self::assertSame($lock->installedAt, $workspace->readLock()?->installedAt);

        // A new process (here, a reset singleton) with MODX_TESTBENCH_FORCE_INSTALL=1 recreates the
        // environment from scratch.
        TestbenchKernel::reset();
        TestbenchKernel::instance()->prepare();

        self::assertFileDoesNotExist($marker);
        self::assertNotSame($lock->installedAt, $workspace->readLock()->installedAt);

        // Branch 2 (an early return): a working directory installed by an earlier revision carries a
        // lock with hasSnapshot: false and has no snapshot file — here that is reproduced by hand on
        // top of an already installed environment. prepare() without FORCE_INSTALL goes into the
        // isInstalledWith() branch (the fingerprint and the core files match) and must repair there,
        // not only on a fresh install.
        // Without an explicit assertInstanceOf: PHPStan has already narrowed readLock() to non-null
        // right here, as it has in the unchecked access $workspace->readLock()?->installedAt just
        // above (line 110, the existing style of the file) — a repeated check would be provably
        // redundant for it (staticMethod.alreadyNarrowedType).
        $installedLock = $workspace->readLock();

        $workspace->writeLock(LockFile::fromArray([
            'fingerprint' => $installedLock->fingerprint,
            'modx_version' => $installedLock->modxVersion,
            'provider' => $installedLock->provider,
            'table_prefix' => $installedLock->tablePrefix,
            'installed_at' => $installedLock->installedAt,
            'has_snapshot' => false,
            // The revision and the table count are preserved: what is checked is the branch "the snapshot
            // went missing in an environment of the CURRENT revision" rather than a migration from an old
            // one — that has a separate test below, and its answer is the opposite (a reinstall).
            'table_count' => $installedLock->tableCount,
            'install_revision' => LockFile::CURRENT_REVISION,
        ]));
        unlink($workspace->snapshotPath());

        // A re-capture must apply the settings of the test install BEFORE capturing. Otherwise a value
        // that has drifted in the database freezes into the new baseline and starts surviving
        // RefreshesDatabase — that is, there is nothing left to repair it with.
        $this->connection()->exec(
            "UPDATE `modx_system_settings` SET value = '1' WHERE `key` = 'log_deprecated'"
        );

        $_SERVER['MODX_TESTBENCH_FORCE_INSTALL'] = '0';
        TestbenchKernel::reset();
        TestbenchKernel::instance()->prepare();

        $repairedLock = $workspace->readLock();
        self::assertInstanceOf(LockFile::class, $repairedLock);
        self::assertTrue($repairedLock->hasSnapshot);
        self::assertFileExists($workspace->snapshotPath());
        // The lock supplied above carried no format (`snapshot_format` is not in it at all) — the
        // re-capture must record it together with the snapshot flag.
        $this->assertRecordedFormatMatchesTheSnapshotOnDisk($repairedLock, $workspace->snapshotPath());
        self::assertSame('0', $this->settingValue('log_deprecated'));
        self::assertStringContainsString(
            "'log_deprecated','0'",
            (string) file_get_contents($workspace->snapshotPath())
        );
        // The install did not repeat — only the baseline was repaired, not the whole environment.
        self::assertSame($installedLock->installedAt, $repairedLock->installedAt);

        // The third guard. The lock says "there is a snapshot", the file is in place and non-empty, but
        // was truncated at a statement boundary by an interrupted capture(). The earlier invariant
        // (`filesize() > 0`) considered it sound, and the first RefreshesDatabase destroyed the database silently.
        $complete = (string) file_get_contents($workspace->snapshotPath());
        $boundary = strpos($complete, ";\n");
        self::assertIsInt($boundary);
        file_put_contents($workspace->snapshotPath(), substr($complete, 0, $boundary + 2));

        TestbenchKernel::reset();
        TestbenchKernel::instance()->prepare();

        self::assertTrue(
            SnapshotFile::isComplete($workspace->snapshotPath()),
            'A truncated baseline was not re-captured — prepare() considered it sound.'
        );
        // And again: the baseline is repaired, not the whole environment.
        self::assertSame($installedLock->installedAt, $workspace->readLock()?->installedAt);

        // The integrity of the database was never checked — a dropped core table left the environment
        // "installed", the core loaded, one or two tests out of a hundred failed, and the diagnostics
        // led away from the cause. The lock knows how many prefixed tables there were at install time;
        // a divergence must be repaired rather than ignored.
        $expectedTables = $workspace->readLock()->tableCount;
        self::assertIsInt($expectedTables);
        self::assertGreaterThan(60, $expectedTables, 'The lock did not remember the table count of the install.');

        $baselineBefore = (string) file_get_contents($workspace->snapshotPath());

        $this->connection()->exec('DROP TABLE `modx_site_content`');
        self::assertSame($expectedTables - 1, $this->countPrefixedTables());

        TestbenchKernel::reset();
        TestbenchKernel::instance()->prepare();

        self::assertSame(
            $expectedTables,
            $this->countPrefixedTables(),
            'A dropped core table went unnoticed — the environment stayed "installed".'
        );
        // The repair comes from the baseline: there was no reinstall.
        self::assertSame($installedLock->installedAt, $workspace->readLock()->installedAt);

        // The repair must come FROM the existing snapshot, re-capturing nothing. A re-capture against a
        // damaged database would cement the damage and destroy the only sound baseline —
        // irreversibly.
        self::assertStringEqualsFile(
            $workspace->snapshotPath(),
            $baselineBefore,
            'The baseline was re-captured against a damaged database — there is nothing left to repair with.'
        );

        // The same defect, but with nothing left to repair the baseline with: an honest reinstall remains.
        $this->connection()->exec('DROP TABLE `modx_site_content`');
        file_put_contents($workspace->snapshotPath(), "SET FOREIGN_KEY_CHECKS=0;\n");

        TestbenchKernel::reset();
        TestbenchKernel::instance()->prepare();

        self::assertSame($expectedTables, $this->countPrefixedTables());
        self::assertNotSame(
            $installedLock->installedAt,
            $workspace->readLock()->installedAt,
            'An unrepairable database must lead to a reinstall rather than to "installed".'
        );

        // The directory is alive and of the CURRENT revision, the lock is intact, while the snapshot is
        // gone and the database is wiped — that happens when the test database has been recreated or
        // MODX_TESTBENCH_DB_NAME points at a new database while the directory survives. Here the order
        // "integrity first, re-capture second" makes a difference in BEHAVIOUR: with the reverse order
        // the preparation tries to apply the settings and capture a baseline against an empty database
        // and fails on "Table '…modx_system_settings' doesn't exist", leaving 0 tables.
        $beforeWipe = $workspace->readLock()->installedAt;
        unlink($workspace->snapshotPath());
        $this->dropPrefixedTables();
        self::assertSame(0, $this->countPrefixedTables());

        TestbenchKernel::reset();
        TestbenchKernel::instance()->prepare();

        self::assertGreaterThan(60, $this->countPrefixedTables());
        self::assertTrue(SnapshotFile::isComplete($workspace->snapshotPath()));
        self::assertNotSame(
            $beforeWipe,
            $workspace->readLock()->installedAt,
            'A wiped database must lead to an honest reinstall.'
        );

        // The database is intact, the lock says "the snapshot was captured by mysqldump", and the
        // snapshot itself is not on disk. The recorded format carries no weight in this branch — there
        // is nothing to read — and re-capturing with an available strategy is exactly what is expected
        // of the preparation. Otherwise a whole environment went into a full reinstall, while the
        // failure message described a state the environment is not in ("the baseline was captured by the mysqldump client").
        $beforeRecapture = $workspace->readLock()->installedAt;
        $lockData = $workspace->readLock()->toArray();
        $lockData['snapshot_format'] = MysqlDumper::FORMAT;
        $workspace->writeLock(LockFile::fromArray($lockData));
        unlink($workspace->snapshotPath());

        TestbenchKernel::reset();

        // A PATH without a single MySQL client: were it not for the repair, the SnapshotManager
        // constructor would refuse here rather than re-capture the baseline.
        $this->withPath($this->binDirectoryWith([]), static function (): void {
            TestbenchKernel::instance()->prepare();
        });

        $recapturedLock = $workspace->readLock();
        self::assertInstanceOf(LockFile::class, $recapturedLock);

        self::assertTrue(SnapshotFile::isComplete($workspace->snapshotPath()));
        self::assertSame(PhpDumper::FORMAT, $recapturedLock->snapshotFormat);
        self::assertSame(
            $beforeRecapture,
            $recapturedLock->installedAt,
            'A missing baseline is repaired by a re-capture rather than by reinstalling the whole environment.'
        );
    }

    private function dropPrefixedTables(): void
    {
        $database = DatabaseConfig::fromEnvironment();
        $connection = $this->connection();
        $statement = $connection->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ?"
        );
        $statement->execute([$database->name, $database->prefix . '%']);

        $tables = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (is_string($table)) {
                $tables[] = '`' . str_replace('`', '``', $table) . '`';
            }
        }

        if ($tables === []) {
            return;
        }

        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        $connection->exec('DROP TABLE IF EXISTS ' . implode(', ', $tables));
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * A workspace deployed by the PREVIOUS revision of the package.
     *
     * The shape of such a directory was taken from a real install at commit `66738ee`: a lock without
     * `table_count` and without `install_revision`, a baseline without the completion marker, and
     * `log_deprecated = 1` both in the database and inside the snapshot. There is nothing to migrate
     * that "in place" with: there is nothing to check the integrity against (nobody recorded the
     * table counts), and it cannot be restored from the snapshot — that one does not pass the
     * completeness check. The only honest answer is a reinstall; the install revision in the lock
     * makes it automatic, exactly as FR-ENV-4 requires.
     *
     * Before this fix the opposite happened: the baseline was re-captured BEFORE the integrity check,
     * that is, against a damaged database, the damage was cemented into the lock, and
     * `log_deprecated = 1` froze into the new snapshot and started surviving `RefreshesDatabase`.
     */
    public function testWorkspaceFromAnEarlierInstallRevisionIsReinstalledInsteadOfMigrated(): void
    {
        $this->useEnvironment([
            'MODX_TESTBENCH_WORKSPACE' => $this->temporaryWorkspacePath(),
            'MODX_TESTBENCH_DB_NAME' => $this->dbName,
        ]);

        $workspace = TestbenchKernel::instance()->prepare();
        $lock = $workspace->readLock();
        self::assertInstanceOf(LockFile::class, $lock);

        // Bring the environment to the shape of the previous revision.
        $workspace->writeLock(LockFile::fromArray([
            'fingerprint' => $lock->fingerprint,
            'modx_version' => $lock->modxVersion,
            'provider' => $lock->provider,
            'table_prefix' => $lock->tablePrefix,
            'installed_at' => $lock->installedAt,
            'has_snapshot' => true,
        ]));
        $baseline = (string) file_get_contents($workspace->snapshotPath());
        file_put_contents(
            $workspace->snapshotPath(),
            (string) preg_replace('/^-- testbench:complete tables=\d+\R?$/m', '', $baseline)
        );
        $this->connection()->exec(
            "UPDATE `modx_system_settings` SET value = '1' WHERE `key` = 'log_deprecated'"
        );
        $this->connection()->exec('DROP TABLE `modx_site_content`');

        self::assertSame(0, $workspace->readLock()?->installRevision);
        self::assertFalse(SnapshotFile::isComplete($workspace->snapshotPath()));

        TestbenchKernel::reset();
        TestbenchKernel::instance()->prepare();

        // Without assertInstanceOf/assertNotNull: PHPStan has already narrowed readLock() to non-null
        // above (staticMethod.alreadyNarrowedType), as it has everywhere else in this file.
        $migrated = $workspace->readLock();

        // A reinstall rather than a migration in place.
        self::assertNotSame($lock->installedAt, $migrated->installedAt);
        self::assertSame(LockFile::CURRENT_REVISION, $migrated->installRevision);
        self::assertGreaterThan(60, $migrated->tableCount);
        self::assertSame($migrated->tableCount, $this->countPrefixedTables());

        // The dropped table came back rather than being cemented into a new snapshot.
        self::assertTrue(SnapshotFile::isComplete($workspace->snapshotPath()));
        self::assertStringContainsString(
            'CREATE TABLE `modx_site_content`',
            (string) file_get_contents($workspace->snapshotPath())
        );

        // The settings of the test install were applied and made it into the snapshot.
        self::assertSame('0', $this->settingValue('log_deprecated'));
        self::assertStringContainsString(
            "'log_deprecated','0'",
            (string) file_get_contents($workspace->snapshotPath())
        );
    }

    private function settingValue(string $key): string
    {
        $statement = $this->connection()->prepare(
            'SELECT value FROM `modx_system_settings` WHERE `key` = ?'
        );
        $statement->execute([$key]);

        return (string) $statement->fetchColumn();
    }

    private function connection(): PDO
    {
        $database = DatabaseConfig::fromEnvironment();

        return new PDO(
            $database->dsn(),
            $database->user,
            $database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function countPrefixedTables(): int
    {
        $database = DatabaseConfig::fromEnvironment();
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ?"
        );
        $statement->execute([$database->name, $database->prefix . '%']);

        return (int) $statement->fetchColumn();
    }

    /**
     * A failed preparation must not be remembered as a successful one: otherwise the next call would
     * return an uninstalled environment as ready, and the real cause of the failure would be lost
     * (in the CLI, `prepare()` is called directly and the exceptions are caught).
     */
    public function testFailedPreparationIsRetriedAndKeepsReportingTheRealCause(): void
    {
        $this->useEnvironment([
            'MODX_TESTBENCH_WORKSPACE' => $this->temporaryWorkspacePath(),
            'MODX_TESTBENCH_PROVIDER' => 'ftp',
        ]);

        $kernel = TestbenchKernel::instance();

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $kernel->prepare();
                self::fail("Attempt {$attempt}: a TestbenchException was expected.");
            } catch (TestbenchException $exception) {
                self::assertStringContainsString('zip, git, local', $exception->getMessage());
            }

            self::assertFalse($kernel->isPrepared());
        }
    }

    /**
     * A provider that cannot deliver must not cost the consumer the environment they already had.
     *
     * The order used to be `destroy()` first and `provide()` afterwards, so a broken network, a
     * typo in `MODX_TESTBENCH_VERSION` or an unreachable `MODX_TESTBENCH_CORE_PATH` turned a
     * working environment into an empty directory BEFORE the only step that could fail was even
     * attempted — and the error message said nothing about it, while `EXIT=1` looks exactly like
     * "nothing was touched". Measured on a directory holding `index.php`,
     * `core/config/config.inc.php` and an unrelated file: after the refusal only the ownership
     * marker was left.
     *
     * The fixture below is not an installed environment (there is no lock, and installing one here
     * would mean a real download): it is a directory the package OWNS and would therefore wipe.
     * That is the whole of what the old order needed to destroy it.
     */
    public function testAFailingProviderLeavesTheExistingEnvironmentInPlace(): void
    {
        $workspace = $this->temporaryWorkspacePath();

        self::assertTrue(mkdir($workspace . '/core/config', 0o700, true));
        self::assertNotFalse(file_put_contents(
            $workspace . '/.testbench-workspace',
            "This directory was created by modx-testbench and will be deleted in full when the environment is reinstalled.\n"
        ));
        self::assertNotFalse(file_put_contents($workspace . '/index.php', "<?php // gateway\n"));
        self::assertNotFalse(file_put_contents($workspace . '/core/config/config.inc.php', "<?php // config\n"));

        $this->useEnvironment([
            'MODX_TESTBENCH_WORKSPACE' => $workspace,
            'MODX_TESTBENCH_PROVIDER' => 'local',
            'MODX_TESTBENCH_CORE_PATH' => $workspace . '-there-is-no-such-distribution',
        ]);

        try {
            TestbenchKernel::instance()->prepare();
            self::fail('A provider pointed at a missing directory was expected to refuse.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('does not exist', $exception->getMessage());
        }

        self::assertFileExists(
            $workspace . '/index.php',
            'The environment was destroyed before the replacement was known to be reachable.'
        );
        self::assertFileExists($workspace . '/core/config/config.inc.php');

        // A refusal must not leave the half-filled staging directory behind either: the next run
        // would find it non-empty, and the run after that would be extracting on top of it.
        self::assertDirectoryDoesNotExist($workspace . '.new');
    }

    public function testUnknownProviderIsReportedWithAllowedValues(): void
    {
        $this->useEnvironment([
            'MODX_TESTBENCH_WORKSPACE' => $this->temporaryWorkspacePath(),
            'MODX_TESTBENCH_PROVIDER' => 'ftp',
        ]);

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('zip, git, local');

        TestbenchKernel::instance()->prepare();
    }

    public function testLocalProviderWithoutCorePathIsReportedWithNextStep(): void
    {
        $this->useEnvironment([
            'MODX_TESTBENCH_WORKSPACE' => $this->temporaryWorkspacePath(),
            'MODX_TESTBENCH_PROVIDER' => 'local',
            // An empty string is more reliable than unset(): Env reads getenv() as well, where the variable
            // may have survived from a developer's environment, and an empty value is treated as "not set".
            'MODX_TESTBENCH_CORE_PATH' => '',
        ]);

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('MODX_TESTBENCH_CORE_PATH');

        TestbenchKernel::instance()->prepare();
    }

    /**
     * The restoration goes on the queue of {@see RunsDeferredCleanups}, which drains LIFO: a
     * repeated substitution of the same key therefore returns it to the value it had BEFORE the
     * first substitution rather than before the last.
     *
     * @param array<string, string> $variables
     */
    private function useEnvironment(array $variables): void
    {
        $this->deferCleanup($this->serverVariableRestorer(array_keys($variables)));

        foreach ($variables as $key => $value) {
            $_SERVER[$key] = $value;
        }

        TestbenchKernel::reset();
    }

    private function temporaryWorkspacePath(): string
    {
        return $this->temporaryWorkspace = sys_get_temp_dir() . '/modx-testbench-kernel-' . bin2hex(random_bytes(4));
    }

    /**
     * The format in the lock is compared not against itself but against the FILE: the client dumper
     * opens a dump with its own header, {@see PhpDumper} with the first statement. The check would
     * otherwise degenerate into the tautology "we wrote what we wrote".
     *
     * There are two headers, and the second is not a trifle: Oracle's `mysqldump` writes "MySQL
     * dump", while `mariadb-dump` writes "MariaDB dump" (measured, see
     * {@see MysqlDumperMariadbClientTest}). Knowing only the first, the check took a MariaDB client's
     * snapshot for a `php` strategy snapshot and went red in the job where PATH carries only the
     * `mariadb*` names — that is, it caught the vendor of the client rather than a divergence between
     * the lock and the file.
     */
    private function assertRecordedFormatMatchesTheSnapshotOnDisk(LockFile $lock, string $snapshotPath): void
    {
        $head = (string) file_get_contents($snapshotPath, false, null, 0, 64);
        $takenByClient = str_contains($head, 'MySQL dump') || str_contains($head, 'MariaDB dump');

        self::assertSame(
            $takenByClient ? MysqlDumper::FORMAT : PhpDumper::FORMAT,
            $lock->snapshotFormat
        );
    }

    private function dropTestDatabase(): void
    {
        $database = DatabaseConfig::fromEnvironment();

        if ($database->name !== $this->dbName) {
            return;
        }

        try {
            $pdo = new PDO($database->dsnWithoutDatabase(), $database->user, $database->password);
            $pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        } catch (Throwable) {
            // Cleanup "where possible": an unreachable DBMS or a database that was never created must not
            // turn the cleanup into a failing test.
        }
    }
}

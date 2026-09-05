<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Console;

use FilesystemIterator;
use ModxKit\Testbench\Console\DestroyCommand;
use ModxKit\Testbench\Console\InstallCommand;
use ModxKit\Testbench\Console\SnapshotCommand;
use ModxKit\Testbench\Console\StatusCommand;
use ModxKit\Testbench\Database\SchemaInventory;
use ModxKit\Testbench\Database\SnapshotFile;
use ModxKit\Testbench\Environment\LockFile;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Environment\Workspace;
use ModxKit\Testbench\Tests\Support\CapturesWarnings;
use ModxKit\Testbench\Tests\Support\RestoresServerVariables;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use ModxKit\Testbench\Tests\Support\RunsDeferredCleanups;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Covers the four `bin/modx-testbench` commands.
 *
 * `install --force`, `destroy` and `snapshot capture` irreversibly overwrite the working directory
 * and the baseline file, so they must not be let anywhere near the SHARED environment every other
 * level 2 test uses (state leaking between tests). Each such test switches the `TestbenchKernel`
 * singleton to a THROWAWAY directory (and, where it comes to writing to the database, to a separate
 * database name) through {@see self::useIsolatedKernel()} /
 * {@see self::useFakeInstalledWorkspace()} and restores both the environment variables and the
 * singleton in `tearDown()` — guaranteed, even if the test itself failed, after the model of
 * `deferCleanup()` in `tests/Integration/Package/TransportInstallerTest.php`.
 *
 * The isolation is established SPECIFICALLY through `MODX_TESTBENCH_WORKSPACE` (and
 * `MODX_TESTBENCH_DB_NAME` where we write to the database). Overriding `MODX_TESTBENCH_DB_PASS`
 * alone is NOT ENOUGH — see the docblock of {@see self::useFakeInstalledWorkspace()}.
 *
 * The names of the service databases here are computed by {@see RunScopedDatabaseName}, as in every
 * other class — four hard-coded names used to be an exception to that scheme, which the README
 * describes under "The bookkeeping databases of the package's own suite". Two of them
 * (`status_tables`, `console_e2e`) reach `DROP DATABASE`, that is, exactly the finding the scheme
 * was introduced for. The other two (`foreign_guard`, `leftovers_guard`) must never get as far as
 * creating a database — the install is supposed to fail earlier, on the directory guard. "Must
 * never" is not the same as "does not": on the development server of this branch the database
 * `modx_testbench_leftovers_guard` WAS FOUND, with 70 MODX core tables in it (measured), meaning an
 * install once did get that far — most likely in a mutation run with the guard removed. After that
 * there is nothing to be said for keeping a hard-coded name.
 *
 * The `install`/`destroy`/`snapshot` commands do not call `TestbenchKernel::modx()` (verified
 * against their sources — they touch only `prepare()`/`workspace()`/`snapshots()`), so switching the
 * singleton to another directory is safe here: `KernelBootstrapper::assertSingleCorePerProcess()`
 * (the guard against loading two copies of the core into one process, see
 * `src/Bootstrap/KernelBootstrapper.php`) never fires, and the core is never loaded into memory.
 */
#[Group('integration')]
final class CommandsTest extends TestCase
{
    use CapturesWarnings;
    use RestoresServerVariables;
    use RunsDeferredCleanups;

    /**
     * The marker for the contents of the snapshot stub file that
     * {@see self::useFakeInstalledWorkspace()} writes: a non-empty file is needed so that
     * `prepare()` does not go off capturing a baseline, and the marker itself makes the fact of its
     * being overwritten by a real dump checkable.
     */
    private const PLACEHOLDER_SNAPSHOT_MARKER = '-- modx-testbench placeholder snapshot';

    protected function tearDown(): void
    {
        $this->runDeferredCleanups();

        // `parent::tearDown()` is isolated like every other step. Here that IS uniformity with
        // `TransportInstallerTest`, and measured as such: this class extends
        // `PHPUnit\Framework\TestCase`, whose `tearDown()` has an empty body
        // (`vendor/phpunit/phpunit/src/Framework/TestCase.php:302-304` on PHPUnit 12.5.33), so
        // today it cannot throw. The measured reason for the shape — a
        // `try { runDeferredCleanups(); } finally { parent::tearDown(); }` that guarded nothing
        // and lost the queue's own failures whenever the parent threw — was taken on
        // `TransportInstallerTest`, whose parent can; it is in the docblock of
        // `RunsDeferredCleanups`.
        $this->runCleanupStep(parent::tearDown(...));

        $this->reportCleanupFailures();
    }

    /**
     * With an empty password `assertStringNotContainsString('', $output)` is always true (an empty
     * needle is contained in any string) — the check degenerates. The password is therefore set
     * deterministically rather than taken from the caller's environment variable.
     *
     * `assertStringContainsString('installed', $output)` also passes when `StatusCommand` printed
     * "not installed" — "installed" is a suffix of its own negation
     * (`src/Console/StatusCommand.php:32`). The states are told apart explicitly: the substring is
     * there, and "not installed" is not.
     */
    public function testStatusReportsPreparedEnvironmentAndMasksPassword(): void
    {
        $this->useFakeInstalledWorkspace('status-masked', ['MODX_TESTBENCH_DB_PASS' => 'CommandsTest-Db-Password-42']);

        $tester = new CommandTester(new StatusCommand());
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        $this->assertOutputContainsPath(TestbenchKernel::instance()->workspace()->path(), $output);
        self::assertStringContainsString('installed', $output);
        self::assertStringNotContainsString('not installed', $output);
        self::assertStringNotContainsString(TestbenchKernel::instance()->config()->database->password, $output);
        self::assertStringContainsString('***', $output);
    }

    /**
     * The other side: the test above catches the mutation "always print «not installed»", but before
     * this test the suite had NOT ONE state of "not installed" — the mutation "always print
     * «installed»" (`StatusCommand.php:32` replaced by a constant) would pass the whole suite (unit
     * and integration) without a single red test. A throwaway directory with no lock is exactly the
     * state in which `isInstalledWith()` must return `false`.
     */
    public function testStatusReportsUnpreparedEnvironment(): void
    {
        $this->makeThrowawayWorkspaceDirectory('status-unprepared');

        $tester = new CommandTester(new StatusCommand());
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('not installed', $output);
    }

    /**
     * `status` must name the snapshot strategy. The consumer's main path is `vendor/bin/phpunit`,
     * where the environment is prepared by the bootstrap and nobody calls `install`; a silent
     * fallback to `PhpDumper` there was named by nobody until this line.
     *
     * The `php` branch is built with a throwaway environment carrying a recorded format — it is
     * reachable on any machine, both where the clients are present (CI) and where they are not.
     */
    public function testStatusNamesTheSnapshotStrategyIncludingTheFallbackToPhp(): void
    {
        $this->useFakeInstalledWorkspace('status-strategy', snapshotFormat: 'php');

        $tester = new CommandTester(new StatusCommand());
        $tester->execute([]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertStringContainsString('Snapshot strategy', $output);
        self::assertStringContainsString('views and triggers did NOT make it into the snapshot', $output);
    }

    /**
     * The mutation "StatusCommand always prints ***" (removing the `password === '' ? ...` ternary)
     * would break nothing in the test above — the password there is non-empty. This test catches
     * exactly that one: with an empty password the output must contain "empty" rather than "***".
     */
    public function testStatusShowsPlainMarkerWhenPasswordIsEmpty(): void
    {
        $this->useFakeInstalledWorkspace('status-empty-pass', ['MODX_TESTBENCH_DB_PASS' => '']);

        self::assertSame('', TestbenchKernel::instance()->config()->database->password);

        $tester = new CommandTester(new StatusCommand());
        $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('empty', $output);
        self::assertStringNotContainsString('***', $output);
    }

    /**
     * `status` must speak of the database itself, not only of the files. A throwaway "installed"
     * directory points at an empty service database, so a divergence from the table count in the
     * lock is guaranteed here and checkable without installing MODX.
     */
    public function testStatusReportsDatabaseTableCountAgainstTheLock(): void
    {
        $databaseName = RunScopedDatabaseName::forBase('modx_testbench_status_tables');
        $this->useFakeInstalledWorkspace('status-tables', [
            'MODX_TESTBENCH_DB_NAME' => $databaseName,
        ], tableCount: 70);
        $this->createDatabase($databaseName);
        $this->deferCleanup(function () use ($databaseName): void {
            $this->dropDatabase($databaseName);
        });

        $tester = new CommandTester(new StatusCommand());
        $tester->execute([]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertStringContainsString('0/70 tables', $output);
        self::assertStringContainsString('differs from the lock', $output);
    }

    /**
     * The gap `install` alone does not cover: the consumer's main path is `vendor/bin/phpunit`,
     * where the environment is prepared by `bootstrap.php` and no command is ever run. Before this,
     * `exposedSecretFiles()` had exactly one caller in production code — `InstallCommand` — while
     * `status`, the command reached for when making sense of an environment, printed the workspace,
     * the state, the version, the provider, the prefix, the DBMS, the snapshot, the strategy and a
     * table count, and said nothing about the one thing hundreds of lines of the package argue
     * about.
     */
    public function testStatusNamesTheFilesWhosePasswordAnybodyCanRead(): void
    {
        $kernel = TestbenchKernel::instance();
        $kernel->prepare();

        $configFile = $kernel->workspace()->configFile();

        self::assertFileExists($configFile);
        self::assertTrue(chmod($configFile, 0o644));

        $this->deferCleanup(static function () use ($configFile): void {
            chmod($configFile, 0o600);
        });

        $tester = new CommandTester(new StatusCommand());
        $exitCode = $tester->execute([]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        // The exit code stays successful for the same reason as in `install`: the environment is
        // ready, and the permissions are a protective measure rather than a success criterion.
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('readable by more than their owner', $output);
        $this->assertOutputContainsPath($configFile, $output);
    }

    /**
     * The mirror half. Without it the test above would also pass for a `status` that prints the
     * warning unconditionally.
     */
    public function testStatusSaysTheEnvironmentIsPrivateWhenItIs(): void
    {
        $kernel = TestbenchKernel::instance();
        $kernel->prepare();

        // Premise: the environment really is private, otherwise this test would be checking that a
        // warning is missing from a run that had nothing to warn about.
        self::assertSame([], $kernel->workspace()->exposedSecretFiles());
        self::assertSame([], $kernel->workspace()->exposedDirectories());

        $tester = new CommandTester(new StatusCommand());
        $exitCode = $tester->execute([]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Password exposure', $output);
        self::assertStringContainsString('none', $output);
        self::assertStringNotContainsString('readable by more than their owner', $output);
    }

    public function testInstallSucceedsWhenEnvironmentAlreadyPrepared(): void
    {
        $kernel = TestbenchKernel::instance();
        $kernel->prepare();

        $tester = new CommandTester(new InstallCommand());
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        $this->assertOutputContainsPath($kernel->workspace()->path(), $output);
    }

    /**
     * A silent security failure: the environment is ready while the database password lies in a file
     * that any user of the machine can read. The `FilePermissions` warning goes through PHP's error
     * mechanism, and its visibility is decided by the consumer's ini (with `display_errors=0`
     * together with `log_errors=0` — a rare but reachable combination — it reaches nowhere at all):
     * "The environment is ready" with exit code 0 would then be all they see.
     *
     * The check is LIVE, so the test gets by with an ordinary `chmod 0644` on a real file of an
     * already prepared environment — no mock, no exotic file system. The earlier edition remembered
     * the outcome of the install and would have stayed silent in this very scenario: `prepare()`
     * against a ready environment installs nothing.
     */
    public function testInstallSaysItWhenThePasswordFileIsReadableByOthers(): void
    {
        $kernel = TestbenchKernel::instance();
        $kernel->prepare();

        $configFile = $kernel->workspace()->configFile();

        self::assertFileExists($configFile);
        self::assertTrue(chmod($configFile, 0o644));

        $this->deferCleanup(static function () use ($configFile): void {
            chmod($configFile, 0o600);
        });

        $tester = new CommandTester(new InstallCommand());
        $exitCode = $tester->execute([]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        // The environment really is ready: the permissions are a protective measure rather than a success criterion.
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('readable by more than their owner', $output);
        self::assertStringContainsString('MODX_TESTBENCH_WORKSPACE', $output);
        $this->assertOutputContainsPath($configFile, $output);
    }

    public function testInstallStaysQuietWhenPermissionsAreFine(): void
    {
        $kernel = TestbenchKernel::instance();
        $kernel->prepare();

        self::assertTrue(chmod($kernel->workspace()->configFile(), 0o600));
        self::assertSame([], $kernel->workspace()->exposedSecretFiles());

        $tester = new CommandTester(new InstallCommand());
        $tester->execute([]);

        // The whitespace is normalised for the same reason as in the positive pair above:
        // `SymfonyStyle::warning()` wraps the block to the terminal width and breaks this very
        // phrase across lines ("readable by more / than their owner"). Compared against the raw
        // display, the needle can never match, and the control would stay green against a command
        // that warns unconditionally — measured by mutation.
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertStringNotContainsString('readable by more than their owner', $output);
    }

    /**
     * The choice of snapshot strategy is a silent fork: without the mysqldump/mysql (or
     * mariadb-dump/mariadb) clients in PATH the snapshot goes to the php strategy, and views and
     * triggers leave the environment together with it, while from the outside the run looks the
     * same.
     *
     * The format is taken from the lock, so the assertion does not depend on whether the clients are
     * present on the machine running this test: what is printed is compared with what was recorded.
     */
    public function testInstallNamesTheSnapshotStrategyRecordedInTheLock(): void
    {
        $workspace = TestbenchKernel::instance()->prepare();
        $format = $workspace->readLock()?->snapshotFormat;

        self::assertIsString($format);
        self::assertNotSame('', $format, 'The lock of the shared environment records no snapshot format.');

        $tester = new CommandTester(new InstallCommand());
        $tester->execute([]);

        self::assertStringContainsString(
            'Snapshot strategy: ' . $format,
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    /**
     * The second half: a fallback to the php strategy must be NAMED rather than merely marked by the
     * name of the format. A throwaway environment with a recorded `php` format gives that branch on
     * any machine — both where the clients are present (CI) and where they are not.
     */
    public function testInstallExplainsWhatThePhpSnapshotStrategyCosts(): void
    {
        $this->useFakeInstalledWorkspace(
            'install-php-strategy',
            tableCount: SchemaInventory::countTablesWithPrefix(TestbenchConfig::fromEnvironment()->database),
            snapshotFormat: 'php',
        );

        $tester = new CommandTester(new InstallCommand());
        $tester->execute([]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString('Snapshot strategy: php', $output);
        self::assertStringContainsString('views and triggers will NOT make it into the snapshot', $output);
    }

    /**
     * The name of the environment directory is a fingerprint with the DBMS password hashed into it;
     * if an outsider can read it, the consumer must learn that from the command rather than guess.
     *
     * The condition is a MEASURED fact (the directory modes) rather than the sign "the fallback to
     * `sys_get_temp_dir()` was taken". The earlier edition of the test checked the sign and thereby
     * cemented a false claim: the fallback creates no exposure by itself, the package creates the
     * whole path segment with mode 0700 — measured in a Linux container where `/tmp` has mode 1777.
     *
     * The exposure here is built the same way as in the `WorkspaceDefaultLocation` unit test: the
     * `workspaces/` directory is prepared IN ADVANCE and with wide permissions, and the root is
     * taken to be certainly traversable (`/tmp`) — inside the private `sys_get_temp_dir()` on macOS
     * the chain would break higher up, and the test would go green having checked nothing.
     *
     * The second channel (the first being the `E_USER_WARNING` from `Workspace::ensureExists()`) is
     * needed for exactly the same reason as it is for the permissions warning: the visibility of
     * `E_USER_WARNING` is decided by the consumer's ini, and with `display_errors=0` together with
     * `log_errors=0` it reaches nowhere.
     */
    public function testInstallSaysItWhenTheWorkspaceNameCanBeReadByStrangers(): void
    {
        $base = '/tmp/modx-testbench-console-loose-' . bin2hex(random_bytes(6));
        $parent = $base . '/modx-testbench/workspaces';

        self::assertTrue(mkdir($parent, 0o755, true));

        foreach ([$base, $base . '/modx-testbench', $parent] as $directory) {
            self::assertTrue(chmod($directory, 0o755));
        }

        $this->deferCleanup(function () use ($base): void {
            $this->removeDirectoryTree($base);
        });

        $this->useIsolatedKernel([
            // An empty string rather than `unset`: `Env::get()` reads the real process variable
            // through `getenv()` as a fallback (see the docblock of `ModxKit\Testbench\Support\Env`).
            'MODX_TESTBENCH_WORKSPACE' => '',
            'XDG_CACHE_HOME' => $base,
        ]);

        $workspace = TestbenchKernel::instance()->workspace();

        self::assertStringStartsWith($parent . '/', $workspace->path());
        self::assertSame([$parent], $workspace->exposedDirectories());

        // The table count is the real one: a lock with an understated count would send the integrity
        // gate to repair the SHARED database with a snapshot stub (see the docblock of useFakeInstalledWorkspace()).
        //
        // Capturing the warnings is mandatory: `ensureExists()` sounds on the merits here, and
        // `failOnWarning` in phpunit.xml turns an uncaught warning into a failure.
        $tables = SchemaInventory::countTablesWithPrefix(TestbenchKernel::instance()->config()->database);

        $warnings = $this->captureWarnings(function () use ($tables): void {
            $this->fillWorkspaceWithSignsOfInstallation($tables);
        });

        // The first channel: a PHP warning. The second is the output of the command, below.
        self::assertNotSame([], array_filter(
            $warnings,
            static fn (string $warning): bool => str_contains($warning, $parent)
        ));

        $tester = new CommandTester(new InstallCommand());
        $duringCommand = $this->captureWarnings(static function () use ($tester): void {
            $tester->execute([]);
        });

        self::assertSame([], array_filter(
            $duringCommand,
            static fn (string $warning): bool => !str_contains($warning, $parent)
        ), 'The command printed a warning unrelated to the scenario being reproduced.');

        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        // The environment is ready and the exit code is successful: the location of the directory is a
        // protective measure rather than a success criterion, on the same grounds as the file permissions.
        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString('can be read by any user of the machine', $output);
        self::assertStringContainsString(basename($workspace->path()), $output);
        $this->assertOutputContainsPath($parent, $output);
    }

    /**
     * A control for the previous test: on a regular machine there is no warning. Without it the
     * mutation "always print" would go unnoticed — and, more importantly, it is this control that
     * tells a measured exposure from one declared by the sign of a branch.
     */
    public function testInstallStaysQuietAboutTheWorkspaceNameWhenNobodyCanReadIt(): void
    {
        $workspace = TestbenchKernel::instance()->prepare();

        self::assertSame([], $workspace->exposedDirectories());

        $tester = new CommandTester(new InstallCommand());
        $tester->execute([]);

        self::assertStringNotContainsString(
            'can be read by any user of the machine',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    /**
     * `InstallCommand` must mask the password in the text of ANY exception, not only in those that
     * already mask it themselves. The real internal exceptions (`DatabaseCleaner`, the PDO driver)
     * do not carry the password verbatim — the MySQL driver answers "(using password: YES)", without
     * the value (verified by hand). To prove all the same that the masking inside the command works,
     * the password is substituted as the value of MODX_TESTBENCH_PROVIDER: "unknown provider
     * «{value}»" — `TestbenchKernel::provider()` throws an exception containing that value verbatim
     * BEFORE touching the database or the network (a fresh workspace, `isInstalledWith()` false
     * regardless of forceInstall).
     */
    public function testInstallMasksPasswordInErrorMessage(): void
    {
        $secret = 'S3cr3t-Marker-Install-7f3a';
        $workspacePath = $this->throwawayWorkspacePath('install-err');

        $this->useIsolatedKernel([
            'MODX_TESTBENCH_WORKSPACE' => $workspacePath,
            'MODX_TESTBENCH_DB_PASS' => $secret,
            'MODX_TESTBENCH_PROVIDER' => $secret,
        ]);
        $this->deferCleanup(static function (): void {
            TestbenchKernel::instance()->workspace()->destroy();
        });

        $tester = new CommandTester(new InstallCommand());
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString($secret, $output);
        self::assertStringContainsString('***', $output);
        self::assertStringContainsString('Unknown core provider', $output);

        // A refusal leaves nothing on disk. It used to leave an empty workspace directory: the
        // preparation destroyed the old environment and created the new one BEFORE the first step
        // that could fail, so a configuration error was paid for with a directory (and, on an
        // environment that was already installed, with the environment itself). The order is now
        // the other way round, and this assertion is what pins it from the command's side.
        self::assertDirectoryDoesNotExist($workspacePath);
        self::assertDirectoryDoesNotExist($workspacePath . '.new', 'The staging directory was left behind.');
    }

    /**
     * `capture()` overwrites the snapshot file with the contents of the live database. The file of
     * the SHARED working directory (`<shared workspace>/testbench-baseline.sql`) is the source of
     * the restore for every test with `RefreshesDatabase` (see `src/Concerns/RefreshesDatabase.php`)
     * and survives the run on disk: if this test captured a snapshot there, any dirt in the shared
     * database at the moment of the run (a random test order, somebody's failed teardown) would be
     * baked into the shared baseline forever. The command therefore works with a THROWAWAY directory
     * and only READS the shared database — exactly as
     * `tests/Integration/Database/SnapshotManagerTest.php` does, which also captures into a file of
     * its own rather than into the workspace one.
     */
    public function testSnapshotCaptureWritesDatabaseDumpIntoWorkspaceFile(): void
    {
        // The table count in the lock must match what really stands in the shared database. A lock that
        // understates it drives `prepare()` into the integrity gate, and that one repairs the database
        // with the snapshot STUB from the throwaway directory: the mysqldump strategy drops all the
        // tables of the shared database and "restores" it from a file without a single statement.
        $this->useFakeInstalledWorkspace(
            'snapshot-capture',
            tableCount: SchemaInventory::countTablesWithPrefix(TestbenchConfig::fromEnvironment()->database)
        );

        $kernel = TestbenchKernel::instance();
        $snapshotPath = $kernel->workspace()->snapshotPath();
        self::assertStringContainsString(self::PLACEHOLDER_SNAPSHOT_MARKER, (string) file_get_contents($snapshotPath));

        $tester = new CommandTester(new SnapshotCommand());
        $exitCode = $tester->execute(['action' => 'capture']);
        $output = $tester->getDisplay();

        self::assertSame(0, $exitCode, $output);
        $this->assertOutputContainsPath($snapshotPath, $output);

        // The stub must be OVERWRITTEN by a real dump: without this check the mutation "do not call
        // capture()" would leave the test green — the path in the success message is printed
        // regardless of whether a snapshot was taken.
        $dump = (string) file_get_contents($snapshotPath);
        self::assertStringNotContainsString(self::PLACEHOLDER_SNAPSHOT_MARKER, $dump);
        self::assertStringContainsString($kernel->config()->database->prefix . 'system_settings', $dump);
    }

    /**
     * The same masking requirement for `SnapshotCommand`: the action is data entered by the user, but
     * that alone proves only that the argument reaches the message verbatim. To prove that
     * `SnapshotCommand` masks specifically THROUGH `Secret::mask()` rather than relying on luck, the
     * password of the current environment is substituted as the `action` argument — the `match`
     * finds no match and throws a `TestbenchException` with that value inside the message verbatim.
     */
    public function testSnapshotMasksPasswordInErrorMessageOnInvalidAction(): void
    {
        $secret = 'CommandsTest-Snapshot-Secret-91';
        $this->useFakeInstalledWorkspace('snapshot-err', ['MODX_TESTBENCH_DB_PASS' => $secret]);

        $tester = new CommandTester(new SnapshotCommand());
        $exitCode = $tester->execute(['action' => $secret]);
        $output = $tester->getDisplay();

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString($secret, $output);
        self::assertStringContainsString('***', $output);
        self::assertStringContainsString('Unknown action', $output);
    }

    /**
     * Without the flag and without a TTY (`interactive: false`, as really happens in CI or a script)
     * the default is a refusal rather than a deletion. `SymfonyStyle::confirm(..., false)` in
     * non-interactive mode returns `false` without asking a single question
     * (`QuestionHelper::ask()` checks `$input->isInteractive()` before touching the input stream).
     */
    public function testDestroyRefusesInNonInteractiveModeWithoutForce(): void
    {
        $workspacePath = $this->makeThrowawayWorkspaceDirectory('destroy-noninteractive');

        $tester = new CommandTester(new DestroyCommand());
        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(0, $exitCode);
        self::assertDirectoryExists($workspacePath);
    }

    public function testDestroyProceedsWithForceFlagWithoutPrompting(): void
    {
        $workspacePath = $this->makeThrowawayWorkspaceDirectory('destroy-force');

        $tester = new CommandTester(new DestroyCommand());
        $exitCode = $tester->execute(['--force' => true]);
        $output = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertDirectoryDoesNotExist($workspacePath);
        $this->assertOutputContainsPath($workspacePath, $output);
    }

    public function testDestroyProceedsWhenUserConfirmsInteractively(): void
    {
        $workspacePath = $this->makeThrowawayWorkspaceDirectory('destroy-confirm-yes');

        $tester = new CommandTester(new DestroyCommand());
        $tester->setInputs(['yes']);
        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        self::assertDirectoryDoesNotExist($workspacePath);
    }

    public function testDestroyRefusesWhenUserDeclinesInteractively(): void
    {
        $workspacePath = $this->makeThrowawayWorkspaceDirectory('destroy-confirm-no');

        $tester = new CommandTester(new DestroyCommand());
        $tester->setInputs(['no']);
        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        self::assertDirectoryExists($workspacePath);
    }

    /**
     * A working directory the package did not create must survive `install`.
     *
     * The fingerprint of a fresh directory matches nothing (there is no lock), so `prepare()` reaches
     * `Workspace::destroy()` FIRST of all — before the network, before the database, before the
     * installer. A typo `MODX_TESTBENCH_WORKSPACE=$PWD` would destroy the project's working
     * directory here.
     */
    public function testInstallRefusesToDestroyDirectoryThatTestbenchDidNotCreate(): void
    {
        $foreignPath = $this->throwawayWorkspacePath('foreign-tree');

        self::assertTrue(mkdir($foreignPath . '/src', 0o775, true));
        file_put_contents($foreignPath . '/composer.json', '{"name":"acme/extra"}');
        file_put_contents($foreignPath . '/src/Answer.php', '<?php return 42;');

        // The database name is throwaway too: if the `destroy()` guard ever breaks (or is removed by a
        // mutation), the install must not reach the shared test database of the run.
        $this->useIsolatedKernel([
            'MODX_TESTBENCH_WORKSPACE' => $foreignPath,
            'MODX_TESTBENCH_DB_NAME' => RunScopedDatabaseName::forBase('modx_testbench_foreign_guard'),
            'MODX_TESTBENCH_DB_PASS' => 'testbench',
        ]);
        // Two registrations and not one closure with two removals: each cleanup is guarded on its
        // own (see `RunsDeferredCleanups`), so a failing assertion in one no longer skips the other.
        // Measured with the removal inside `removeWorkspaceOf()` gutted: sharing a closure left the
        // staging directory behind as well — 78 456 KiB, and measured rather than assumed, 7592
        // files of unpacked MODX tree (see the docblock of `RunsDeferredCleanups` for the listing).
        $this->deferCleanup(function () use ($foreignPath): void {
            $this->removeStagingOf($foreignPath);
        });
        $this->deferCleanup(function () use ($foreignPath): void {
            $this->removeWorkspaceOf($foreignPath);
        });

        $tester = new CommandTester(new InstallCommand());
        $exitCode = $tester->execute([]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(1, $exitCode, $output);
        self::assertFileExists($foreignPath . '/composer.json');
        self::assertStringEqualsFile($foreignPath . '/src/Answer.php', '<?php return 42;');
        self::assertStringContainsString('MODX_TESTBENCH_WORKSPACE', $output);

        // A mirror of the check for `destroy`. The DBMS password is the regular `testbench`
        // (`ci/docker-compose.yml`), that is, a substring of both names the message is obliged to
        // name. Put through `Secret::mask()` it turned into "modx-*** was not created: neither the
        // marker «.***-workspace» nor the file ***.lock.json".
        self::assertStringContainsString('.testbench-workspace', $output);
        self::assertStringContainsString('testbench.lock.json', $output);
        self::assertStringNotContainsString('***', $output);
    }

    /**
     * What a later fix broke: `destroy()` stopped losing the marker on an incomplete cleanup — and
     * thereby stopped leading to the refusal "the directory is not ours". Which means `prepare()`
     * could go on and install the core ON TOP of the remains of the previous environment. What is
     * checked here is that it does not.
     */
    public function testInstallRefusesWhenTheWorkspaceCouldNotBeClearedCompletely(): void
    {
        $path = $this->throwawayWorkspacePath('leftovers');

        self::assertTrue(mkdir($path . '/blocked', 0o775, true));
        file_put_contents($path . '/testbench.lock.json', '{}');
        file_put_contents($path . '/blocked/keep.txt', 'stuck');
        self::assertTrue(chmod($path . '/blocked', 0o555));

        $this->useIsolatedKernel([
            'MODX_TESTBENCH_WORKSPACE' => $path,
            'MODX_TESTBENCH_DB_NAME' => RunScopedDatabaseName::forBase('modx_testbench_leftovers_guard'),
        ]);
        $this->deferCleanup(function () use ($path): void {
            $this->removeStagingOf($path);
        });
        $this->deferCleanup(function () use ($path): void {
            // The subdirectory is left unwritable BY THE TEST, which is what blocks the removal it
            // reproduces. Load-bearing, not decorative: measured with this line deleted, the
            // removal below cannot finish and `removeWorkspaceOf()` reddens.
            @chmod($path . '/blocked', 0o775);
            $this->removeWorkspaceOf($path);
        });

        $tester = new CommandTester(new InstallCommand());

        // PHP reports deletion refusals with warnings; here they are expected and are part of the
        // reproducible scenario, so they are captured rather than muffled.
        $exitCode = 0;
        $warnings = $this->captureWarnings(static function () use ($tester, &$exitCode): void {
            $exitCode = $tester->execute([]);
        });
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        // The only assertion of the test that names the real cause of the incomplete cleanup:
        // the rest speak only of the exit code and of the text of the command.
        $this->assertWarnedAboutBlockedFile($warnings, $path . '/blocked/keep.txt');
        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('could not be cleared completely', $output);
        // The directory stays ours — a repeated run will be able to finish clearing it.
        self::assertFileExists($path . '/.testbench-workspace');
        self::assertFileExists($path . '/blocked/keep.txt');
    }

    /**
     * `destroy` must not report success without having done the work. An incomplete cleanup does not
     * become an exception (the directory stays marked so that it can be finished off), but the exit
     * code must be non-zero — that is what the command is called for in CI.
     */
    public function testDestroyReportsFailureWhenTheDirectoryCouldNotBeClearedCompletely(): void
    {
        $path = $this->throwawayWorkspacePath('destroy-leftovers');

        self::assertTrue(mkdir($path . '/blocked', 0o775, true));
        file_put_contents($path . '/testbench.lock.json', '{}');
        file_put_contents($path . '/blocked/keep.txt', 'stuck');
        self::assertTrue(chmod($path . '/blocked', 0o555));

        $this->useIsolatedKernel(['MODX_TESTBENCH_WORKSPACE' => $path]);
        $this->deferCleanup(function () use ($path): void {
            @chmod($path . '/blocked', 0o775);
            $this->removeWorkspaceOf($path);
        });

        $tester = new CommandTester(new DestroyCommand());

        $exitCode = 0;
        $warnings = $this->captureWarnings(static function () use ($tester, &$exitCode): void {
            $exitCode = $tester->execute(['--force' => true]);
        });
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        $this->assertWarnedAboutBlockedFile($warnings, $path . '/blocked/keep.txt');
        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('was not fully deleted', $output);
        self::assertStringNotContainsString('Environment deleted', $output);

        // The directory stays ours — a repeated run will be able to finish clearing it.
        self::assertFileExists($path . '/.testbench-workspace');
        self::assertFileExists($path . '/blocked/keep.txt');
    }

    /**
     * The same protection from the `destroy` side: the command must say why and return 1 rather than
     * drop a raw Symfony stack (NFR-3).
     *
     * The DBMS password is set to exactly the one the package suggests itself
     * (`ci/docker-compose.yml`, the README): `testbench` — a substring both of the ownership marker
     * `.testbench-workspace` and of the file `testbench.lock.json`. Put through `Secret::mask()` the
     * message turned into "modx-*** was not created: it contains neither the marker
     * `.***-workspace` nor the file `***.lock.json`" — the user was told to look for exactly the two
     * names that had been hidden. The assertions below require both names LITERALLY.
     */
    public function testDestroyNamesTheOwnershipMarkerEvenWhenItLooksLikeThePassword(): void
    {
        $foreignPath = $this->throwawayWorkspacePath('foreign-destroy');

        self::assertTrue(mkdir($foreignPath, 0o775, true));
        file_put_contents($foreignPath . '/notes.txt', 'important');

        $this->useIsolatedKernel([
            'MODX_TESTBENCH_WORKSPACE' => $foreignPath,
            'MODX_TESTBENCH_DB_PASS' => 'testbench',
        ]);
        $this->deferCleanup(function () use ($foreignPath): void {
            $this->removeWorkspaceOf($foreignPath);
        });

        $tester = new CommandTester(new DestroyCommand());
        $exitCode = $tester->execute(['--force' => true]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(1, $exitCode, $output);
        self::assertStringEqualsFile($foreignPath . '/notes.txt', 'important');
        self::assertStringContainsString('.testbench-workspace', $output);
        self::assertStringContainsString('testbench.lock.json', $output);
        self::assertStringNotContainsString('***', $output);
    }

    /**
     * The same defect one step further out: `Workspace::forConfig()` rejects a working
     * directory of `/` with a message built ONLY from the package's own prose and the
     * rejected path, and that message names the replacement to use — `/tmp/modx-testbench`.
     * The command sent it through `Secret::mask()` all the same, and with the password the
     * package itself proposes (`testbench`, `ci/docker-compose.yml`) the recommendation came
     * out as `(/tmp/modx-***, for example)`: the user was told to look for exactly the name
     * that had been hidden from them.
     *
     * Unlike the ownership refusal above, this path had no exception type of its own, so no
     * command could tell it apart from a message that really may carry a password. The
     * assertion below demands the recommended path LITERALLY.
     */
    public function testDestroyKeepsTheRecommendedPathEvenWhenItLooksLikeThePassword(): void
    {
        $this->useIsolatedKernel([
            'MODX_TESTBENCH_WORKSPACE' => '/',
            'MODX_TESTBENCH_DB_PASS' => 'testbench',
        ]);

        $tester = new CommandTester(new DestroyCommand());
        $exitCode = $tester->execute(['--force' => true]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('MODX_TESTBENCH_WORKSPACE', $output);
        self::assertStringContainsString('/tmp/modx-testbench', $output);
        self::assertStringNotContainsString('***', $output);
    }

    /**
     * An unknown action must be rejected BEFORE any work with the environment. The directory is
     * deliberately a foreign one — if the command called `prepare()` first, the refusal would be the
     * wrong one (and from the wrong place), and against a fresh directory it would go to the network
     * for the distribution.
     */
    public function testSnapshotRejectsUnknownActionWithoutPreparingTheEnvironment(): void
    {
        $foreignPath = $this->throwawayWorkspacePath('snapshot-bogus');

        self::assertTrue(mkdir($foreignPath, 0o775, true));
        file_put_contents($foreignPath . '/notes.txt', 'important');

        $this->useIsolatedKernel(['MODX_TESTBENCH_WORKSPACE' => $foreignPath]);
        $this->deferCleanup(function () use ($foreignPath): void {
            $this->removeWorkspaceOf($foreignPath);
        });

        $tester = new CommandTester(new SnapshotCommand());
        $exitCode = $tester->execute(['action' => 'bogus']);
        $output = $tester->getDisplay();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('Unknown action', $output);
        // The environment is untouched: no install, no unpacking of the core, no snapshot.
        self::assertSame(['notes.txt'], array_values(array_diff(
            (array) scandir($foreignPath),
            ['.', '..']
        )));
    }

    /**
     * The only test that really runs `install --force` — a full install of MODX in a subprocess. The
     * working directory is THROWAWAY (a random path in the temporary directory), and the database
     * name is separate from the shared `MODX_TESTBENCH_DB_NAME` — otherwise
     * `TestbenchKernel::prepare()` would call `DatabaseCleaner::purgeInstallation()` against the
     * shared database BEFORE reinstalling and would drop the tables every other level 2 test uses,
     * right in the middle of the run (exactly the class of state leak that was closed earlier).
     */
    public function testInstallForceRebuildsIsolatedWorkspaceAndSnapshotRestoreWorks(): void
    {
        $workspacePath = $this->throwawayWorkspacePath('force-lifecycle');
        $databaseName = RunScopedDatabaseName::forBase('modx_testbench_console_e2e');

        $this->useIsolatedKernel([
            'MODX_TESTBENCH_WORKSPACE' => $workspacePath,
            'MODX_TESTBENCH_DB_NAME' => $databaseName,
        ]);
        $this->deferCleanup(function () use ($databaseName): void {
            TestbenchKernel::instance()->workspace()->destroy();
            $this->dropDatabase($databaseName);
        });

        $installTester = new CommandTester(new InstallCommand());
        $installExitCode = $installTester->execute(['--force' => true]);

        self::assertSame(0, $installExitCode, $installTester->getDisplay());
        $this->assertOutputContainsPath($workspacePath, $installTester->getDisplay());
        self::assertDirectoryExists($workspacePath);
        self::assertNotNull(TestbenchKernel::instance()->workspace()->readLock());

        // The observable contract of the `--force` branch is the reinstall flag being set, which
        // `TestbenchConfig::fromEnvironment()` reads. Against a CERTAINLY NEW directory the outcome of
        // an install with the flag and without it is the same (`isInstalledWith()` is false either
        // way), so without this check the mutation "stop setting the flag" is caught by nothing.
        self::assertSame('1', $_SERVER['MODX_TESTBENCH_FORCE_INSTALL'] ?? null);

        // `snapshot restore` is checked behaviourally rather than by the exit code alone: a row that
        // was certainly in the database at the moment the baseline was captured (captured by
        // `prepare()` itself at the end of the install) is deleted, and the restore is confirmed to
        // have brought it back. Otherwise the mutation `'restore' => $snapshots->restore()` →
        // `'restore' => null` would go unnoticed. The database here is THROWAWAY ($databaseName).
        $table = TestbenchKernel::instance()->config()->database->prefix . 'system_settings';
        $pdo = $this->connection();
        $pdo->exec("DELETE FROM `{$table}` WHERE `key` = 'site_name'");
        self::assertSame('0', $this->countSiteNameSetting($pdo, $table), 'The row was not deleted — the restore check has degenerated.');

        $snapshotTester = new CommandTester(new SnapshotCommand());
        $restoreExitCode = $snapshotTester->execute(['action' => 'restore']);

        self::assertSame(0, $restoreExitCode, $snapshotTester->getDisplay());
        self::assertSame('1', $this->countSiteNameSetting($pdo, $table));

        $destroyTester = new CommandTester(new DestroyCommand());
        $destroyExitCode = $destroyTester->execute(['--force' => true]);

        self::assertSame(0, $destroyExitCode, $destroyTester->getDisplay());
        self::assertDirectoryDoesNotExist($workspacePath);
    }

    /**
     * The wiring of the executable file itself: `CommandTester` instantiates the command classes
     * directly and does not execute `bin/modx-testbench` at all, so a forgotten
     * `$application->add(...)` would be caught by none of the tests above. A real process is launched
     * with the built-in `list` command — it prints the names of the registered commands and does not
     * touch the database.
     */
    public function testExecutableRegistersAllFourCommands(): void
    {
        $executable = dirname(__DIR__, 3) . '/bin/modx-testbench';
        self::assertFileExists($executable);

        $process = new Process([PHP_BINARY, $executable, 'list', '--no-ansi']);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertSame(0, $process->getExitCode(), $output);

        foreach (['install', 'status', 'destroy', 'snapshot'] as $command) {
            self::assertMatchesRegularExpression('/^\s+' . $command . '\s+\S/m', $output, $output);
        }
    }

    /**
     * `SymfonyStyle` hard-wraps long lines to the width of the terminal (`success()` as a block,
     * `definitionList()` as a table), and working directories in /tmp are longer than the typical
     * width without a real TTY — the path is physically broken by a line feed and an indent in the
     * middle. A direct `assertStringContainsString($path, $output)` fails in that case not because of
     * a bug in the command but because of the formatting of the output: the comparison is made with
     * whitespace stripped from both sides.
     */
    private function assertOutputContainsPath(string $path, string $output): void
    {
        $normalizedPath = (string) preg_replace('/\s+/', '', $path);
        $normalizedOutput = (string) preg_replace('/\s+/', '', $output);

        self::assertStringContainsString(
            $normalizedPath,
            $normalizedOutput,
            "The output does not contain the path «{$path}» (after whitespace was stripped):\n{$output}"
        );
    }

    /**
     * Narrows the assertion down to a warning about a SPECIFIC file: the capture wraps the execution
     * of the command as a whole, so the list also holds warnings that have nothing to do with the
     * scenario being reproduced.
     *
     * @param list<string> $warnings
     */
    private function assertWarnedAboutBlockedFile(array $warnings, string $file): void
    {
        $naming = array_values(array_filter(
            $warnings,
            static fn (string $warning): bool => str_contains($warning, $file)
                && str_contains($warning, 'Permission denied')
        ));

        self::assertNotSame(
            [],
            $naming,
            "No warning named «{$file}» with a permissions refusal:\n"
            . implode("\n", $warnings)
        );
    }

    /**
     * Recursive cleanup of the test's throwaway directory. `Workspace::destroy()` is no good here:
     * it removes only the environment directory itself, while the exposure test also needs the
     * manually prepared chain of parents removed.
     */
    private function removeDirectoryTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /**
     * Removes the staging directory of a throwaway working directory, and says so with an
     * assertion rather than trusting the removal.
     *
     * `<workspace>.new` is left on disk BY DESIGN when preparation refuses after the core has been
     * staged: `Workspace::prepareStaging()` clears the leftover on the next attempt at the SAME
     * path. A test's path, however, is `…-<random hex>` and never comes round again, so nothing
     * ever clears it and the remains accumulate one run at a time. Measured before this cleanup
     * existed: a green run of the two refusal tests alone left two such directories, 156 912 KiB
     * together — and one session of running the suite filled 4.8 GiB of the developer's disk.
     *
     * The assertion is the point. Cleanup without it came back silently once already.
     */
    private function removeStagingOf(string $workspacePath): void
    {
        $this->removeDirectoryTree($workspacePath . '.new');

        self::assertDirectoryDoesNotExist(
            $workspacePath . '.new',
            'The staging directory of a throwaway workspace outlived the test.'
        );
    }

    /**
     * Removes a throwaway working directory, and says so with an assertion — for the same reason
     * {@see self::removeStagingOf()} does, and it is the same directory those tests were leaving
     * behind.
     *
     * A chain of `@unlink()`/`@rmdir()` used to stand in each of these cleanups, and a cleanup that
     * had stopped working looked exactly like one that worked. Measured, both halves of that:
     * `rmdir()` on a directory that is not empty returns `false` and leaves the directory standing,
     * and the call sites threw that `false` away; and the suppression is what hid the rest. That
     * second half is measured against its own control, because a green run proves nothing on its
     * own — two tests differing only in the `@`, both doing `rmdir()` on a non-empty directory,
     * under this suite's `failOnWarning="true"`: the suppressed one exits 0 with no warning
     * printed, the bare one prints `rmdir(…): Directory not empty` and exits 1.
     *
     * The assertion has teeth beyond that: two of the callers above begin their cleanup with an
     * `@chmod()` that makes a deliberately unwritable subdirectory writable again. Delete that line
     * from one of them and the recursive removal cannot finish — measured, that caller's test then
     * reddens here with "The throwaway workspace outlived the test".
     */
    private function removeWorkspaceOf(string $workspacePath): void
    {
        $this->removeDirectoryTree($workspacePath);

        self::assertDirectoryDoesNotExist(
            $workspacePath,
            'The throwaway workspace outlived the test.'
        );
    }

    private function throwawayWorkspacePath(string $label): string
    {
        return sys_get_temp_dir() . '/modx-testbench-console-' . $label . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Creates a throwaway working directory (without installing MODX — `DestroyCommand` works with
     * any existing directory, and a marker file makes the assertions about its contents meaningful)
     * and switches the singleton to it.
     */
    private function makeThrowawayWorkspaceDirectory(string $label): string
    {
        $path = $this->throwawayWorkspacePath($label);

        $this->useIsolatedKernel(['MODX_TESTBENCH_WORKSPACE' => $path]);

        $workspace = TestbenchKernel::instance()->workspace();
        $workspace->ensureExists();
        file_put_contents($path . '/marker.txt', 'placeholder');

        $this->deferCleanup(static function () use ($workspace): void {
            $workspace->destroy();
        });

        return $path;
    }

    /**
     * Switches the singleton to a THROWAWAY working directory that looks installed.
     *
     * `MODX_TESTBENCH_DB_PASS` alone is NOT ENOUGH for isolation. The password is part of
     * `TestbenchConfig::fingerprint()`, so an "isolating" password yields a directory that does not
     * exist yet — and `prepare()` would go off installing the environment for real, with a
     * deliberately wrong password and against the shared database (whose name does not depend on the
     * fingerprint). The directory is therefore set here explicitly, through
     * `MODX_TESTBENCH_WORKSPACE`, and filled with the signs of an installed environment.
     *
     * A full install of MODX is not needed for such tests: `isInstalledWith()` requires exactly a
     * lock with a matching fingerprint, `index.php` and `core/config/config.inc.php`, while a
     * snapshot file with the completion marker suppresses the re-capture of the baseline.
     *
     * But `prepare()`, since the integrity gate appeared, does touch the database — it compares the
     * table count against the lock. Tests that do reach `prepare()` and look at the SHARED database
     * must therefore pass the REAL table count in here: an understated one sends the gate off to
     * repair the database from the snapshot stub lying in the throwaway directory.
     *
     * @param array<string, string> $overrides
     */
    private function useFakeInstalledWorkspace(
        string $label,
        array $overrides = [],
        int $tableCount = 0,
        string $snapshotFormat = '',
    ): string {
        $path = $this->throwawayWorkspacePath($label);

        $this->useIsolatedKernel(['MODX_TESTBENCH_WORKSPACE' => $path] + $overrides);

        $this->fillWorkspaceWithSignsOfInstallation($tableCount, $snapshotFormat);

        return $path;
    }

    /**
     * Fills the directory of an ALREADY SWITCHED core with the signs of an installed environment.
     *
     * Separated from {@see self::useFakeInstalledWorkspace()} because that one sets the directory
     * through the `MODX_TESTBENCH_WORKSPACE` variable, while the caller here needs exactly the
     * opposite case: the variable is absent and the package picks the path itself.
     */
    private function fillWorkspaceWithSignsOfInstallation(int $tableCount, string $snapshotFormat = ''): void
    {
        $kernel = TestbenchKernel::instance();
        $config = $kernel->config();
        $workspace = $kernel->workspace();

        $workspace->ensureExists();
        $this->deferCleanup(static function () use ($workspace): void {
            $workspace->destroy();
        });

        if (!is_dir($workspace->corePath() . 'config') && !mkdir($workspace->corePath() . 'config', 0o775, true)) {
            self::fail("Could not create {$workspace->corePath()}config");
        }

        file_put_contents($workspace->indexFile(), "<?php\n");
        file_put_contents($workspace->configFile(), "<?php\n");
        // The completion marker is mandatory: without it `prepare()` would take the stub for the
        // remains of an interrupted capture() and would go off capturing a baseline into the shared database.
        file_put_contents(
            $workspace->snapshotPath(),
            self::PLACEHOLDER_SNAPSHOT_MARKER . "\n" . SnapshotFile::completionLine(0)
        );

        $workspace->writeLock(new LockFile(
            fingerprint: $config->fingerprint(),
            modxVersion: $config->version,
            provider: $config->provider,
            tablePrefix: $config->database->prefix,
            installedAt: gmdate('c'),
            hasSnapshot: true,
            tableCount: $tableCount,
            snapshotFormat: $snapshotFormat,
        ));

        self::assertTrue(
            $workspace->isInstalledWith($config->fingerprint()),
            'The throwaway directory does not look installed — prepare() would go into the shared database.'
        );
    }

    /**
     * Switches `TestbenchKernel::instance()` to a configuration with the given environment variable
     * overrides and registers the restoration BEFORE any cleanups the test adds itself — LIFO means
     * the test-specific cleanups (remove the directory, drop the database) run FIRST, while the
     * singleton still points at the throwaway environment, and the restoration of the variables plus
     * the final `TestbenchKernel::reset()` run LAST.
     *
     * It also restores `MODX_TESTBENCH_FORCE_INSTALL`: `InstallCommand` with `--force` sets it and
     * never clears it itself (see `src/Console/InstallCommand.php`) — that is deliberately safe for a
     * one-off CLI process, but would be a leak between tests.
     *
     * @param array<string, string> $overrides
     */
    private function useIsolatedKernel(array $overrides): void
    {
        $trackedKeys = array_keys($overrides);
        $trackedKeys[] = 'MODX_TESTBENCH_FORCE_INSTALL';

        $restoreOriginals = $this->serverVariableRestorer($trackedKeys);

        foreach ($overrides as $key => $value) {
            $_SERVER[$key] = $value;
        }

        // A throwaway environment must never inherit a foreign FORCE_INSTALL — otherwise
        // TestbenchKernel::prepare() would unconditionally reinstall it, even when the particular test
        // did not ask for that.
        unset($_SERVER['MODX_TESTBENCH_FORCE_INSTALL']);

        TestbenchKernel::reset();

        $this->deferCleanup(static function () use ($restoreOriginals): void {
            $restoreOriginals();

            TestbenchKernel::reset();
        });
    }

    /**
     * A connection to the database of the CURRENT (switched) core configuration — used only by the
     * lifecycle test, whose database is a throwaway one.
     */
    private function connection(): PDO
    {
        $database = TestbenchKernel::instance()->config()->database;

        return new PDO(
            $database->dsn(),
            $database->user,
            $database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function countSiteNameSetting(PDO $pdo, string $table): string
    {
        $statement = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `key` = 'site_name'");
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }

    /**
     * The throwaway database for {@see self::testStatusReportsDatabaseTableCountAgainstTheLock()}:
     * the `DROP` before the `CREATE` removes a same-named database if one has been left behind.
     */
    private function createDatabase(string $name): void
    {
        $database = TestbenchKernel::instance()->config()->database;
        $pdo = new PDO(
            $database->dsnWithoutDatabase(),
            $database->user,
            $database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $quoted = '`' . str_replace('`', '``', $name) . '`';
        $pdo->exec('DROP DATABASE IF EXISTS ' . $quoted);
        $pdo->exec('CREATE DATABASE ' . $quoted);
    }

    /**
     * Cleanup of the throwaway database — both the one created by
     * {@see self::testStatusReportsDatabaseTableCountAgainstTheLock()} and the one left by
     * {@see self::testInstallForceRebuildsIsolatedWorkspaceAndSnapshotRestoreWorks()}. A failure here
     * is no reason to hide the real result of the test, so it is suppressed silently: `tearDown()`
     * will restore the environment and the singleton on the next step of the LIFO queue anyway.
     */
    private function dropDatabase(string $name): void
    {
        $database = TestbenchKernel::instance()->config()->database;

        try {
            $pdo = new PDO(
                $database->dsnWithoutDatabase(),
                $database->user,
                $database->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $name) . '`');
        } catch (Throwable) {
            // see the docblock of the method.
        }
    }

}

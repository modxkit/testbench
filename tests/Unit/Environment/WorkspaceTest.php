<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Environment;

use FilesystemIterator;
use ModxKit\Testbench\Environment\LockFile;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\Workspace;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Exception\WorkspaceOwnershipException;
use ModxKit\Testbench\Tests\Support\CapturesWarnings;
use ModxKit\Testbench\Tests\Unit\Installer\UnchmodableStreamWrapper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[Group('unit')]
final class WorkspaceTest extends TestCase
{
    use CapturesWarnings;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/modx-testbench-test-' . bin2hex(random_bytes(4));
        $_SERVER['MODX_TESTBENCH_WORKSPACE'] = $this->tempDir;
    }

    /**
     * The directory is removed by our own means rather than by `Workspace::destroy()`: some of the
     * tests below deliberately prepare a directory that `destroy()` must refuse to delete.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        unset($_SERVER['MODX_TESTBENCH_WORKSPACE']);
    }

    /**
     * A live check of the permissions on the files carrying a password: the file system is asked,
     * not the memory of the install. The earlier edition remembered the outcome of `chmod` during
     * the install and would have said nothing on the path where a ready environment is reused —
     * no install runs there, and the password in `config.inc.php` does not become any less
     * accessible for that.
     */
    public function testReportsPasswordFilesReadableByOthers(): void
    {
        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        self::assertTrue(mkdir($this->tempDir . '/core/config', 0o775, true));
        self::assertTrue(mkdir($this->tempDir . '/setup', 0o775, true));
        self::assertNotFalse(file_put_contents($workspace->configFile(), "<?php // password\n"));
        self::assertTrue(chmod($workspace->configFile(), 0o600));

        // The permissions are fine and there is no manifest (the norm after a successful install).
        self::assertSame([], $workspace->exposedSecretFiles());

        self::assertTrue(chmod($workspace->configFile(), 0o644));

        self::assertSame([$workspace->configFile()], $workspace->exposedSecretFiles());

        // The install manifest carries the same passwords and is checked on a par with the core config.
        $manifest = $workspace->setupPath() . 'config.xml';

        self::assertNotFalse(file_put_contents($manifest, '<modx/>'));
        self::assertTrue(chmod($manifest, 0o640));

        self::assertSame([$workspace->configFile(), $manifest], $workspace->exposedSecretFiles());
    }

    /**
     * The permissions may have been changed by somebody else: the MODX install runs in a child
     * process, and the working directory lives between runs. PHP's `stat` cache holds ONE file per
     * name, does not expire by itself and learns nothing of a foreign `chmod` — without
     * `clearstatcache()` the second question about the permissions would get an answer taken BEFORE
     * the foreign edit.
     *
     * The window between the two calls is deliberately sterile. The foreign process is spawned
     * BEFORE the first call and waits for its stdin to be closed, because almost everything that is
     * convenient for waiting on a foreign `chmod` flushes the cache on behalf of the code under test
     * (measured on PHP 8.4.8): `exec()`, reading and writing a pipe, `stat` of any OTHER existing
     * file. What does not touch the cache is `usleep()`, `proc_get_status()` and `stat` of a
     * non-existent path (`setup/config.xml` inside the check itself), so the wait is built on those.
     *
     * The central operation of the window is `fclose($pipes[0])`, and it is sterile NOT in itself:
     * nothing was ever written into the pipe, the buffer is empty, there is nothing to flush. Signal
     * the child with `fwrite()` (even with a close right after) and the test is silently disarmed:
     * writing into the pipe flushes the `stat` cache on behalf of the code under test, and it stays
     * green even without `clearstatcache()`. The signal must not be changed.
     */
    public function testAnswersAboutTheCurrentModeEvenAfterAnExternalChange(): void
    {
        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        self::assertTrue(mkdir($this->tempDir . '/core/config', 0o775, true));
        self::assertNotFalse(file_put_contents($workspace->configFile(), "<?php // password\n"));
        self::assertTrue(chmod($workspace->configFile(), 0o600));

        $foreign = proc_open(
            ['/bin/sh', '-c', 'read line; chmod 0644 ' . escapeshellarg($workspace->configFile())],
            [0 => ['pipe', 'r']],
            $pipes
        );

        self::assertIsResource($foreign);

        // Put the permissions into this process's `stat` cache.
        self::assertSame([], $workspace->exposedSecretFiles());

        // The signal to the child is a closed stdin: `read` gets EOF, and `chmod` follows.
        // Closing it, and NOT `fwrite()`: nothing was written into the pipe, so the `stat` cache is
        // intact — a write would have flushed it on behalf of the code under test (see the docblock).
        fclose($pipes[0]);

        $status = proc_get_status($foreign);
        $deadline = microtime(true) + 5.0;

        while ($status['running'] && microtime(true) < $deadline) {
            usleep(200);
            $status = proc_get_status($foreign);
        }

        self::assertFalse($status['running'], 'The foreign process did not finish within 5 seconds.');
        self::assertSame(0, $status['exitcode']);

        self::assertSame([$workspace->configFile()], $workspace->exposedSecretFiles());

        proc_close($foreign);
    }

    /**
     * A write permission for the group is no better than a read one: the criterion is any access
     * beyond the owner's.
     */
    public function testGroupWriteAccessCountsAsExposure(): void
    {
        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        self::assertTrue(mkdir($this->tempDir . '/core/config', 0o775, true));
        self::assertNotFalse(file_put_contents($workspace->configFile(), "<?php // password\n"));
        self::assertTrue(chmod($workspace->configFile(), 0o620));

        self::assertSame([$workspace->configFile()], $workspace->exposedSecretFiles());
    }

    private function removeDirectory(string $path): void
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
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }

    public function testPathHonoursExplicitWorkspaceDirectory(): void
    {
        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        self::assertSame($this->tempDir, $workspace->path());
        self::assertSame($this->tempDir . '/core/', $workspace->corePath());
        self::assertSame($this->tempDir . '/setup/index.php', $workspace->setupPath() . 'index.php');
    }

    public function testWorkspaceIsNotInstalledBeforeLockIsWritten(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $workspace = Workspace::forConfig($config);

        self::assertFalse($workspace->isInstalledWith($config->fingerprint()));
    }

    public function testLockRoundTripsThroughDisk(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $workspace = Workspace::forConfig($config);
        $workspace->ensureExists();

        $workspace->writeLock(new LockFile(
            fingerprint: $config->fingerprint(),
            modxVersion: '3.2.3-pl',
            provider: 'zip',
            tablePrefix: 'modx_',
            installedAt: '2026-08-20T10:00:00+00:00',
            hasSnapshot: false,
            tableCount: 70,
            snapshotFormat: 'mysql',
        ));

        $lock = $workspace->readLock();

        self::assertNotNull($lock);
        self::assertSame('3.2.3-pl', $lock->modxVersion);
        self::assertFalse($lock->hasSnapshot);
        self::assertSame(70, $lock->tableCount);
        // What the baseline was taken with is part of the environment's state, not a runtime detail.
        self::assertSame('mysql', $lock->snapshotFormat);
        self::assertSame(LockFile::CURRENT_REVISION, $lock->installRevision);
    }

    /**
     * A lock WITHOUT the format key is a legitimate input (that is how any environment deployed
     * before the key appeared looks). It must be read, not made to break the reading.
     */
    public function testLockWithoutSnapshotFormatIsStillReadable(): void
    {
        $lock = LockFile::fromArray([
            'fingerprint' => 'aaaaaaaaaaaa',
            'modx_version' => '3.2.3-pl',
            'provider' => 'zip',
            'table_prefix' => 'modx_',
            'installed_at' => '2026-08-20T10:00:00+00:00',
            'has_snapshot' => true,
            'table_count' => 70,
            'install_revision' => 1,
        ]);

        self::assertSame('', $lock->snapshotFormat);
        self::assertSame('mysql', $lock->withSnapshot('mysql')->snapshotFormat);
    }

    /**
     * The core files are created deliberately, and a matching fingerprint is checked first.
     *
     * Without them `isInstalledWith()` would return `false` because `index.php` and
     * `config.inc.php` are missing, it would never get as far as comparing the fingerprint, and the
     * check would degenerate into a tautology: a removed fingerprint comparison survived the whole
     * unit suite.
     */
    public function testInstalledWithRejectsDifferentFingerprint(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $workspace = Workspace::forConfig($config);
        $workspace->ensureExists();

        self::assertTrue(mkdir($this->tempDir . '/core/config', 0o775, true));
        file_put_contents($workspace->indexFile(), "<?php\n");
        file_put_contents($workspace->configFile(), "<?php\n");

        $workspace->writeLock(new LockFile(
            fingerprint: $config->fingerprint(),
            modxVersion: '3.2.3-pl',
            provider: 'zip',
            tablePrefix: 'modx_',
            installedAt: '2026-08-20T10:00:00+00:00',
            hasSnapshot: false,
        ));

        // A control: every other condition is met, and the method answers "installed".
        self::assertTrue($workspace->isInstalledWith($config->fingerprint()));

        self::assertFalse($workspace->isInstalledWith('bbbbbbbbbbbb'));
    }

    /**
     * An environment deployed by an earlier revision of the install does not count as installed.
     *
     * The case is not hypothetical and does not reduce to "a lock without a table count": a lock may
     * carry the fingerprint, the table count and a finished snapshot — and still have been assembled
     * by a revision that did not yet put `log_deprecated = 0` into the database. There is then
     * nothing to migrate and no reason to: such an environment must be reinstalled, exactly as
     * FR-ENV-4 demands.
     *
     * The core files are created deliberately: without them the method would return `false` because
     * they are missing, it would never get as far as comparing the revision, and the check would
     * degenerate.
     */
    public function testInstalledWithRejectsLockFromAnEarlierInstallRevision(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $workspace = Workspace::forConfig($config);
        $workspace->ensureExists();

        self::assertTrue(mkdir($this->tempDir . '/core/config', 0o775, true));
        file_put_contents($workspace->indexFile(), "<?php\n");
        file_put_contents($workspace->configFile(), "<?php\n");

        $current = new LockFile(
            fingerprint: $config->fingerprint(),
            modxVersion: '3.2.3-pl',
            provider: 'zip',
            tablePrefix: 'modx_',
            installedAt: '2026-08-20T10:00:00+00:00',
            hasSnapshot: true,
            tableCount: 70,
        );

        $workspace->writeLock($current);
        self::assertTrue($workspace->isInstalledWith($config->fingerprint()));

        // All the same, but one revision earlier: the lock did not yet have the `install_revision` key.
        $earlier = $current->toArray();
        unset($earlier['install_revision']);
        $workspace->writeLock(LockFile::fromArray($earlier));

        self::assertSame(0, $workspace->readLock()?->installRevision);
        self::assertFalse($workspace->isInstalledWith($config->fingerprint()));
    }

    /**
     * A `MODX_TESTBENCH_WORKSPACE` that points at the project's working directory through a typo must
     * not lead to a recursive deletion of somebody else's files. A directory the package did not
     * create (non-empty, with neither an ownership marker nor a lock) must survive `destroy()`.
     */
    public function testDestroyRefusesDirectoryThatTestbenchDidNotCreate(): void
    {
        self::assertTrue(mkdir($this->tempDir . '/src', 0o775, true));
        file_put_contents($this->tempDir . '/composer.json', '{}');
        file_put_contents($this->tempDir . '/src/Answer.php', '<?php');

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        try {
            $workspace->destroy();
            self::fail('destroy() destroyed a directory the package had not created.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString($this->tempDir, $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_WORKSPACE', $exception->getMessage());
        }

        self::assertFileExists($this->tempDir . '/composer.json');
        self::assertFileExists($this->tempDir . '/src/Answer.php');
    }

    /**
     * A directory there is nothing to mark cannot be deleted: without a marker an interrupted
     * cleanup would leave it unidentifiable. The refusal arrives as the package's own message —
     * without a raw "Failed to open stream", which names the same cause both worse and earlier.
     */
    public function testDestroyRefusesWhenTheDirectoryCannotBeMarked(): void
    {
        self::assertTrue(mkdir($this->tempDir, 0o775, true));
        file_put_contents($this->tempDir . '/testbench.lock.json', '{}');
        file_put_contents($this->tempDir . '/index.php', '<?php');
        self::assertTrue(chmod($this->tempDir, 0o555));

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());
        $failure = null;

        $warnings = implode("\n", $this->captureWarnings(static function () use ($workspace, &$failure): void {
            try {
                $workspace->destroy();
            } catch (WorkspaceOwnershipException $exception) {
                $failure = $exception;
            }
        }));

        self::assertTrue(chmod($this->tempDir, 0o775));

        self::assertInstanceOf(WorkspaceOwnershipException::class, $failure);
        self::assertStringContainsString('.testbench-workspace', $failure->getMessage());
        self::assertStringContainsString('write permissions', $failure->getMessage());
        self::assertSame('', $warnings, 'A raw PHP warning duplicates the package message.');

        // The directory is untouched: the package does not undertake to delete what it cannot mark.
        self::assertFileExists($this->tempDir . '/index.php');
    }

    /**
     * The other side of the same check: a directory created by the package itself
     * (`ensureExists()` leaves an ownership marker) is deleted as before.
     */
    public function testDestroyRemovesDirectoryCreatedByTestbench(): void
    {
        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());
        $workspace->ensureExists();
        self::assertTrue(mkdir($this->tempDir . '/core/config', 0o775, true));
        file_put_contents($this->tempDir . '/core/config/config.inc.php', '<?php');

        $workspace->destroy();

        self::assertDirectoryDoesNotExist($this->tempDir);
    }

    /**
     * Environments installed before the ownership marker appeared carry only
     * `testbench.lock.json` — they are ours too and must be deleted.
     */
    public function testDestroyRemovesLegacyDirectoryIdentifiedByLockFile(): void
    {
        self::assertTrue(mkdir($this->tempDir, 0o775, true));
        file_put_contents($this->tempDir . '/testbench.lock.json', '{}');
        file_put_contents($this->tempDir . '/index.php', '<?php');

        Workspace::forConfig(TestbenchConfig::fromEnvironment())->destroy();

        self::assertDirectoryDoesNotExist($this->tempDir);
    }

    /**
     * The window in which the directory stopped being recognisably ours.
     *
     * The cleanup is recursive, and `testbench.lock.json` disappears among the first entries (for a
     * real environment, the FIRST of 8606). A process killed inside that window left the directory
     * non-empty and without either sign of ownership, after which the guard refused forever: neither
     * `install` nor `destroy --force` worked, and only `rm -rf` helped. A revision 0 directory has no
     * marker at all, so the window covered the whole cleanup from the second entry onwards — and it
     * is exactly those directories that the install revision drives through `destroy()` en masse.
     *
     * The break is reproduced deterministically rather than by timing: a subdirectory without write
     * permission does not allow its contents to be deleted, and the cleanup breaks off exactly on it.
     * An interrupted process would give the same state — the directory non-empty, the lock already
     * gone.
     */
    public function testInterruptedDestroyLeavesTheDirectoryRecognisablyOurs(): void
    {
        // The directory is "ours by the lock" with no marker — the shape of an environment left by the
        // previous install revision.
        self::assertTrue(mkdir($this->tempDir . '/blocked', 0o775, true));
        file_put_contents($this->tempDir . '/testbench.lock.json', '{}');
        file_put_contents($this->tempDir . '/index.php', '<?php');
        file_put_contents($this->tempDir . '/blocked/keep.txt', 'stuck');
        self::assertTrue(chmod($this->tempDir . '/blocked', 0o555));

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());
        self::assertFileDoesNotExist($workspace->ownershipMarkerPath());

        // PHP reports deletion refusals with warnings; here they are expected and are part of the
        // reproducible scenario, so they are captured rather than muffled.
        $warnings = implode("\n", $this->captureWarnings(static fn (): null => $workspace->destroy()));

        // This doubles as insurance against degeneration: if the deletion did NOT get blocked (a run
        // as root, say, for which 0555 is no obstacle), there would be no warning and the test would
        // go red here rather than pass for nothing.
        self::assertStringContainsString('Permission denied', $warnings);

        // The cleanup did not run to the end — and that is fine. What would not be fine is losing the
        // ownership: the lock is already gone, so the marker is what must identify the directory.
        self::assertDirectoryExists($this->tempDir);
        self::assertFileDoesNotExist($this->tempDir . '/testbench.lock.json');
        self::assertFileExists($workspace->ownershipMarkerPath());

        // A repeated call finishes what was started rather than refusing with "the directory is not ours".
        self::assertTrue(chmod($this->tempDir . '/blocked', 0o775));
        $workspace->destroy();

        self::assertDirectoryDoesNotExist($this->tempDir);
    }

    /**
     * `ensureExists()` must not appropriate a foreign non-empty directory: the marker would
     * otherwise appear in it by itself, and the next `destroy()` would wipe it on legitimate grounds.
     */
    public function testEnsureExistsDoesNotClaimForeignDirectory(): void
    {
        self::assertTrue(mkdir($this->tempDir, 0o775, true));
        file_put_contents($this->tempDir . '/composer.json', '{}');

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());
        $workspace->ensureExists();

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('was not created by modx-testbench');
        $workspace->destroy();
    }

    /**
     * A refusal of `chmod()` on the environment DIRECTORY: the run does not fail, but the refusal is
     * named.
     *
     * An earlier round marked this branch unmeasured, on the grounds that no cross-platform way to
     * break `chmod` had been found (`chflags uchg` is macOS only, `chattr +i` needs root on Linux).
     * The technique was lying in the repository: {@see UnchmodableStreamWrapper} — pure PHP, touching
     * no platform, and it fits onto a directory without a single edit. That is the fourteenth time in
     * this project that "structurally uncheckable" has been refuted, and once again not by the author
     * of the claim.
     *
     * Why the wrapper fits here without edits: `url_stat()` for a path WITHOUT a dot returns
     * `0040777`, so `is_dir()` is true (there is nothing to create), `restrictDirectoryMode()` sees
     * the group and other bits and goes into `chmod()`, and `stream_metadata()` refuses on exactly
     * `STREAM_META_ACCESS`. `claimOwnership()` then stops on its very first line: `is_file()` on
     * `.testbench-workspace` (the name contains a dot) is true.
     *
     * There is exactly one warning here, and that is checked: the path is set by
     * `MODX_TESTBENCH_WORKSPACE`, and such a directory is not judged by the exposure guard — its name
     * was invented by the consumer.
     */
    public function testRefusalToTightenTheWorkspaceDirectoryIsAnnouncedWithoutFailingTheRun(): void
    {
        UnchmodableStreamWrapper::install();
        $_SERVER['MODX_TESTBENCH_WORKSPACE'] = UnchmodableStreamWrapper::SCHEME . '://ws';

        try {
            $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

            // The capture is the `CapturesWarnings` trait; it returns a list of messages, while the checks
            // below speak of the text as a whole, so the list is joined here.
            $warnings = implode("\n", $this->captureWarnings(static function () use ($workspace): void {
                // Tightening the permissions is a protective measure rather than a criterion of success: there
                // must be no exception, otherwise the install would fail where it went through completely.
                $workspace->ensureExists();
            }));

            self::assertStringContainsString('failed to restrict the permissions', $warnings);
            self::assertStringContainsString(UnchmodableStreamWrapper::SCHEME . '://ws', $warnings);
            self::assertSame(1, substr_count($warnings, 'modx-testbench:'), $warnings);
        } finally {
            UnchmodableStreamWrapper::uninstall();
        }
    }

    /**
     * `rtrim('/', '/')` yields an empty string, and the whole package starts working with paths
     * relative to the current directory.
     */
    public function testForConfigRejectsFilesystemRoot(): void
    {
        $_SERVER['MODX_TESTBENCH_WORKSPACE'] = '/';

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('MODX_TESTBENCH_WORKSPACE');

        Workspace::forConfig(TestbenchConfig::fromEnvironment());
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Package;

use MODX\Revolution\modNamespace;
use MODX\Revolution\modWorkspace;
use MODX\Revolution\Transport\modTransportPackage;
use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\Exception\PackageRegistrationException;
use ModxKit\Testbench\Package\TransportInstaller;
use ModxKit\Testbench\Support\ProcessResult;
use ModxKit\Testbench\TestCase;
use ModxKit\Testbench\Tests\Support\CapturesWarnings;
use ModxKit\Testbench\Tests\Support\RunsDeferredCleanups;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\ExpectationFailedException;
use ZipArchive;

#[Group('integration')]
final class TransportInstallerTest extends TestCase
{
    use CapturesWarnings;
    use RefreshesDatabase;
    use RunsDeferredCleanups;

    protected function tearDown(): void
    {
        $this->runDeferredCleanups();

        // `parent::tearDown()` is isolated like every other step, and not for symmetry: a
        // `try { runDeferredCleanups(); } finally { parent::tearDown(); }` used to stand here and
        // guarded nothing, while losing the queue's own failures whenever the parent threw. The
        // measurement is in the docblock of `RunsDeferredCleanups`.
        $this->runCleanupStep(parent::tearDown(...));

        $this->reportCleanupFailures();
    }

    public function testBuildsAndInstallsFixturePackage(): void
    {
        $buildScript = dirname(__DIR__, 2) . '/Fixtures/SampleExtra/_build/build.transport.php';
        $targetDir = $this->corePath() . 'packages/';
        // The signature of the fixture is fixed in `build.transport.php`
        // (`createPackage('sampleextra', '1.0.0', 'pl')`), so the paths are deterministic.
        $signature = 'sampleextra-1.0.0-pl';

        // The cleanup is registered BEFORE the call: `build()` already creates a zip in
        // `core/packages/` (see the comment in `TransportInstaller::install()` about the coinciding
        // workspace), and `install()` then unpacks the signature directory into the same place —
        // without this cleanup both artefacts survive the test and the next run installs the package
        // on top of an already unpacked directory rather than on top of a freshly built one.
        $this->deferCleanup(function () use ($targetDir, $signature): void {
            @unlink($targetDir . $signature . '.transport.zip');
            $this->removeDirectoryRecursively($targetDir . $signature);
        });

        (new TransportInstaller($this->modx))->buildAndInstall($buildScript);

        $this->assertObjectExists(modNamespace::class, ['name' => 'sampleextra']);
        $this->assertObjectExists(modTransportPackage::class, ['package_name' => 'sampleextra']);
    }

    public function testBuildThrowsWhenBuildScriptDoesNotExist(): void
    {
        // A fake runner with no result prepared throws a LogicException on any call — if the
        // `is_file()` check disappeared, the test would fail exactly like that rather than with the
        // expected PackageRegistrationException, and the failure would point at the mutation precisely.
        $runner = new CallRecordingCommandRunner();

        $this->expectException(PackageRegistrationException::class);
        $this->expectExceptionMessageMatches('/the build script was not found/');

        (new TransportInstaller($this->modx, $runner))->build('/nonexistent/build.transport.php');
    }

    public function testBuildThrowsWithSubprocessOutputWhenSubprocessFails(): void
    {
        $buildScript = dirname(__DIR__, 2) . '/Fixtures/SampleExtra/_build/build.transport.php';
        $runner = new CallRecordingCommandRunner(new ProcessResult(1, 'partial output', 'fatal error: boom'));

        $this->expectException(PackageRegistrationException::class);
        $this->expectExceptionMessageMatches('/boom/');

        (new TransportInstaller($this->modx, $runner))->build($buildScript);
    }

    public function testBuildThrowsWhenSubprocessDoesNotPrintPackagePath(): void
    {
        $buildScript = dirname(__DIR__, 2) . '/Fixtures/SampleExtra/_build/build.transport.php';
        $runner = new CallRecordingCommandRunner(new ProcessResult(0, '', ''));

        $this->expectException(PackageRegistrationException::class);
        $this->expectExceptionMessageMatches('/the script returned no path/');

        (new TransportInstaller($this->modx, $runner))->build($buildScript);
    }

    /**
     * The build script prints the path as its last line, but not as the only thing in stdout —
     * before it, the MODX 3.2.3-pl core under PHP 8.4 prints
     * `Deprecated: Creation of dynamic property...` (verified by hand by running the fixture). A
     * naive `trim()` of the whole stdout would return a multi-line mush that would not pass
     * `is_file()`, and this test catches that: without splitting into lines, build() would throw
     * "the script returned no path" instead of returning a valid one.
     */
    public function testBuildExtractsPackagePathFromLastNonEmptyLineOfNoisyOutput(): void
    {
        $buildScript = dirname(__DIR__, 2) . '/Fixtures/SampleExtra/_build/build.transport.php';
        $packageFile = sys_get_temp_dir() . '/tb-noisy-' . bin2hex(random_bytes(4)) . '.transport.zip';
        file_put_contents($packageFile, 'zip payload irrelevant for this test');

        $noisyOutput = "\nDeprecated: Creation of dynamic property Foo::\$bar is deprecated in /path/to/file.php on line 1\n"
            . $packageFile;
        $runner = new CallRecordingCommandRunner(new ProcessResult(0, $noisyOutput, ''));

        try {
            $result = (new TransportInstaller($this->modx, $runner))->build($buildScript);

            self::assertSame($packageFile, $result);
        } finally {
            unlink($packageFile);
        }
    }

    /**
     * Completes the same requirement: stray PHP output can appear AFTER the path as well — a
     * `Warning`/`Deprecated` printed during shutdown hooks, after the script has echoed the path.
     * Without scanning from the end by the criterion "ends with `.transport.zip` and exists on
     * disk" (`TransportInstaller::extractPackagePath()`), a naive "the last non-empty line" would
     * return that warning instead of a valid path.
     */
    public function testBuildExtractsPackagePathWhenNoisyOutputFollowsThePath(): void
    {
        $buildScript = dirname(__DIR__, 2) . '/Fixtures/SampleExtra/_build/build.transport.php';
        $packageFile = sys_get_temp_dir() . '/tb-noisy-tail-' . bin2hex(random_bytes(4)) . '.transport.zip';
        file_put_contents($packageFile, 'zip payload irrelevant for this test');

        $noisyOutput = $packageFile
            . "\nWarning: Undefined array key \"foo\" in /path/to/file.php on line 1\n";
        $runner = new CallRecordingCommandRunner(new ProcessResult(0, $noisyOutput, ''));

        try {
            $result = (new TransportInstaller($this->modx, $runner))->build($buildScript);

            self::assertSame($packageFile, $result);
        } finally {
            unlink($packageFile);
        }
    }

    /**
     * A pin on the robustness of the build subprocess: alongside the diagnostics of failures it
     * matters to pin WHAT exactly is passed to `CommandRunner` — the right PHP binary, the path to
     * the build script, its directory as `cwd` and the timeout (see
     * `CallRecordingCommandRunner::$commands`).
     *
     * The third argument of `run()` (`600` in `TransportInstaller::build():75`) was checked by
     * nothing before this test — the build could hang with no timeout at all and not a single test
     * would have noticed.
     */
    public function testBuildInvokesSubprocessWithPhpBinaryScriptPathAndItsDirectoryAsCwd(): void
    {
        $buildScript = dirname(__DIR__, 2) . '/Fixtures/SampleExtra/_build/build.transport.php';
        $packageFile = sys_get_temp_dir() . '/tb-invocation-' . bin2hex(random_bytes(4)) . '.transport.zip';
        file_put_contents($packageFile, 'zip payload irrelevant for this test');
        $runner = new CallRecordingCommandRunner(new ProcessResult(0, $packageFile, ''));

        try {
            (new TransportInstaller($this->modx, $runner))->build($buildScript);

            self::assertCount(1, $runner->commands);
            self::assertSame([PHP_BINARY, $buildScript], $runner->commands[0]['command']);
            self::assertSame(dirname($buildScript), $runner->commands[0]['cwd']);
            self::assertSame(600, $runner->commands[0]['timeout']);
        } finally {
            unlink($packageFile);
        }
    }

    /**
     * `realpath()` for a non-existent path is always `false`, and before the fix `false !== false`
     * short-circuited the `&&` — the copy was skipped silently, and `install()` then failed in
     * `scanlocal`/`install` with a stray "Package not found." instead of a diagnosable failure at
     * this step. The mutation "remove the `is_file()` check of the source" is caught by exactly this
     * test: without it the exception would either not be thrown at all (the `realpath()` of both
     * non-existent sides are equal), or the message would not contain "not found" but would fail at
     * the unrelated `scanlocal` step.
     */
    public function testInstallThrowsDiagnosableExceptionWhenSourcePackageFileDoesNotExist(): void
    {
        $this->expectException(PackageRegistrationException::class);
        $this->expectExceptionMessageMatches('/package file .*was not found/');

        (new TransportInstaller($this->modx))->install('/nonexistent/sampleextra-1.0.0-pl.transport.zip');
    }

    /**
     * Without creating the missing directory, `copy()` would refuse with an opaque "could not copy",
     * without explaining that the directory was not there at all.
     *
     * Moving `core/packages/` aside registers its own `deferCleanup()` inside
     * {@see self::moveDirectoryAside()}, with no window in which a thrown exception would leave the
     * shared MODX core without a `packages/` directory for every subsequent test.
     */
    public function testInstallCreatesMissingPackagesDirectoryBeforeCopying(): void
    {
        $targetDir = $this->corePath() . 'packages/';
        $blockedPath = rtrim($targetDir, '/');

        $this->moveDirectoryAside($blockedPath);

        // Without a `-<hex>` tail in the third segment of the signature: ScanLocal parses the third
        // hyphen-separated segment as `release`/`release_index` (`preg_split('/(\d+)/', ...)` in
        // `ScanLocal::createPackage()`), and a random hex tail with a long run of digits overflows the
        // `release_index` column ("Out of range value"), littering the MODX error.log on every run.
        // Two hyphen-separated segments do not produce that at all.
        $packageFile = sys_get_temp_dir() . '/tb-fixture' . bin2hex(random_bytes(4)) . '.transport.zip';
        $this->createManifestlessZip($packageFile);
        $this->deferCleanup(static function () use ($packageFile): void {
            @unlink($packageFile);
        });

        try {
            (new TransportInstaller($this->modx))->install($packageFile);

            self::fail('An install failure was expected: the file is not a real transport package.');
        } catch (PackageRegistrationException $exception) {
            // The failure is expected, but NOT at the separately diagnosed steps: which means the
            // directory was created and the copy went through, and what refused was the real MODX
            // processor against meaningless zip contents.
            self::assertStringNotContainsString('could not be created', $exception->getMessage());
            self::assertStringNotContainsString('failed to copy the package', $exception->getMessage());
        }

        self::assertDirectoryExists($targetDir);
        self::assertFileExists($targetDir . basename($packageFile));
    }

    /**
     * `xPDO::getOption()` is declared as `@return mixed` — without an explicit type check, PHPStan
     * at level `max` would not let a cast to string (`(string) $mixed`) through. `core_path` is
     * broken by writing straight into the core's memory (`setOption()`) rather than through
     * `setSetting()`: the value is restored here in `finally`, and no separate rollback is required.
     */
    public function testInstallFailsWithDiagnosticStepWhenCorePathIsMissing(): void
    {
        $original = $this->modx->getOption('core_path');
        $this->modx->setOption('core_path', '');

        try {
            $this->expectException(PackageRegistrationException::class);
            $this->expectExceptionMessageMatches('/core_path/');

            (new TransportInstaller($this->modx))->install('/irrelevant/sampleextra-1.0.0-pl.transport.zip');
        } finally {
            $this->modx->setOption('core_path', $original);
        }
    }

    /**
     * The destination directory is absent and an ordinary file lies in its place — `mkdir()`
     * physically cannot create a directory on top of a file, and that is the only cheap way to fail
     * the creation attempt for certain without manipulating OS permissions.
     *
     * The cleanup is registered immediately after `rename()`, before the next line creates the
     * blocking file in the directory's place — so that even a failure to create the blocking file
     * would not leave `core/packages/` irresolvably renamed.
     */
    public function testInstallFailsWithDiagnosticStepWhenTargetDirectoryCannotBeCreated(): void
    {
        $targetDir = $this->corePath() . 'packages/';
        $blockedPath = rtrim($targetDir, '/');

        $this->moveDirectoryAside($blockedPath);

        file_put_contents($blockedPath, 'a plain file blocking mkdir()');

        $this->expectException(PackageRegistrationException::class);
        $this->expectExceptionMessageMatches('/does not exist and could not be created/');

        (new TransportInstaller($this->modx))->install('/irrelevant/sampleextra-1.0.0-pl.transport.zip');
    }

    /**
     * The first half. The mechanism of the defect: a cleanup registered BEFORE the `rename()` is
     * checked runs in `tearDown()` whatever the outcome — including when the directory could not be
     * moved aside and a real, live directory was left in its place.
     *
     * A non-existent path is the cheapest way to fail `rename()` for certain without manipulating OS
     * permissions.
     */
    public function testMovingADirectoryAsideFailsBeforeRegisteringTheCleanupThatWouldDeleteIt(): void
    {
        $absent = sys_get_temp_dir() . '/tb-absent-' . bin2hex(random_bytes(4));
        $registeredBefore = count($this->pendingCleanups);
        $refusal = null;

        // PHP reports a `rename()` failure with a warning; here it is part of the scenario, so it is
        // captured rather than muffled with an `@` in the helper itself.
        $this->captureWarnings(function () use ($absent, &$refusal): void {
            try {
                $this->moveDirectoryAside($absent);
            } catch (ExpectationFailedException $failure) {
                $refusal = $failure;
            }
        });

        self::assertInstanceOf(
            ExpectationFailedException::class,
            $refusal,
            'A non-existent directory cannot be moved aside — the helper must fail the test.'
        );

        self::assertCount(
            $registeredBefore,
            $this->pendingCleanups,
            'A cleanup was registered on a failed rename() — in tearDown() it would have wiped out '
            . 'a directory that is in place and that nobody moved aside.'
        );
    }

    /**
     * The second half: there is no backup, so there is nothing to remove. Here the backup never
     * appeared at all; in real life it could disappear between the cleanup being registered and
     * being executed.
     */
    public function testRestoringAMovedDirectoryLeavesTheOriginalAloneWhenTheBackupIsGone(): void
    {
        $original = sys_get_temp_dir() . '/tb-original-' . bin2hex(random_bytes(4));
        $vanishedBackup = $original . '-backup-gone';

        self::assertTrue(mkdir($original . '/inner', 0o775, true));
        self::assertNotFalse(file_put_contents($original . '/inner/keep.txt', 'live data'));
        $this->deferCleanup(function () use ($original): void {
            $this->removeDirectoryRecursively($original);
        });

        ($this->restoreMovedDirectory($original, $vanishedBackup))();

        self::assertFileExists(
            $original . '/inner/keep.txt',
            'A restore without a backup wiped out a real directory.'
        );
    }

    /**
     * The destination directory exists but is not writable — `copy()` will refuse for a reason other
     * than the directory being absent, and the message must differ from "does not exist and could
     * not be created" (an exception must carry a cause AND a next action, not the same message for
     * different failures).
     *
     * The `chmod()` registers the restoration of the permissions on the very next line, before the
     * zip fixture is created.
     */
    public function testInstallFailsWithDiagnosticStepWhenCopyFails(): void
    {
        $targetDir = $this->corePath() . 'packages/';
        $mode = fileperms($targetDir);

        // Not decoration and not symmetry with the neighbours: `false & 0o777` is `0` (measured),
        // so a failed `fileperms()` would hand the cleanup below a mode of `000` and the test would
        // go GREEN having left the shared core directory of the workspace unreadable — after which
        // every later test of every later run dies on `Failed to open directory: Permission
        // denied`, and only a manual `chmod` brings it back. This is the only place in the suite
        // where a `chmod` of a directory shared with the rest of the run is restored from a read
        // mode, so the read is checked before anything is changed.
        self::assertNotFalse($mode, sprintf('Could not read the mode of %s — nothing has been changed yet.', $targetDir));

        $originalMode = $mode & 0o777;

        chmod($targetDir, 0o500);
        $this->deferCleanup(function () use ($targetDir, $originalMode): void {
            chmod($targetDir, $originalMode);
        });

        $packageFile = sys_get_temp_dir() . '/tb-unwritable-' . bin2hex(random_bytes(4)) . '.transport.zip';
        $this->createManifestlessZip($packageFile);
        $this->deferCleanup(static function () use ($packageFile): void {
            @unlink($packageFile);
        });

        $this->expectException(PackageRegistrationException::class);
        $this->expectExceptionMessageMatches('/failed to copy the package/');

        (new TransportInstaller($this->modx))->install($packageFile);
    }

    /**
     * Covers the `workspace/packages/scanlocal` failure path: the processor refuses BEFORE the
     * install if the workspace record with `id = 1` (the default `TransportInstaller` calls
     * `scanlocal` with) is temporarily taken away — `ScanLocal::initialize()` then returns a lexicon
     * string, and MODX turns it into a `ProcessorResponse` with `isError() === true` without
     * throwing. Without a separate `$scan->isError()` check that failure would be swallowed and the
     * code would go on to call `workspace/packages/install`.
     *
     * Changing the `modWorkspace` record is a mutation of the database, and it is undone by the
     * restore from the baseline snapshot that {@see RefreshesDatabase} provides; the manual
     * `UPDATE ... SET id = 1` in `finally` is not required for correctness but is kept as a
     * self-documenting symmetry with the `UPDATE` above.
     */
    public function testInstallFailsWithDiagnosticStepWhenScanlocalErrors(): void
    {
        $workspaceTable = $this->modx->getTableName(modWorkspace::class);
        self::assertIsString($workspaceTable);

        $targetDir = $this->corePath() . 'packages/';
        $packageFile = sys_get_temp_dir() . '/tb-scanlocal-' . bin2hex(random_bytes(4)) . '.transport.zip';
        $this->createManifestlessZip($packageFile);

        // Temporarily "hide" the workspace record with id=1: scanlocal looks for it by that default
        // (the processor's `workspace` property defaults to 1).
        self::assertSame(1, $this->modx->exec("UPDATE {$workspaceTable} SET id = 999999 WHERE id = 1"));

        try {
            $this->expectException(PackageRegistrationException::class);
            // The assertion is on the processor's OWN failure rather than on the name of the step alone.
            // The earlier edition demanded only "the «scanlocal» step" and therefore stayed green in CI,
            // where the processor was not found at all and answered "Requested processor not found": the
            // test confirmed a broken product. The text "Workspace not found." is `ScanLocal`'s own answer
            // to a missing workspace record, taken from a run.
            $this->expectExceptionMessageMatches('/step "scanlocal".+Workspace not found/us');

            (new TransportInstaller($this->modx))->install($packageFile);
        } finally {
            $this->modx->exec("UPDATE {$workspaceTable} SET id = 1 WHERE id = 999999");
            unlink($packageFile);
            @unlink($targetDir . basename($packageFile));
            @unlink($targetDir . 'not-a-manifest.txt');
        }
    }

    /**
     * Covers the `scanlocal`/`install` failure path: the file is not a real transport package, so
     * the `workspace/packages/install` processor refuses, and `TransportInstaller` must turn that
     * into a diagnosable exception rather than swallow the `isError()`.
     *
     * An `expectExceptionMessageMatches('/install/')` would match ANY message of
     * `atTransportStep('install', …)`, `mkdir`/`copy` failures included — the test would not tell
     * them from a genuine processor failure. The assertion is made on the real text of the MODX
     * processor's answer ("Could not install package with signature: …", verified by running it).
     */
    public function testInstallFailsWithDiagnosticStepWhenPackageProcessorErrors(): void
    {
        $targetDir = $this->corePath() . 'packages/';
        // See the comment in `testInstallCreatesMissingPackagesDirectoryBeforeCopying()`: without a
        // third hyphen-separated segment — otherwise ScanLocal parses the tail as `release_index` and
        // MODX logs "Out of range value" on every run.
        $packageFile = sys_get_temp_dir() . '/tb-bogus' . bin2hex(random_bytes(4)) . '.transport.zip';
        $this->createManifestlessZip($packageFile);

        try {
            $this->expectException(PackageRegistrationException::class);
            $this->expectExceptionMessageMatches('/Could not install package with signature/');

            (new TransportInstaller($this->modx))->install($packageFile);
        } finally {
            unlink($packageFile);
            @unlink($targetDir . basename($packageFile));
            // `xPDOTransport::unpack()` unpacks the contents of the zip into `core/packages/` BEFORE it
            // discovers that `manifest.php` is missing, leaving `not-a-manifest.txt` in the root of
            // `packages/` even when the install fails.
            @unlink($targetDir . 'not-a-manifest.txt');
        }
    }

    /**
     * `getOption()` is declared as `@return mixed` — a narrow getter instead of a `(string)` cast on
     * every call, so that PHPStan at level `max` does not reject the cast of a potentially
     * non-string value (see the note in `TransportInstaller::corePath()`).
     */
    private function corePath(): string
    {
        $corePath = $this->modx->getOption('core_path');

        self::assertIsString($corePath);

        return $corePath;
    }

    /**
     * A structurally valid zip without an xPDOTransport manifest: `ZipArchive::open()` opens it
     * successfully (unlike an arbitrary text file, on which `xPDOTransport::unpack()` throws a
     * `ValueError` from inside `xPDOZip` — that would fall past the `try/catch(Throwable)` in
     * `modTransportPackage::install()`, since `getTransport()` is called before it), but there is no
     * manifest in it — `unpack()` returns nothing and MODX refuses cleanly through a
     * `ProcessorResponse`.
     */
    private function createManifestlessZip(string $path): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('not-a-manifest.txt', 'this package intentionally has no manifest');
        $zip->close();
    }

    /**
     * Moves a directory aside and registers its return.
     *
     * The result of `rename()` is checked BEFORE the cleanup is registered. The cleanup queue runs
     * in `tearDown()` regardless of the outcome of the test, so a cleanup registered before the
     * check would run on a failed `rename()` too — and would recursively wipe out the REAL directory
     * that nobody had moved aside.
     *
     * @return string the path of the backup
     */
    private function moveDirectoryAside(string $blockedPath): string
    {
        $backupDir = $blockedPath . '-backup-' . bin2hex(random_bytes(4));

        self::assertTrue(
            rename($blockedPath, $backupDir),
            "Could not move {$blockedPath} to {$backupDir}."
        );

        $this->deferCleanup($this->restoreMovedDirectory($blockedPath, $backupDir));

        return $backupDir;
    }

    /**
     * Returning a directory that was moved aside.
     *
     * The removal of `$blockedPath` is conditional on the backup existing. No backup means there is
     * nothing to return, which in turn means that what stands in the place of `$blockedPath` is a
     * directory this test never moved aside, and it must not be touched.
     *
     * @return callable(): void
     */
    private function restoreMovedDirectory(string $blockedPath, string $backupDir): callable
    {
        return function () use ($blockedPath, $backupDir): void {
            if (!is_dir($backupDir)) {
                return;
            }

            // In the place of the directory it moved aside the test may also have left an ordinary file —
            // that is what it blocks `mkdir()` with.
            if (is_file($blockedPath)) {
                self::assertTrue(unlink($blockedPath), "Could not remove {$blockedPath}.");
            } else {
                $this->removeDirectoryRecursively($blockedPath);
            }

            self::assertTrue(
                rename($backupDir, $blockedPath),
                "Could not return {$backupDir} to its place {$blockedPath}."
            );
        };
    }

    private function removeDirectoryRecursively(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path . '/' . $entry;

            if (is_dir($entryPath)) {
                $this->removeDirectoryRecursively($entryPath);
            } else {
                unlink($entryPath);
            }
        }

        rmdir($path);
    }
}

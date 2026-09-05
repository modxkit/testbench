<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Environment;

use FilesystemIterator;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\Workspace;
use ModxKit\Testbench\Tests\Support\CapturesWarnings;
use ModxKit\Testbench\Tests\Support\OwnsTestbenchEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Where the environment directory goes BY DEFAULT — that is, when `MODX_TESTBENCH_WORKSPACE` is not
 * set.
 *
 * A separate class rather than new methods in {@see WorkspaceTest}: that one sets
 * `MODX_TESTBENCH_WORKSPACE` firmly in its own `setUp()` and checks the behaviour of an already
 * built directory, whereas what is checked here is the exact opposite — which path the package
 * picks BY ITSELF when the variable is absent. Combining the two in one class would only be
 * possible by clearing the variable inside half of the tests, that is, with two incompatible
 * fixtures in one `setUp()`.
 */
#[Group('unit')]
final class WorkspaceDefaultLocationTest extends TestCase
{
    use CapturesWarnings;
    use OwnsTestbenchEnvironment {
        setUp as private clearTestbenchEnvironment;
        tearDown as private restoreTestbenchEnvironment;
    }

    private string $tempDir;

    /**
     * The directory for the tests about EXPOSURE of the name, and it is deliberately not in
     * `sys_get_temp_dir()`.
     *
     * Exposure cannot be reproduced inside a private directory: on macOS `sys_get_temp_dir()` is
     * `/var/folders/…/T` with `drwx------`, and the chain breaks ABOVE the directory the test
     * prepared. The test would then go green having checked nothing. So `/tmp` is taken as the root
     * — certainly traversable by outsiders (1777) both on macOS and in Linux containers; the premise
     * is checked in {@see self::setUp()} so that the test does not degenerate silently where that is
     * not so.
     */
    private string $looseDir;

    /** @var callable(): void */
    private $restoreHomeVariables;

    protected function setUp(): void
    {
        // Clears every MODX_TESTBENCH_*, MODX_TESTBENCH_WORKSPACE included: without this the test
        // would be checking an override from the run's environment rather than the default.
        $this->clearTestbenchEnvironment();

        // `HOME` and `XDG_CACHE_HOME` are real process variables, and they can only be "cleared" for
        // `Env::get()` with an empty string (the docblock of {@see \ModxKit\Testbench\Support\Env}).
        $this->restoreHomeVariables = $this->serverVariableRestorer(['HOME', 'XDG_CACHE_HOME']);

        $this->tempDir = sys_get_temp_dir() . '/modx-testbench-home-' . bin2hex(random_bytes(4));
        $this->looseDir = '/tmp/modx-testbench-loose-' . bin2hex(random_bytes(4));

        foreach (['/tmp', '/'] as $ancestor) {
            self::assertSame(
                0o001,
                fileperms($ancestor) & 0o001,
                'Premise of the exposure tests: ' . $ancestor . ' must be traversable by '
                . 'outsiders, otherwise the chain breaks above the directory that was prepared.'
            );
        }
    }

    protected function tearDown(): void
    {
        ($this->restoreHomeVariables)();
        $this->restoreTestbenchEnvironment();

        $this->removeDirectory($this->tempDir);
        $this->removeDirectory($this->looseDir);
    }

    /**
     * The fingerprint hashes the DBMS password and the administrator password (FR-ENV-4), so the
     * directory name is material for an offline brute force of a weak password: the other terms of
     * the fingerprint (host, port, database name, version) are usually known. The composition of the
     * fingerprint cannot be changed, so the mitigation is its location: the name must not lie where
     * any user of the machine can read it.
     */
    public function testDefaultWorkspaceLivesUnderTheUserCacheDirectory(): void
    {
        $_SERVER['XDG_CACHE_HOME'] = $this->tempDir;
        $_SERVER['HOME'] = '/nonexistent-home';

        $config = TestbenchConfig::fromEnvironment();

        self::assertSame(
            $this->tempDir . '/modx-testbench/workspaces/' . $config->fingerprint(),
            Workspace::forConfig($config)->path()
        );
    }

    /**
     * The scheme is the same as for the release cache ({@see TestbenchConfig::fromEnvironment()}):
     * `XDG_CACHE_HOME`, otherwise `$HOME/.cache`. No second way is invented.
     */
    public function testDefaultWorkspaceFallsBackToHomeCacheDirectory(): void
    {
        $_SERVER['XDG_CACHE_HOME'] = '';
        $_SERVER['HOME'] = $this->tempDir;

        $config = TestbenchConfig::fromEnvironment();

        self::assertSame(
            $this->tempDir . '/.cache/modx-testbench/workspaces/' . $config->fingerprint(),
            Workspace::forConfig($config)->path()
        );
    }

    /**
     * `MODX_TESTBENCH_WORKSPACE` overrides everything — the private directory the package would pick
     * by itself included. It is checked WITH `XDG_CACHE_HOME` SET: without it the test would go
     * green even against an implementation where the variable is simply not read.
     */
    public function testExplicitWorkspaceVariableStillWins(): void
    {
        $_SERVER['XDG_CACHE_HOME'] = $this->tempDir;
        $_SERVER['MODX_TESTBENCH_WORKSPACE'] = $this->tempDir . '/explicit';

        self::assertSame(
            $this->tempDir . '/explicit',
            Workspace::forConfig(TestbenchConfig::fromEnvironment())->path()
        );
    }

    /**
     * The warning about the fallback used to sound UNCONDITIONALLY and asserted an exposure that
     * measurement does not find: `ensureExists()` creates the whole path segment with
     * `mkdir(0700, recursive)` — in the fallback too — so in a world-writable `/tmp` (1777) an
     * outsider gets `Permission denied` at `modx-testbench` already. The price of the false claim
     * was not a paper one: eight tests with `#[RunInSeparateProcess]` went red for anyone without a
     * `HOME`.
     *
     * The loudness is now tied to the FACT, and what is checked here is the absence of that fact:
     * the package created the chain itself, so the chain is private, so there is nothing to say.
     */
    public function testNothingIsAnnouncedWhenThePackageCreatedTheWholeChainItself(): void
    {
        $_SERVER['XDG_CACHE_HOME'] = $this->tempDir;

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        $warnings = $this->captureWarnings(static function () use ($workspace): void {
            $workspace->ensureExists();
        });

        self::assertSame([], $warnings);
        self::assertSame([], $workspace->exposedDirectories());
    }

    /**
     * The key check: an earlier attempt tightened and checked ONE leaf, while a ready-made parent
     * with wide permissions handed the fingerprint to a listing silently.
     *
     * The package does not recreate the `workspaces/` directory if it already exists, and does not
     * correct a foreign mode on it: the directory may be shared (the release cache lives in the same
     * `modx-testbench`), and changing the permissions of a foreign directory is a side effect
     * outside one's own environment (NFR-4). So the second admissible way was chosen: SAY IT LOUD.
     */
    public function testExposureIsAnnouncedWhenTheParentDirectoryWasPreparedWithALooseMode(): void
    {
        $_SERVER['XDG_CACHE_HOME'] = $this->looseDir;

        $parent = $this->looseDir . '/modx-testbench/workspaces';

        self::assertTrue(mkdir($parent, 0o755, true));

        // `mkdir()` respects the umask, and the test must get exactly 0755 on the whole chain.
        foreach ([$this->looseDir, $this->looseDir . '/modx-testbench', $parent] as $directory) {
            self::assertTrue(chmod($directory, 0o755));
        }

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        $warnings = $this->captureWarnings(static function () use ($workspace): void {
            $workspace->ensureExists();
        });

        self::assertSame([$parent], $workspace->exposedDirectories());

        self::assertCount(1, $warnings);
        self::assertStringContainsString($parent, $warnings[0]);
        self::assertStringContainsString('MODX_TESTBENCH_WORKSPACE', $warnings[0]);

        // The environment directory itself is private here — the exposure comes from the parent.
        clearstatcache(true, $workspace->path());
        self::assertSame(0o700, fileperms($workspace->path()) & 0o777);
    }

    /**
     * A substantial finding of the re-review. The guard demanded READ AND TRAVERSE from the parent
     * at once — and thereby missed a real exposure: to list the names in a directory an outsider
     * needs only the `r` bit, the `x` bit is needed merely to `stat` the entries inside, that is,
     * for `ls -l` rather than for `ls`.
     *
     * Measured in `php:8.4-cli` with a real outsider user (`/tmp/modx-testbench` = 0755, the mode of
     * `workspaces/` being varied):
     *
     *     0777, 0755  → the guard spoke, the outsider read the fingerprint   (correct)
     *     0744, 0704  → the guard was SILENT, the outsider read it           (a miss)
     *     0740, 0701  → the guard was silent, the outsider did not read it   (correct)
     *
     * Not one test saw the difference: replacing the rule left the suite entirely green. This test
     * does see it — mode 0744 differs from the already covered 0755 by exactly the cleared `x` bit.
     */
    public function testExposureIsAnnouncedWhenTheParentIsOnlyReadableWithoutBeingTraversable(): void
    {
        $_SERVER['XDG_CACHE_HOME'] = $this->looseDir;

        $parent = $this->looseDir . '/modx-testbench/workspaces';

        self::assertTrue(mkdir($parent, 0o755, true));
        self::assertTrue(chmod($this->looseDir, 0o755));
        self::assertTrue(chmod($this->looseDir . '/modx-testbench', 0o755));

        // The only difference from the test above: the parent has the traverse bit cleared, and read
        // is missing only for the owner. An outsider still lists the names in such a directory.
        self::assertTrue(chmod($parent, 0o744));

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        $warnings = $this->captureWarnings(static function () use ($workspace): void {
            $workspace->ensureExists();
        });

        self::assertSame([$parent], $workspace->exposedDirectories());
        self::assertCount(1, $warnings);
        self::assertStringContainsString($parent, $warnings[0]);
    }

    /**
     * A break in the chain above the parent closes the exposure entirely: `workspaces/` cannot be
     * listed without passing through `modx-testbench`. Without this check the guard would answer per
     * single directory and would declare an exposure where there is none — exactly the false claim
     * the unconditional warning above used to make.
     */
    public function testNothingIsAnnouncedWhenAnAncestorBlocksTheWayToTheLooseParent(): void
    {
        $_SERVER['XDG_CACHE_HOME'] = $this->looseDir;

        $middle = $this->looseDir . '/modx-testbench';
        $parent = $middle . '/workspaces';

        self::assertTrue(mkdir($parent, 0o755, true));
        self::assertTrue(chmod($this->looseDir, 0o755));
        self::assertTrue(chmod($parent, 0o755));

        // The only difference from the test above is a closed link in the MIDDLE of the chain.
        self::assertTrue(chmod($middle, 0o700));

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        $warnings = $this->captureWarnings(static function () use ($workspace): void {
            $workspace->ensureExists();
        });

        self::assertSame([], $workspace->exposedDirectories());
        self::assertSame([], $warnings);
    }

    /**
     * An explicitly set `MODX_TESTBENCH_WORKSPACE` does not fall under the guard, and that is not a
     * concession: the consumer invented the name of such a directory, there is no fingerprint in it,
     * and there is nothing in the name to leak. Warning would be the same false claim the
     * unconditional warning used to make.
     */
    public function testExplicitWorkspaceIsNotJudgedByItsParentDirectory(): void
    {
        $parent = $this->looseDir . '/shared';

        self::assertTrue(mkdir($parent . '/mine', 0o755, true));
        self::assertTrue(chmod($this->looseDir, 0o755));
        self::assertTrue(chmod($parent, 0o755));

        $_SERVER['MODX_TESTBENCH_WORKSPACE'] = $parent . '/mine';

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());

        $warnings = $this->captureWarnings(static function () use ($workspace): void {
            $workspace->ensureExists();
        });

        self::assertSame([], $workspace->exposedDirectories());
        self::assertSame([], $warnings);
    }

    /**
     * Moving it without permissions solves nothing: on many systems `~/.cache` is readable by the
     * group and by others, and the name of the directory with the fingerprint would be read there
     * exactly as it would in `sys_get_temp_dir()`.
     *
     * The WHOLE path segment created by the package is checked, not only the last directory: the
     * right to list is granted by any of the intermediate ones.
     */
    public function testWorkspaceDirectoryIsCreatedPrivateToItsOwner(): void
    {
        $_SERVER['XDG_CACHE_HOME'] = $this->tempDir;

        self::assertTrue(mkdir($this->tempDir, 0o755, true));

        $workspace = Workspace::forConfig(TestbenchConfig::fromEnvironment());
        $workspace->ensureExists();

        foreach ([
            $this->tempDir . '/modx-testbench',
            $this->tempDir . '/modx-testbench/workspaces',
            $workspace->path(),
        ] as $created) {
            self::assertDirectoryExists($created);
            clearstatcache(true, $created);
            self::assertSame(
                0o700,
                fileperms($created) & 0o777,
                'The directory is accessible to more than its owner: ' . $created
            );
        }
    }

    /**
     * A directory created by an earlier edition of the package (or by anything else) with 0755
     * permissions is tightened as a matter of fact rather than only at the moment of creation:
     * otherwise upgrading the package would leave the name of the directory with the fingerprint
     * readable forever.
     */
    public function testExistingWorkspaceDirectoryIsTightenedOnUse(): void
    {
        $_SERVER['MODX_TESTBENCH_WORKSPACE'] = $this->tempDir;

        self::assertTrue(mkdir($this->tempDir, 0o755, true));
        self::assertSame(0o755, fileperms($this->tempDir) & 0o777);

        Workspace::forConfig(TestbenchConfig::fromEnvironment())->ensureExists();

        clearstatcache(true, $this->tempDir);

        self::assertSame(0o700, fileperms($this->tempDir) & 0o777);
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
}

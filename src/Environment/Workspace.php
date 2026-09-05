<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

use FilesystemIterator;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Exception\WorkspaceLocationException;
use ModxKit\Testbench\Exception\WorkspaceOwnershipException;
use ModxKit\Testbench\Support\Env;
use ModxKit\Testbench\Support\FilePermissions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class Workspace
{
    /**
     * Marker file recording that the directory belongs to the package. Written by
     * {@see self::ensureExists()} at the moment the directory was created by the package (or was
     * empty), and it is what authorises the recursive removal in {@see self::destroy()}.
     *
     * `testbench.lock.json` alone is not enough for that: the lock appears only AFTER a successful
     * install, while an interrupted install (SIGKILL, CI timeout) leaves the directory with an
     * unpacked core and no lock — such an environment must reinstall itself rather than demand a
     * manual `rm -rf`. The lock still counts as a sign of ownership as well: environments
     * installed before the marker existed remain removable.
     */
    private const OWNERSHIP_MARKER = '.testbench-workspace';

    /**
     * Default path tail.
     *
     * The `workspaces/` segment is not tidiness but a load-bearing part of the name-exposure
     * mitigation, and that is measured. The `<base>/modx-testbench` directory is shared with the
     * release cache and therefore usually already exists by the first run: on the development
     * machine of this branch it was created by the release cache with mode 0755, that is, open to
     * listing by everyone. A fingerprint lying directly inside it would be readable by an
     * outsider. The `workspaces/` segment is created by the package ITSELF and therefore always
     * gets 0700 ({@see self::ensureExists()}) — that is what keeps the directory name closed.
     *
     * Dropping the segment "for beauty" silently brings the exposure back. The property is held by
     * {@see self::exposedDirectories()} and the test
     * `WorkspaceDefaultLocationTest::testExposureIsAnnouncedWhenTheParentDirectoryWasPreparedWithALooseMode()`.
     */
    private const DEFAULT_SUFFIX = '/modx-testbench/workspaces/';

    /**
     * Mode of the environment directory. Not decoration: inside lies `core/config/config.inc.php`
     * with the database password, and the directory NAME ITSELF is a fingerprint with that same
     * password hashed into it (FR-ENV-4). Without 0700 the move out of a world-accessible
     * temporary directory into `~/.cache` buys nothing: on many systems `~/.cache` is open for
     * reading and listing.
     */
    private const DIRECTORY_MODE = 0o700;

    /**
     * Suffix of the directory a replacement core is delivered into before it takes the place of the
     * current environment ({@see self::prepareStaging()}).
     *
     * A sibling and not a subdirectory of the environment: the environment is removed whole, and a
     * staging area inside it would be removed with it. A sibling also keeps the move a `rename()`
     * within one filesystem instead of a copy.
     *
     * The price is named rather than hidden: while the delivery is running, the old environment and
     * the new core occupy the disk at the same time. Overwriting in place costs half the space and
     * the environment on every failed download; the package pays the space.
     */
    private const STAGING_SUFFIX = '.new';

    /**
     * "Outsiders" in POSIX permission terms: group and others, each with its own read and
     * traversal bits. Each principal is checked along its own chain —
     * {@see self::exposedDirectories()}.
     *
     * There is deliberately no argument here in favour of splitting them: the previous one ("a
     * combined mask would declare 0750 an exposure, which it is not for others") is refuted by
     * this very code — on 0750 the guard does declare an exposure, through the "group" channel —
     * and has been removed. Nobody holds a measurement showing that checking the principals apart
     * is better than a combined mask; NOT MEASURED.
     *
     * A known over-caution, also not measured: any group bit counts as an outsider, even when the
     * group contains nobody but the owner.
     *
     * @var array<string, array{read: int, exec: int}>
     */
    private const PRINCIPALS = [
        'group' => ['read' => 0o040, 'exec' => 0o010],
        'others' => ['read' => 0o004, 'exec' => 0o001],
    ];

    /**
     * @param bool $nameIsFingerprint whether the package chose the path itself. Only then is the
     *                               directory NAME a fingerprint, and only then does an outsider
     *                               reading it mean anything: a name given by
     *                               `MODX_TESTBENCH_WORKSPACE` was invented by the consumer, and
     *                               there is nothing in it to leak.
     */
    private function __construct(private string $path, private bool $nameIsFingerprint)
    {
    }

    public static function forConfig(TestbenchConfig $config): self
    {
        $nameIsFingerprint = $config->workspaceDir === null;
        $path = $config->workspaceDir ?? self::defaultLocation($config);

        $normalized = rtrim($path, '/');

        // rtrim('/', '/') yields an empty string: from there the whole package would work with
        // paths relative to the current directory, and `destroy()` would try to delete the
        // filesystem root.
        if ($normalized === '') {
            // A type of its own, not a plain TestbenchException: the message names the
            // directory to use instead, and blind masking turned that recommendation into
            // `(/tmp/modx-***, for example)` under the package's own password.
            // See {@see WorkspaceLocationException}.
            throw WorkspaceLocationException::filesystemRoot($path);
        }

        return new self($normalized, $nameIsFingerprint);
    }

    /**
     * The default environment directory lives under the user's private directory.
     *
     * The scheme is taken from the release cache ({@see TestbenchConfig::fromEnvironment()})
     * verbatim — `XDG_CACHE_HOME`, otherwise `$HOME/.cache` — rather than invented anew: a second
     * way of choosing the base directory inside one package would diverge from the first at the
     * very first edit.
     *
     * `$config->cacheDir` is deliberately NOT used, even though it usually yields the same base
     * directory: in CI `MODX_TESTBENCH_CACHE_DIR` is pointed at a directory uploaded by
     * `actions/cache` (`.github/workflows/tests.yml`), and the environment together with
     * `config.inc.php` would travel into a world-readable cache artifact.
     *
     * **Falling back to `sys_get_temp_dir()` is NOT in itself an exposure, and that is measured.**
     * An earlier revision warned here unconditionally, claiming that the directory name "can be
     * read by anyone". A check on Linux in `/tmp` (1777) refuted the claim:
     * {@see self::ensureExists()} creates the whole path segment with `mkdir(0700, recursive)`, so
     * `su nobody -c "ls /tmp/modx-testbench"` answers `Permission denied`. On macOS
     * `sys_get_temp_dir()` is private outright (`/var/folders/…/T`, `drwx------`). The price of the
     * false claim was not a paper one: the `E_USER_WARNING` from here reached the STDERR of PHPUnit
     * child processes, and the run `env -u HOME -u XDG_CACHE_HOME … --testsuite unit` gave
     * `Errors: 8` for everyone without `HOME`.
     *
     * Hence there is no warning here any more: the loudness moved to where there is a FACT —
     * {@see self::exposedDirectories()} and {@see self::announceExposedName()}.
     */
    private static function defaultLocation(TestbenchConfig $config): string
    {
        $base = Env::get('XDG_CACHE_HOME');

        if ($base === null) {
            $home = Env::get('HOME');
            $base = $home === null ? null : $home . '/.cache';
        }

        return rtrim($base ?? sys_get_temp_dir(), '/') . self::DEFAULT_SUFFIX . $config->fingerprint();
    }

    /**
     * Directories whose mode lets an outsider read the NAME of the environment directory. An empty
     * list means they cannot.
     *
     * The check is LIVE, like {@see self::exposedSecretFiles()}: we ask the filesystem, not our
     * memory of which branch the default took. Substituting a branch for the fact was exactly the
     * defect of the first attempt — the warning fired on the sign "the fallback was taken", even
     * though the fallback creates no exposure.
     *
     * What "can read" means precisely. The name lies INSIDE the parent directory, so an outsider
     * needs (1) the READ permission on the parent itself and (2) the TRAVERSAL permission on every
     * directory above it. Traversal on the parent itself is not needed: it opens `stat` on the
     * entries inside (that is, `ls -l`), while listing the names goes through `r` alone —
     * measured, mode 0744 hands the name to an outsider. One outsider is enough — group or
     * others — and the permissions of the two are examined apart (see {@see self::PRINCIPALS}). A
     * break in any link above closes the exposure entirely: that is exactly why `/tmp` with mode
     * 1777 opens nothing by itself as long as `modx-testbench` beneath it has 0700.
     *
     * A single directory is returned — the parent: it is the only one whose mode settles the
     * question (if the parent is not readable, neither is the name, whatever the permissions
     * above).
     *
     * A directory given by `MODX_TESTBENCH_WORKSPACE` is not subject to the check: its name was
     * invented by the consumer, there is no fingerprint in it.
     *
     * @return list<string>
     */
    public function exposedDirectories(): array
    {
        if (!$this->nameIsFingerprint) {
            return [];
        }

        $parent = dirname($this->path);

        // The same argument as in exposedSecretFiles(): the `stat` cache never learns about
        // somebody else's `chmod`, and the mode of the parent directory is changed by exactly
        // somebody else.
        clearstatcache();

        $mode = @fileperms($parent);

        // The directory does not exist yet — there is nothing to read in it. The package will
        // create it itself, and then it will be 0700.
        if ($mode === false) {
            return [];
        }

        foreach (self::PRINCIPALS as $bits) {
            // From the parent ITSELF only the read bit is needed. The traversal bit on it opens
            // `stat` on the entries inside (that is, `ls -l`), while listing the names — which is
            // what gives away the fingerprint — goes through `r` alone. An earlier revision
            // required both and therefore stayed silent on modes such as 0744 and 0704, where an
            // outsider does read the name; measured in `php:8.4-cli` with a real outside user.
            if (($mode & $bits['read']) === 0) {
                continue;
            }

            if ($this->isTraversableUpTo(dirname($parent), $bits['exec'])) {
                return [$parent];
            }
        }

        return [];
    }

    /**
     * Whether the directory and everything above it is traversable for an outsider holding the
     * `$exec` bit.
     *
     * The walk goes up to the root, where `dirname()` stops changing the path. A directory that
     * does not exist counts as non-traversable: you cannot pass through what is not there.
     */
    private function isTraversableUpTo(string $directory, int $exec): bool
    {
        while (true) {
            $mode = @fileperms($directory);

            if ($mode === false || ($mode & $exec) === 0) {
                return false;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return true;
            }

            $directory = $parent;
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function rootPath(): string
    {
        return $this->path . '/';
    }

    public function corePath(): string
    {
        return $this->path . '/core/';
    }

    public function setupPath(): string
    {
        return $this->path . '/setup/';
    }

    public function indexFile(): string
    {
        return $this->path . '/index.php';
    }

    public function configFile(): string
    {
        return $this->path . '/core/config/config.inc.php';
    }

    /**
     * Environment files that hold the database password and are accessible to somebody other than
     * the owner.
     *
     * The check is LIVE (`stat` against the facts), not a memory of the install. A remembered
     * by-product of the install would answer a different question — "did tightening the permissions
     * succeed BACK THEN" — and would stay silent exactly where the answer matters most: when an
     * already prepared environment is reused, no install runs at all, and the file with the
     * password does not go anywhere.
     *
     * The criterion is any access for group or others (`0o077`), not reading alone: write
     * permission on the core configuration file is no better than read permission. A missing file
     * is not a finding: `setup/config.xml` is removed after a successful install, and that is
     * normal.
     *
     * `clearstatcache()` is mandatory here, and that is measured rather than assumed (PHP 8.4.8):
     * the `stat` cache does not expire on its own and never learns about somebody else's `chmod`,
     * so a second call in the same process would answer with the mode taken BEFORE the change. The
     * permissions are changed by exactly ANOTHER process: the MODX install runs as a child, and the
     * working directory lives between runs. It is easy to get this wrong — `exec()` flushes the
     * cache by itself, and a test that changed the permissions through it would go green without
     * the flush too. The property is held by
     * {@see \ModxKit\Testbench\Tests\Unit\Environment\WorkspaceTest} —
     * `testAnswersAboutTheCurrentModeEvenAfterAnExternalChange()` changes the permissions from a
     * process spawned before the first call, and in the window between the calls does nothing that
     * would flush the cache on behalf of the code under test.
     *
     * @return list<string>
     */
    public function exposedSecretFiles(): array
    {
        $exposed = [];

        foreach ([$this->configFile(), $this->setupPath() . 'config.xml'] as $file) {
            // Flush before EVERY file. That a stat of a neighbouring path evicts the previous
            // answer by itself is a property of the cache's internals, not a promise of the
            // documentation.
            clearstatcache();

            if (!is_file($file)) {
                continue;
            }

            // `fileperms()` reads the same `stat` cache that `is_file()` has just filled: it does
            // not go down to the filesystem, and there is nowhere for a `false` to come from here.
            if ((fileperms($file) & 0o077) !== 0) {
                $exposed[] = $file;
            }
        }

        return $exposed;
    }

    public function lockPath(): string
    {
        return $this->path . '/testbench.lock.json';
    }

    public function snapshotPath(): string
    {
        return $this->path . '/testbench-baseline.sql';
    }

    public function ownershipMarkerPath(): string
    {
        return $this->path . '/' . self::OWNERSHIP_MARKER;
    }

    /**
     * @internal
     */
    public function ensureExists(): void
    {
        $this->createDirectory();

        // Only OUR OWN directory can be tightened; the package does not touch the parent (it is
        // shared with the release cache, and changing somebody else's permissions is a side effect
        // outside our own environment, NFR-4). So what remains is to name it — loudly and by the
        // facts.
        $this->announceExposedName();

        $this->claimOwnership();
    }

    /**
     * Where a replacement core is delivered before it replaces the current environment.
     */
    public function stagingPath(): string
    {
        return $this->path . self::STAGING_SUFFIX;
    }

    /**
     * An empty staging directory, owned by the package and ready to receive the core.
     *
     * The exposure of the parent is NOT announced a second time here: the staging directory is a
     * sibling of the environment, so its parent is the same directory, and
     * {@see self::ensureExists()} names it once per run for the environment itself.
     *
     * @internal
     */
    public function prepareStaging(): self
    {
        $staging = new self($this->stagingPath(), $this->nameIsFingerprint);

        // Leftovers of an interrupted attempt — and `destroy()` rather than a blind removal on
        // purpose: `<workspace>.new` can perfectly well be a directory of the consumer's own, and
        // the ownership guard that protects the environment protects it on exactly the same terms.
        $staging->destroy();

        $staging->createDirectory();
        $staging->claimOwnership();

        return $staging;
    }

    /**
     * Moves everything the provider delivered into the environment directory and removes the
     * staging directory.
     *
     * The move is entry by entry rather than a single `rename()` of the whole directory: the
     * environment directory has already been created with 0700 and marked as ours, and renaming
     * over it would replace both the mode and the marker with whatever the staging directory
     * carries.
     *
     * @internal
     */
    public function adoptStagedCore(self $staging): void
    {
        foreach (new FilesystemIterator($staging->path, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->getPathname() === $staging->ownershipMarkerPath()) {
                continue;
            }

            $target = $this->path . '/' . $entry->getFilename();

            if (!rename($entry->getPathname(), $target)) {
                throw new TestbenchException(sprintf(
                    'Failed to move %s of the delivered core into the test environment directory %s. '
                    . 'The core has been downloaded and is left in %s; check the write permissions '
                    . 'on the environment directory.',
                    $entry->getFilename(),
                    $this->path,
                    $staging->path
                ));
            }
        }

        $staging->destroy();
    }

    private function createDirectory(): void
    {
        // The mode is passed to `mkdir()` rather than set afterwards: on recursive creation ALL
        // created directories of the segment get it, and any one of them grants the right to list.
        //
        // The umask cannot hand anything out of 0700 to an outsider — it can only clear bits — so
        // for SECURITY it is harmless. It is not harmless in general, though, and that is measured
        // (`php:8.3-cli`): under `umask(0500)` the same call yields 0200 along the whole chain, and
        // the directory becomes unusable. What exactly breaks is measured on `php:8.4-cli`: under
        // an unprivileged user `mkdir()` itself fails ("Permission denied") and the exception below
        // is thrown at once; under root the chain is created and `ensureExists()` passes silently,
        // leaving 0200. That is, the loudness here is a property of the process permissions, not of
        // the code. The umask is therefore not handled but named.
        if (!is_dir($this->path) && !mkdir($this->path, self::DIRECTORY_MODE, true) && !is_dir($this->path)) {
            throw new TestbenchException(
                "Failed to create the test environment directory: {$this->path}. Check write permissions."
            );
        }

        $this->restrictDirectoryMode();
    }

    /**
     * A name exposure the package has no right to fix must be named.
     *
     * The channel is the same `E_USER_WARNING` as in {@see FilePermissions}: refusing here is not
     * an option (the environment works, its name is merely visible), and an exception would trade a
     * name leak for a broken package. The visibility of `E_USER_WARNING` is decided by the
     * consumer's ini, so the channel is not the only one: the same list is returned by
     * {@see self::exposedDirectories()}, and the `bin/modx-testbench install` warning is built on
     * it.
     *
     * The limitation that a warning raised during bootstrap lands in the STDERR of the PHPUnit
     * child process applies here — `bootstrap.php` does reach `ensureExists()`. But that price is
     * now paid only for a MEASURED exposure: the run
     * `env -u HOME -u XDG_CACHE_HOME … --testsuite unit` gives `Errors: 0` (it used to be
     * `Errors: 8`), because the chain the package creates is private and there is nothing to talk
     * about.
     */
    private function announceExposedName(): void
    {
        $exposed = $this->exposedDirectories();

        if ($exposed === []) {
            return;
        }

        trigger_error(
            sprintf(
                'modx-testbench: directory %s is open to outsiders for reading and traversal, and '
                . 'the name of the test environment directory (%s) is a configuration fingerprint '
                . 'with the database password hashed into it. Tighten the permissions on this '
                . 'directory (chmod 700) or set MODX_TESTBENCH_WORKSPACE to a directory only you can '
                . 'access.',
                implode(', ', $exposed),
                basename($this->path)
            ),
            E_USER_WARNING
        );
    }

    /**
     * Tightening the permissions of an ALREADY EXISTING environment directory.
     *
     * The reachable case is a manually set `MODX_TESTBENCH_WORKSPACE`: there the directory is
     * created by the consumer, and created with whatever permissions. Default directories do not
     * fall into this branch: the package creates them itself and straight away with 0700, while
     * environments of the FORMER layout live at a different path the package no longer touches at
     * all.
     *
     * The policy is the package-wide one: tightening permissions is a protective measure, not a
     * success criterion ({@see FilePermissions}). A failing `chmod` does not fail the run, but does
     * not pass unnoticed either.
     */
    private function restrictDirectoryMode(): void
    {
        clearstatcache(true, $this->path);

        $mode = fileperms($this->path);

        // `false` means the directory vanished between the two calls; there is nothing to tighten
        // then, and the real error will be named by the very first install step that reaches into
        // it.
        if ($mode === false || ($mode & 0o077) === 0) {
            return;
        }

        FilePermissions::restrict(
            $this->path,
            self::DIRECTORY_MODE,
            'the test environment directory is named after a configuration fingerprint with the '
            . 'database password hashed into it, and core/config/config.inc.php inside holds the '
            . 'same password.'
        );
    }

    /**
     * Marks the directory as belonging to the package — but only if it really is ours: empty (we
     * have just created it, or it was empty) or already marked. Somebody else's non-empty directory
     * gets no marker, otherwise `destroy()` would wipe it on the very next call.
     */
    private function claimOwnership(): void
    {
        if ($this->belongsToTestbench()) {
            return;
        }

        if (!$this->isEmptyDirectory()) {
            return;
        }

        $this->writeOwnershipMarker();
    }

    private function writeOwnershipMarker(): void
    {
        if (is_file($this->ownershipMarkerPath())) {
            return;
        }

        // The result is checked below, and the package's own message names the same cause more
        // clearly than a raw "Failed to open stream: Permission denied" in front of it.
        $written = @file_put_contents(
            $this->ownershipMarkerPath(),
            "This directory was created by modx-testbench and will be deleted in full when the environment is reinstalled.\n"
        );

        if ($written === false) {
            throw WorkspaceOwnershipException::cannotMark($this->path, self::OWNERSHIP_MARKER);
        }
    }

    private function belongsToTestbench(): bool
    {
        return is_file($this->ownershipMarkerPath()) || is_file($this->lockPath());
    }

    private function isEmptyDirectory(): bool
    {
        return !(new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS))->valid();
    }

    /**
     * Whether any files are left in the directory after the cleanup. The ownership marker does not
     * count as a leftover: `destroy()` removes it last and only on an empty directory.
     */
    public function hasLeftovers(): bool
    {
        return is_dir($this->path) && !$this->isEmptyExceptOwnershipMarker();
    }

    /**
     * Whether anything is left in the directory other than the ownership marker. If something is,
     * the cleanup was not carried through and the marker must not be removed.
     */
    private function isEmptyExceptOwnershipMarker(): bool
    {
        foreach (new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->getPathname() !== $this->ownershipMarkerPath()) {
                return false;
            }
        }

        return true;
    }

    public function readLock(): ?LockFile
    {
        if (!is_file($this->lockPath())) {
            return null;
        }

        $raw = file_get_contents($this->lockPath());

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? LockFile::fromArray($data) : null;
    }

    /**
     * @internal
     */
    public function writeLock(LockFile $lock): void
    {
        $this->ensureExists();

        file_put_contents(
            $this->lockPath(),
            json_encode($lock->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * The install revision is checked on a par with the fingerprint: an environment deployed by an
     * earlier revision of the package does not count as installed and is honestly reinstalled —
     * see {@see LockFile::CURRENT_REVISION}.
     */
    public function isInstalledWith(string $fingerprint): bool
    {
        $lock = $this->readLock();

        return $lock instanceof LockFile
            && $lock->fingerprint === $fingerprint
            && $lock->installRevision === LockFile::CURRENT_REVISION
            && is_file($this->configFile())
            && is_file($this->indexFile());
    }

    /**
     * Removes the environment directory in full — but only if the directory belongs to the
     * package.
     *
     * `prepare()` calls `destroy()` on any fingerprint mismatch, including the very first run.
     * Without this check a typo such as `MODX_TESTBENCH_WORKSPACE=$PWD` would destroy the project's
     * working directory with a single `install` command, without confirmation and without
     * `--force`.
     *
     * @internal
     */
    public function destroy(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        if (!$this->belongsToTestbench() && !$this->isEmptyDirectory()) {
            throw WorkspaceOwnershipException::notOurs($this->path, self::OWNERSHIP_MARKER);
        }

        // Re-claim the directory BEFORE the first removal. The cleanup is recursive, and
        // `testbench.lock.json` disappears as one of the first entries: a process killed in that
        // window (Ctrl+C, SIGKILL, CI timeout) used to leave the directory non-empty and without a
        // single sign of ownership — after which the guard above refused FOREVER, and the only cure
        // was `rm -rf`. A revision 0 directory has no marker at all, so the window covered the
        // whole cleanup starting from the second entry — and it is exactly those environments that
        // the install revision drives through `destroy()` en masse on the first run after a package
        // upgrade.
        //
        // We do not rely on the traversal order: nothing guarantees it. The marker is written first
        // and removed last, so the directory is recognisably ours at any moment of the cleanup.
        if (!$this->isEmptyDirectory()) {
            $this->writeOwnershipMarker();
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->getPathname() === $this->ownershipMarkerPath()) {
                continue;
            }

            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        // An incomplete cleanup (no permissions on part of the tree, a file in use) does not
        // remove the marker: the directory stays ours, and a repeated `destroy()` finishes what was
        // started.
        if (!$this->isEmptyExceptOwnershipMarker()) {
            return;
        }

        if (is_file($this->ownershipMarkerPath())) {
            unlink($this->ownershipMarkerPath());
        }

        rmdir($this->path);
    }
}

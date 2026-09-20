<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

use ModxKit\Testbench\Exception\ConcurrentRunException;
use ModxKit\Testbench\Support\Env;

/**
 * The exclusive hold on one test environment for the lifetime of the run (FR-ENV-9).
 *
 * **`flock()` rather than a pid written down and compared.** Liveness is then the operating
 * system's answer, not ours: the lock goes away with the holder whatever killed it — `kill -9`, a
 * PHP fatal, a closed terminal — and there is no stale record to reason about. A pid compared by
 * hand has to answer "is it still alive", and pids are reissued; the hole is already written down
 * in `tests/Support/RunScopedDatabaseName.php`, where the package's own suite works around it. The
 * pid is written into the file all the same, but only to give the refusal something to name.
 *
 * **Beside the environment directory, never inside it.** A reinstallation deletes the environment
 * directory in full ({@see TestbenchKernel::prepare()} calls {@see Workspace::destroy()}), and a
 * file unlinked under a descriptor that holds it stops being shared: the next process creates a new
 * inode at the same path and both "hold" a lock of their own. So the file lives in `locks/`, a
 * sibling of `workspaces/`, and nothing deletes it.
 *
 * **Keyed by the environment fingerprint**, which already carries host, port, database name, user,
 * password and table prefix. One lock therefore means one environment AND one database, without a
 * second notion to keep in step. What this does NOT cover: two runs pointed at one
 * `MODX_TESTBENCH_WORKSPACE` by hand while their databases differ — different fingerprints, two
 * locks, and a fight over the directory. Covering that as well would take a second lock keyed the
 * other way, and the configuration is reached only on purpose.
 *
 * **Not taken is not a failure.** Where the private cache directory cannot be created the guard
 * simply does not exist for that run: refusing to test because a protective measure is unavailable
 * would break runs that work today. That is the same ruling FR-ENV-8 already makes about the 0700
 * it cannot always set.
 *
 * **A child of the holder is not a second run.** The package spawns such children itself — the
 * build script of a transport package ({@see \ModxKit\Testbench\Package\TransportInstaller}) boots
 * the very same environment in a subprocess, and so does the deprecation probe of the suite —
 * and refusing them would break a documented feature instead of preventing a collision. Measured,
 * not foreseen: the first build of this guard turned three of the package's own tests red, one of
 * them the transport package the DX guide sells.
 *
 * They are told apart by a TOKEN, not by a pid or by ancestry: pids are reissued and `getppid()`
 * chains are not portable. The holder writes a random token into the first line of the lock file
 * and publishes the same token with `putenv()`, from where every subprocess inherits it along with
 * the rest of the environment. Whoever comes with a matching token belongs to the run that holds
 * the lock; a stranger has no way to know it. A token inherited from somewhere else while the lock
 * is FREE changes nothing — the lock is simply taken, and a fresh token replaces the stale one.
 *
 * The hold ends in one of three ways, and all three are the same event: {@see self::release()} called
 * by hand, the holder being dropped (its destructor calls `release()` — which is what
 * {@see TestbenchKernel::reset()} amounts to), or the process ending, where the operating system
 * closes the descriptor whether or not any PHP code got to run.
 *
 * @internal
 */
final class RunLock
{
    /**
     * The mode of `locks/`. The parent `<base>/modx-testbench` is shared with the release cache and
     * on a machine where the cache created it first it is 0755 — open to listing. The fingerprint
     * in a file name is exactly what FR-ENV-8 keeps closed, so this segment, created by the package
     * itself, carries the 0700.
     */
    private const DIRECTORY_MODE = 0700;

    /**
     * The handshake variable. NOT part of the configuration and not for the consumer to set: it is
     * written by the holder and read by its children, which is why it is absent from the table of
     * `MODX_TESTBENCH_*` variables in the README and from
     * {@see TestbenchConfig::fromEnvironment()}.
     */
    private const TOKEN_VARIABLE = 'MODX_TESTBENCH_RUN_TOKEN';

    /**
     * How long a lock file nobody holds is kept before it is swept.
     *
     * A file is worth nothing once its run is over — the next `fopen()` recreates it — so the
     * threshold buys nothing but calm: an environment used at least weekly keeps its file, because
     * every `acquire()` rewrites the record and with it the mtime. Without a sweep the directory
     * grows without bound wherever configurations are one-off: 91 files in a single day on the
     * machine this package is developed on, one per throwaway fingerprint of its own suite.
     */
    private const STALE_AFTER_SECONDS = 7 * 86400;

    /**
     * How many times taking the lock is retried when the file turns out to have been replaced
     * under us. See {@see self::stillTheSameFile()}; three is not a tuned number, it is "more than
     * the one collision the sweep can cause".
     */
    private const ATTEMPTS = 3;

    /** @param resource|null $handle the hold itself; closing it releases the lock */
    private function __construct(private $handle)
    {
    }

    /**
     * Lets the environment go.
     *
     * Idempotent, because the destructor calls it as well and a double `fclose()` on the same
     * resource is a warning for nothing. Deliberately a method and not only a destructor: a test
     * that has to hand the environment to the next process wants the release to happen at a
     * point it chose, not whenever the object is collected.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        // `fclose()` releases the lock by itself — an explicit `flock(LOCK_UN)` would only widen
        // the window between the two calls in which the environment is free while this run still
        // believes it holds it.
        fclose($this->handle);

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }

    public static function pathFor(TestbenchConfig $config): string
    {
        return PrivateCacheDirectory::path() . '/locks/' . $config->fingerprint() . '.lock';
    }

    public static function acquire(TestbenchConfig $config): ?self
    {
        if ($config->allowConcurrent) {
            return null;
        }

        $path = self::pathFor($config);
        $directory = dirname($path);

        // The mode goes to `mkdir()` rather than a `chmod()` afterwards: on a recursive creation
        // every segment gets it, and there is no window in between when the directory is open.
        if (!is_dir($directory) && !mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            return null;
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            // 'c' and not 'w': 'w' truncates the file on open, and the holder's record would be
            // wiped by the very process that is about to be refused.
            $handle = @fopen($path, 'c');

            if ($handle === false) {
                return null;
            }

            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                $held = self::read($path);
                fclose($handle);

                // Our own child: it inherited the token together with the rest of the environment.
                // It gets no lock object of its own — the lock is already held by the run it
                // belongs to, and holding it twice would mean releasing it twice.
                if ($held['token'] !== '' && $held['token'] === Env::get(self::TOKEN_VARIABLE)) {
                    return null;
                }

                throw ConcurrentRunException::heldBy($held['record'], $config->database->name, $path);
            }

            if (!self::stillTheSameFile($handle, $path)) {
                fclose($handle);

                continue;
            }

            // The token belongs to the RUN, not to this one lock: a run that takes a second
            // environment keeps the token its children already carry. Minting a fresh one here
            // disowned them — they went on presenting the old token to the file of the FIRST lock,
            // which now held a different one, and were refused entry into the environment their
            // own parent was holding.
            $token = Env::get(self::TOKEN_VARIABLE) ?? bin2hex(random_bytes(8));

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $token . "\n" . self::record($config));
            fflush($handle);

            self::publish($token);

            // With our own lock in hand: the sweep skips whatever is held, and ours is held.
            self::sweepStale($directory, $path);

            return new self($handle);
        }

        // Three collisions in a row is not a situation to keep fighting: proceed unguarded rather
        // than fail a test run over a protective measure (FR-ENV-9).
        return null;
    }

    /**
     * Into all three places, and not one of them is superfluous — measured, not assumed.
     *
     * `putenv()` is what a child spawned by `proc_open()`, `exec()` or `passthru()` inherits: those
     * take the real process environment. Symfony's `Process`, which the package and its suite use,
     * does NOT: `Process::getDefaultEnv()` takes `getenv()` and intersects it with `$_SERVER`
     * (`vendor/symfony/process/Process.php:1713`), so a variable that exists only in the real
     * environment is filtered out on its way to the child. The first build of this handshake
     * published the token with `putenv()` alone and the test "a child of this run is let through"
     * went red with `refused`.
     *
     * `$_ENV` for symmetry with the pair `Env::get()` reads, so that the value a child sees is the
     * value this process would read about itself.
     */
    private static function publish(string $token): void
    {
        putenv(self::TOKEN_VARIABLE . '=' . $token);
        $_SERVER[self::TOKEN_VARIABLE] = $token;
        $_ENV[self::TOKEN_VARIABLE] = $token;
    }

    /**
     * Is the descriptor we locked still the file that path names?
     *
     * The sweep below deletes files, and deletion opens a window: a process may have opened the
     * file a moment before the unlink, and would then take `flock()` on an inode that no longer
     * has a name. The next process, finding no file, would create one and lock that — two holders
     * of one environment, which is the whole thing this class exists to prevent. Comparing the
     * inode we hold with the inode the path resolves to closes the window: a mismatch means the
     * file was replaced under us and the lock we are holding guards nothing.
     *
     * **The mismatch branch is NOT MEASURED.** Staging it takes a real race between a sweep and an
     * acquisition; nothing in a single-threaded test can produce it, and a test that spawned
     * processes and hoped for the interleaving would be flaky rather than a check. What IS measured
     * is the agreement path — every `acquire()` in the suite goes through this method.
     *
     * On Windows `fstat()` reports `ino` as 0, so the comparison degenerates to `0 === 0` and the
     * check simply does not fire there. That is a degradation, not a break: the sweep is the only
     * thing that creates the window, and it is as rare as the threshold above.
     *
     * @param resource $handle
     */
    private static function stillTheSameFile($handle, string $path): bool
    {
        $held = fstat($handle);
        $named = @stat($path);

        // Either question going unanswered is treated as "not the same file": the caller then
        // retries and, if it keeps failing, proceeds unguarded. Assuming agreement on the strength
        // of a failed `stat()` would be assuming exactly what this method exists to establish.
        if ($held === false || $named === false) {
            return false;
        }

        return $held['ino'] === $named['ino'] && $held['dev'] === $named['dev'];
    }

    /**
     * Deletes lock files that nobody holds and nobody has touched for {@see self::STALE_AFTER_SECONDS}.
     *
     * Two conditions, and both are load-bearing. Age alone would delete the file of a run that has
     * simply been going for a long time. "Nobody holds it" alone would delete the file of every
     * environment that merely happens to be idle this second, and churn the directory for nothing.
     *
     * "Nobody holds it" is asked of the operating system rather than of the record inside the file:
     * a pid written down says nothing about whether that process is alive, and pids are reissued.
     * Opening with `r+` and not `c` matters — `c` would recreate a file another sweeper has just
     * deleted.
     *
     * Failure is silence by design. A directory somebody else owns, a file without write
     * permission, a race with another sweeper: none of it is a reason to fail a test run, and the
     * only cost of not sweeping is a hundred bytes.
     */
    private static function sweepStale(string $directory, string $ourPath): void
    {
        $deadline = time() - self::STALE_AFTER_SECONDS;

        foreach (glob($directory . '/*.lock') ?: [] as $candidate) {
            if ($candidate === $ourPath) {
                continue;
            }

            $modified = @filemtime($candidate);

            if ($modified === false || $modified > $deadline) {
                continue;
            }

            $handle = @fopen($candidate, 'r+');

            if ($handle === false) {
                continue;
            }

            // Somebody holds it — old or not, it is a live run.
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                // Under our own lock, so that a process which opened this file before us cannot
                // believe it took the environment: its {@see self::stillTheSameFile()} will find
                // the name gone and retry.
                @unlink($candidate);
            }

            fclose($handle);
        }
    }

    private static function record(TestbenchConfig $config): string
    {
        return sprintf(
            'pid %d, started %s, database "%s"',
            getmypid(),
            date('c'),
            $config->database->name
        );
    }

    /**
     * The holder writes its record after taking the lock, so a run refused in that very window sees
     * an empty file. Saying so is better than printing nothing where a pid was promised — and an
     * empty token matches nobody, so the window cannot let a stranger through either.
     *
     * @return array{token: string, record: string}
     */
    private static function read(string $path): array
    {
        $contents = @file_get_contents($path);

        if (!is_string($contents) || trim($contents) === '') {
            return [
                'token' => '',
                'record' => 'the record has not been written yet (the run has only just started)',
            ];
        }

        $lines = explode("\n", $contents, 2);

        return [
            'token' => trim($lines[0]),
            'record' => trim($lines[1] ?? ''),
        ];
    }
}

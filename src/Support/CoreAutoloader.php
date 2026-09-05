<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Support;

use MODX\Revolution\modX;
use ModxKit\Testbench\Exception\TestbenchException;

/**
 * @internal
 */
final class CoreAutoloader
{
    private static bool $registered = false;

    /**
     * The path (without a trailing `/`) the last SUCCESSFUL `register()` in this process was called
     * with. `null` if `register()` has never succeeded — including when `isRegistered()` is true
     * only because of `class_exists(modX::class, false)` (a core loaded not through this class, for
     * example directly through the distribution's `require_once`).
     *
     * `KernelBootstrapper::assertSingleCorePerProcess()` compares against this value in order to
     * diagnose a conflict with a core loaded through `CoreAutoloader` (level 1), and not only
     * through the `MODX_CORE_PATH` constant (which is defined exclusively by level 2's
     * `config.core.php`).
     */
    private static ?string $registeredPath = null;

    /**
     * Includes the core autoloader so that the MODX classes are available without an installed
     * CMS.
     */
    public static function register(string $corePath): void
    {
        $resolvedCorePath = rtrim($corePath, '/');

        if (self::isRegistered()) {
            self::assertSameCore($resolvedCorePath);

            return;
        }

        $autoload = $resolvedCorePath . '/vendor/autoload.php';

        // The "next action" here is verified by execution rather than written from memory — an
        // earlier revision advised `bin/modx-testbench install`, and that advice did NOT fix the
        // failure (reproduced: MODX_TESTBENCH_CORE_PATH=<not a distribution> + `install` → the same
        // error, because `install` prepares ITS OWN working directory and does not touch the
        // environment variable). The same class of defect was already fixed once in UnitTestCase; do
        // not repeat it — before changing the text, reproduce both reachable scenarios:
        //   1) `UnitTestCase::setUp()` with MODX_TESTBENCH_CORE_PATH pointing away from a
        //      distribution;
        //   2) `bootstrap.php` after a SUCCESSFUL `prepare()` with an incomplete working directory —
        //      and that is strictly the `CoreAutoloader::register($workspace->corePath())` line at
        //      the end of `bootstrap.php`, NOT the `catch` branch above it: that one calls
        //      `registerIfAvailable()`, which on a missing file silently returns `false` (see below)
        //      and never passes control here at all. Reproducing scenario 2 through the `catch` is
        //      pointless — this `throw` is unreachable there by construction, and whoever edits it
        //      will mistakenly take it for dead.
        if (!is_file($autoload)) {
            throw new TestbenchException(
                "Core autoloader not found: {$autoload}. If this path comes from the "
                . 'MODX_TESTBENCH_CORE_PATH variable, it must point at the root of an UNPACKED '
                . 'MODX 3 distribution (a directory that has index.php, setup/ and '
                . 'core/vendor/autoload.php); `bin/modx-testbench install` does not fix this path, '
                . 'it prepares its own working directory and does not change the environment '
                . 'variable. If the path leads into the testbench working directory instead, that '
                . 'directory is incomplete — rebuild it: `bin/modx-testbench install --force` (a '
                . 'plain `install` on a directory already marked installed reinstalls nothing).'
            );
        }

        require_once $autoload;
        self::$registered = true;
        self::$registeredPath = $resolvedCorePath;
    }

    /**
     * A second registration with a DIFFERENT path used to be a quiet no-op: the caller got
     * "success" while the core from the first directory stayed in the process — from then on the
     * test worked with one distribution believing it worked with another. Level 2 is protected from
     * this by {@see \ModxKit\Testbench\Bootstrap\KernelBootstrapper::assertSingleCorePerProcess()},
     * but that is called only when the core is booted, whereas a direct `register()` call (level 1,
     * `Unit\UnitTestCase::setUp()` via `MODX_TESTBENCH_CORE_PATH`) went past it.
     *
     * The comparison can be made only against a path WE registered with: `isRegistered()` is true
     * when the `modX` class entered the process some other way too (`registeredPath() === null`) —
     * nothing is known here about its origin, and staying silent in that case remains correct.
     */
    private static function assertSameCore(string $resolvedCorePath): void
    {
        $registered = self::$registeredPath;

        if ($registered === null || $registered === $resolvedCorePath) {
            return;
        }

        throw new TestbenchException(sprintf(
            "A MODX core from a different directory is already registered in this PHP process:\n"
            . "  registered: %s\n  requested: %s\n"
            . 'Two distributions in one process are incompatible (identically named core classes and '
            . 'a ComposerAutoloaderInit… collision), so the second registration would not have gone '
            . 'through in any case — this simply went unreported before. Run tests that need '
            . 'different core directories in different processes (`#[RunInSeparateProcess]`), or '
            . 'bring MODX_TESTBENCH_CORE_PATH and the testbench working directory to the same '
            . 'distribution.',
            $registered,
            $resolvedCorePath
        ));
    }

    /**
     * The soft variant of `register()` for callers to whom the ABSENCE of a core on disk is a
     * regular rather than an exceptional outcome (the `catch` branch in `bootstrap.php`). It returns
     * `true` if the core classes are available after the call.
     *
     * The knowledge of WHAT exactly makes a core loadable (a `vendor/autoload.php` file inside
     * `core/`) deliberately stays here instead of spreading into `bootstrap.php` or into
     * `Environment\Workspace`: the latter would drag a level 1 check into a level 2 class.
     *
     * A CORRUPTED (as opposed to a missing) autoloader is not muffled here: `is_file()` passes,
     * control goes into `register()`, and the `require_once` error flies out loudly — exactly the
     * behaviour preserved for the main branch.
     */
    public static function registerIfAvailable(string $corePath): bool
    {
        if (self::isRegistered()) {
            return true;
        }

        if (!is_file(rtrim($corePath, '/') . '/vendor/autoload.php')) {
            return false;
        }

        self::register($corePath);

        return true;
    }

    public static function isRegistered(): bool
    {
        return self::$registered || class_exists(modX::class, false);
    }

    /**
     * @see self::$registeredPath
     */
    public static function registeredPath(): ?string
    {
        return self::$registeredPath;
    }
}

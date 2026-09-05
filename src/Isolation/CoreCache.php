<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Isolation;

use FilesystemIterator;
use MODX\Revolution\modX;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The fourth vessel of state — the core's file cache.
 *
 * A transaction and a snapshot bring the database back, but `core/cache/` stays whatever the test
 * made of it. `modX::reloadConfig()` calls `cacheManager->refresh()`, and that regenerates
 * `core/cache/system_settings/config.cache.php` from the DIRTY, not yet rolled back state of the
 * database; at least 20 regular core processors do that, including `System/Settings/Update`,
 * `Context/Update`, `Element/Create|Update|Remove` and `Workspace/Packages/Install` (that is, the
 * whole `TransportInstaller` path). The pollution survives the process boundary: the row is already
 * gone from the database, while `getOption()` in a new PHP process hands back the value of the
 * rolled back test.
 *
 * `logs/` is left alone: the core's own log is written there (FR-BOOT-3), and removing it in the
 * middle of a run would take away the diagnostics exactly when they matter most.
 *
 * @internal
 */
final class CoreCache
{
    /** The cache subdirectories the purge leaves alone. */
    private const PRESERVED = ['logs'];

    public static function purge(modX $modx): void
    {
        $path = rtrim($modx->getCachePath(), '/');

        if ($path === '' || !is_dir($path)) {
            return;
        }

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if (in_array($entry->getFilename(), self::PRESERVED, true)) {
                continue;
            }

            $entry->isDir() && !$entry->isLink()
                ? self::removeDirectory($entry->getPathname())
                : @unlink($entry->getPathname());
        }
    }

    private static function removeDirectory(string $path): void
    {
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
}

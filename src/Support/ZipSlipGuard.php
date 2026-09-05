<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Support;

use ZipArchive;

/**
 * @internal
 */
final class ZipSlipGuard
{
    /**
     * Returns the name of the first archive entry that escapes the extraction directory, or null if
     * the archive is safe.
     */
    public static function findEscapingEntry(ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                return '<unknown>';
            }

            if (self::escapesTargetDirectory($name)) {
                return $name;
            }
        }

        return null;
    }

    private static function escapesTargetDirectory(string $entryName): bool
    {
        $normalized = str_replace('\\', '/', $entryName);

        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:#', $normalized) === 1) {
            return true;
        }

        return in_array('..', explode('/', $normalized), true);
    }
}

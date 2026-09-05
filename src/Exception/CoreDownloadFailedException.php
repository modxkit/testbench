<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

final class CoreDownloadFailedException extends TestbenchException
{
    /**
     * @param array<int, string> $attemptedUrls
     */
    public static function forVersion(string $version, array $attemptedUrls, string $cacheDir, string $reason): self
    {
        return new self(sprintf(
            "Failed to fetch the MODX %s distribution.\nReason: %s\nTried: %s\nRelease cache: %s\n"
            . 'Check network availability and that MODX_TESTBENCH_VERSION is correct; if the cache is '
            . 'suspected to be corrupt, delete the cache directory and run again.',
            $version,
            $reason,
            implode(', ', $attemptedUrls),
            $cacheDir
        ));
    }
}

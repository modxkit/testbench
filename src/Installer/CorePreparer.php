<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Installer;

use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Exception\CoreTransportUnpackException;
use ModxKit\Testbench\Support\ZipSlipGuard;
use ZipArchive;

/**
 * @internal
 */
final class CorePreparer
{
    /**
     * Unpacks core.transport.zip in advance so that the install runs with unpacked=1.
     */
    public function unpackCoreTransport(CoreLocation $core): bool
    {
        $archive = $core->packagesPath() . 'core.transport.zip';

        if (!is_file($archive)) {
            return false;
        }

        if (is_dir($core->packagesPath() . 'core')) {
            return true;
        }

        $zip = new ZipArchive();

        if ($zip->open($archive) !== true) {
            return false;
        }

        $this->guardAgainstZipSlip($zip, $archive);

        $extracted = $zip->extractTo($core->packagesPath());
        $zip->close();

        return $extracted;
    }

    /**
     * Zip slip protection: no entry of the archive may escape the packages/ directory by an
     * absolute path or a ".." segment.
     */
    private function guardAgainstZipSlip(ZipArchive $zip, string $archive): void
    {
        $escapingEntry = ZipSlipGuard::findEscapingEntry($zip);

        if ($escapingEntry !== null) {
            $zip->close();

            throw CoreTransportUnpackException::forArchive(
                $archive,
                sprintf('the archive contains an unsafe path: %s', $escapingEntry)
            );
        }
    }
}

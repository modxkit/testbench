<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

final class CoreTransportUnpackException extends TestbenchException
{
    public static function forArchive(string $archive, string $reason): self
    {
        return new self(sprintf(
            "Failed to safely unpack %s.\nReason: %s\n"
            . 'Check the integrity of core.transport.zip; if the file is suspected to be corrupt or '
            . 'tampered with, delete the core/packages directory and download the core again.',
            $archive,
            $reason
        ));
    }
}

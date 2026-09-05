<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

final class InstallationFailedException extends TestbenchException
{
    /**
     * @param array<int, string> $command
     */
    public static function forCommand(array $command, string $configFile, string $output, string $reason): self
    {
        return new self(sprintf(
            "MODX installation failed: %s\nCommand: %s\nManifest: %s\n--- Installer output ---\n%s\n"
            . 'Check the %s manifest and the installer output above; if the cause is database-related, '
            . 'make sure the DBMS is reachable and that MODX_TESTBENCH_DB_HOST/PORT/USER/PASS point to '
            . 'an account with the CREATE privilege.',
            $reason,
            implode(' ', $command),
            $configFile,
            $output === '' ? '(empty)' : $output,
            $configFile
        ));
    }
}

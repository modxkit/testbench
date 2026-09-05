<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

/**
 * The test's transaction could not be opened: `xPDO::beginTransaction()` returns `false` when it
 * failed to connect to the database (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2475-2477`).
 *
 * A class of its own is needed so as not to confuse this failure with
 * {@see TransactionLostException}: there a transaction existed and was lost, here there was none at
 * all, and what needs fixing is the connection.
 */
final class TransactionNotStartedException extends TestbenchException
{
    public static function create(): self
    {
        return new self(
            'Failed to open the test transaction: xPDO could not connect to the database, so the '
            . 'test would have run without isolation. Check that the MySQL server is reachable and '
            . 'the environment variables MODX_TESTBENCH_DB_HOST, MODX_TESTBENCH_DB_PORT, '
            . 'MODX_TESTBENCH_DB_USER, MODX_TESTBENCH_DB_PASS.'
        );
    }

    /**
     * The service table of the guard marker is unusable.
     *
     * On failure `xPDO::exec()` returns `false` without throwing, and a
     * `CREATE TABLE IF NOT EXISTS` against an existing table with a DIFFERENT schema is not an error
     * at all but a no-op. An unchecked result would mean the marker was not inserted, the detector
     * was silently disarmed, and the test runs without isolation while staying green.
     */
    public static function guardTableUnusable(string $table, string $driverError): self
    {
        return new self(sprintf(
            "Failed to prepare the isolation bookkeeping table \"%s\": %s\n"
            . 'The package creates it itself and keeps the test guard marker in it; it needs exactly one '
            . 'column, marker VARCHAR(64) NOT NULL PRIMARY KEY, and the InnoDB engine. If a table with '
            . 'that name is taken by something else, rename or drop it: '
            . 'DROP TABLE `%s`. Without the marker the isolation-loss detector works at half strength, '
            . 'so the test does not run at all.',
            $table,
            $driverError,
            $table
        ));
    }
}

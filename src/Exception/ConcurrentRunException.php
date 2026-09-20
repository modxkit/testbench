<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

/**
 * Another run already holds this environment.
 *
 * The collision it reports is not exotic: two projects that both leave `MODX_TESTBENCH_DB_NAME` at
 * its default share one database, one table prefix and one environment directory, because the
 * fingerprint holds the DBMS coordinates and nothing that would tell one project from another. The
 * damage is silent — the installer drops every table with the prefix, and the snapshot isolation of
 * `RefreshesDatabase` reloads the dump over somebody else's data in the middle of their test.
 *
 * The message carries the holder's record verbatim rather than a parsed retelling of it: the record
 * is written by the holder and read by whoever is refused, and a format the two sides agree on by
 * accident is a format nobody checked.
 */
final class ConcurrentRunException extends TestbenchException
{
    public static function heldBy(string $record, string $database, string $path): self
    {
        return new self(sprintf(
            'Another run is already using this test environment (database "%s"), and running both '
            . 'at once would let them drop each other\'s tables. The holder: %s. The lock: %s. '
            . 'Two ways out: give this project a database of its own with MODX_TESTBENCH_DB_NAME '
            . '(the environment is then separate as well, and is installed once), or, if several '
            . 'processes over ONE environment are what you meant, set '
            . 'MODX_TESTBENCH_ALLOW_CONCURRENT=1 and keep the runs apart yourself.',
            $database,
            $record,
            $path
        ));
    }
}

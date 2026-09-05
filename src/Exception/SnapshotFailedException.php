<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

use Throwable;

final class SnapshotFailedException extends TestbenchException
{
    public static function because(string $strategy, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                "Database snapshot operation failed (strategy %s): %s\n"
                . 'Check that the DBMS is reachable and that the user may read and write the test database.',
                $strategy,
                $reason
            ),
            0,
            $previous
        );
    }

    /**
     * An unclosed transaction on the core connection holds a metadata lock, and a `DROP TABLE` from
     * the dumper's connection waits for it `lock_wait_timeout` seconds — by default 31,536,000, that
     * is, a year (this is NOT `innodb_lock_wait_timeout`). The run hung dead, and after the process
     * was killed not a single table was left in the database.
     */
    public static function openTransactionOnKernelConnection(): self
    {
        return new self(
            'Restore from the baseline snapshot was stopped: an unclosed transaction was left on the '
            . 'kernel connection at the end of the test. It holds a metadata lock, and cleaning the '
            . 'database from the dumper connection would have waited for its lock_wait_timeout seconds '
            . "(31536000 by default, that is a year) instead of failing.\n"
            . 'The transaction was rolled back and the database restored from the baseline — the run '
            . 'is safe to continue. The cause is in the test itself: under the '
            . 'ModxKit\\Testbench\\Concerns\\RefreshesDatabase trait isolation is given by the snapshot '
            . 'and not by a transaction, so opening one by hand (beginTransaction(), START TRANSACTION, '
            . 'BEGIN) without a matching commit()/rollBack() is not allowed.'
        );
    }
}

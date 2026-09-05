<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Isolation;

use MODX\Revolution\modX;
use ModxKit\Testbench\Database\SnapshotManager;
use ModxKit\Testbench\Exception\SnapshotFailedException;

/**
 * The second line of isolation: the test runs without the safety net of a transaction, and the
 * database is returned to the baseline snapshot afterwards — a restore undoes both DDL and MyISAM
 * tables, which {@see TransactionIsolation} cannot undo.
 *
 * @internal
 */
final readonly class SnapshotIsolation implements IsolationStrategy
{
    public function __construct(private SnapshotManager $snapshots)
    {
    }

    /**
     * The restore happens after the test so as not to pay for it twice, so the only work left here
     * is to make sure there will be something to restore from. An unusable baseline named here costs
     * one test; named in `end()` it arrives after the body of the test and looks like a failure of
     * an entirely different place.
     */
    public function begin(modX $modx): void
    {
        // The core connection's session lives for the whole run and is not rolled back by a
        // snapshot.
        SessionState::reset($modx);

        if ($this->snapshots->exists()) {
            return;
        }

        throw SnapshotFailedException::because('snapshot', sprintf(
            'baseline %s is missing or is not read to the end, so there will be nothing to restore '
            . 'from after the test. Capture it again (vendor/bin/modx-testbench snapshot capture) or '
            . 'recreate the environment (MODX_TESTBENCH_FORCE_INSTALL=1)',
            $this->snapshots->path()
        ));
    }

    public function end(modX $modx): void
    {
        // The transaction is closed BEFORE the dumper is addressed. The dumper works from its own
        // connection, and an unclosed test transaction would block its cleanup of the database on a
        // metadata lock — for a year, not for seconds.
        $leftover = $modx->pdo !== null && $modx->pdo->inTransaction();

        if ($leftover) {
            $modx->pdo?->rollBack();
        }

        $this->snapshots->restore();

        // The snapshot restored the database but not the core's file cache. See {@see CoreCache}.
        CoreCache::purge($modx);

        if ($leftover) {
            // The failure comes after the restore: by that moment the environment is clean, and the
            // rest of the run's tests go against it rather than against the aftermath.
            throw SnapshotFailedException::openTransactionOnKernelConnection();
        }
    }
}

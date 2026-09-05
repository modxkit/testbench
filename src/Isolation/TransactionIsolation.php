<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Isolation;

use MODX\Revolution\modX;
use ModxKit\Testbench\Exception\TransactionLostException;
use ModxKit\Testbench\Exception\TransactionNotStartedException;
use PDO;
use PDOStatement;

/**
 * The default isolation: the test runs inside a transaction that is rolled back afterwards.
 *
 * The isolation-loss detector consists of three independent checks — a single
 * `PDO::inTransaction()` is not enough:
 *
 * 1. `inTransaction()` catches an implicit commit after DDL: the flag is asked of the server itself
 *    (`SERVER_STATUS_IN_TRANS`), not of a client-side counter.
 * 2. The guard marker catches the cases where the flag STAYS raised while the isolation is lost:
 *    `START TRANSACTION`/`BEGIN` through `exec()` (an implicit commit plus a new transaction) and a
 *    `commit()` followed by a `beginTransaction()`. The marker is inserted inside the transaction
 *    and must disappear together with it; a marker that survived the rollback means the transaction
 *    rolled back was no longer the one the test ran in.
 * 3. The engine check catches the MyISAM case: MyISAM tables ignore the transaction, `rollBack()`
 *    runs to no effect, and `inTransaction()` honestly returns `true` all the while.
 *
 * What the detector does not catch and cannot catch: writes made from ANOTHER connection —
 * including from a subprocess (`TransportInstaller::build()`). They do not obey the test's
 * transaction in principle, and seeing them would be possible only by comparing the whole database
 * before and after the test, that is, with a snapshot — a different isolation strategy. For that
 * case the protection lives in {@see \ModxKit\Testbench\Package\TransportInstaller} itself rather
 * than here.
 *
 * @internal
 */
final class TransactionIsolation implements IsolationStrategy
{
    /**
     * The guard marker table. WITHOUT the core prefix deliberately: with it the table would land in
     * the count of install tables (FR-ENV-6) and in the `DatabaseCleaner::purgeInstallation()`
     * cleanup.
     */
    private const GUARD_TABLE = 'testbench_isolation_guard';

    /** Filled in only between `begin()` and `end()`. */
    private ?string $marker = null;

    public function begin(modX $modx): void
    {
        $this->assertTablesAreTransactional($modx);

        // Strictly before the transaction is opened — a `SET autocommit = 1` with an open
        // transaction would commit it.
        SessionState::reset($modx);

        $this->prepareGuardTable($modx);

        // With the database unreachable `xPDO::beginTransaction()` does not throw but silently
        // returns `false` (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2475-2477`). Without this check
        // the test would run with no isolation at all, and `end()` would then name DDL as the
        // cause — that is, send the developer off to fix the wrong thing.
        if ($modx->beginTransaction() === false) {
            throw TransactionNotStartedException::create();
        }

        $marker = bin2hex(random_bytes(16));

        // The result must be checked: on failure `xPDO::exec()` returns `false` without throwing.
        // A marker silently left uninserted would disarm two arms of the detector at once, and the
        // test would run without isolation while staying green.
        if ($modx->exec(sprintf(
            'INSERT INTO `%s` (marker) VALUES (%s)',
            self::GUARD_TABLE,
            $modx->quote($marker)
        )) === false) {
            // We have already opened the transaction — we close it, otherwise the next test would
            // run into "There is already an active transaction" and look for the cause in the wrong
            // place.
            $modx->rollBack();

            throw TransactionNotStartedException::guardTableUnusable(
                self::GUARD_TABLE,
                $this->driverError($modx)
            );
        }

        $this->marker = $marker;
    }

    public function end(modX $modx): void
    {
        $marker = $this->marker;
        $this->marker = null;

        if ($modx->pdo === null || !$modx->pdo->inTransaction()) {
            $this->forgetMarker($modx, $marker);
            // The cache is purged BEFORE the failure, as in `SnapshotIsolation::end()`. A test
            // that lost its isolation is the only one that has GUARANTEEDLY left a dirty
            // `core/cache/` behind: leaving it to the next test would mean adding a second,
            // inexplicable failure to the first.
            CoreCache::purge($modx);

            throw TransactionLostException::create();
        }

        $modx->rollBack();

        if ($marker !== null && $this->markerSurvivedRollback($modx, $marker)) {
            $this->forgetMarker($modx, $marker);
            CoreCache::purge($modx);

            throw TransactionLostException::afterHiddenCommit();
        }

        // The transaction restored the database but not the core's file cache. See
        // {@see CoreCache}.
        CoreCache::purge($modx);
    }

    /**
     * If the target database was missing, the MODX CLI setup silently sets
     * `OPT_OVERRIDE_TABLE_TYPE = 'MyISAM'` and returns a `warning` without breaking off. Every core
     * table becomes MyISAM and the rollback stops meaning anything, giving away nothing about it.
     */
    private function assertTablesAreTransactional(modX $modx): void
    {
        $prefix = $modx->getOption('table_prefix');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'modx_';

        $statement = $modx->query(sprintf(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
            . "AND TABLE_NAME LIKE %s ESCAPE '\\\\' "
            . "AND (ENGINE IS NULL OR ENGINE <> 'InnoDB') ORDER BY TABLE_NAME LIMIT 5",
            $modx->quote($this->likePattern($prefix))
        ));

        // `xPDO::query()` is declared as `PDOStatement|false`, but a substitute core in tests (and
        // any decorator) is free to return `null`: an integrity check must not turn into an `Error`
        // out of nowhere.
        if (!$statement instanceof PDOStatement) {
            return;
        }

        $tables = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (is_string($table)) {
                $tables[] = $table;
            }
        }

        if ($tables !== []) {
            throw TransactionLostException::nonTransactionalTables($tables);
        }
    }

    private function prepareGuardTable(modX $modx): void
    {
        // DDL is safe here: the test's transaction is not open yet. The engine is given
        // explicitly — the marker must obey the rollback regardless of the server defaults.
        //
        // An `IF NOT EXISTS` against a table with a DIFFERENT schema is not an error but a no-op:
        // this statement by itself will not reveal the unusability; the checked insertion of the
        // marker in `begin()` will. Here what is checked is what is checked: a failure to create and
        // a failure to clean.
        $this->execOrFail(
            $modx,
            sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (marker VARCHAR(64) NOT NULL, PRIMARY KEY (marker)) ENGINE=InnoDB',
                self::GUARD_TABLE
            )
        );

        // Markers of previous runs left behind by a killed process must not count as somebody
        // else's loss of isolation.
        $this->execOrFail($modx, 'DELETE FROM `' . self::GUARD_TABLE . '`');
    }

    private function execOrFail(modX $modx, string $statement): void
    {
        if ($modx->exec($statement) !== false) {
            return;
        }

        throw TransactionNotStartedException::guardTableUnusable(
            self::GUARD_TABLE,
            $this->driverError($modx)
        );
    }

    private function driverError(modX $modx): string
    {
        $info = $modx->pdo?->errorInfo() ?? [];
        $message = $info[2] ?? null;

        return is_string($message) && $message !== '' ? $message : 'the driver named no reason';
    }

    private function markerSurvivedRollback(modX $modx, string $marker): bool
    {
        $statement = $modx->query(sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE marker = %s',
            self::GUARD_TABLE,
            $modx->quote($marker)
        ));

        return $statement instanceof PDOStatement && (int) $statement->fetchColumn() > 0;
    }

    /**
     * Removes a marker that survived a loss of isolation. The result is deliberately not checked:
     * this call is on the failure path, and a failure of its own would substitute for the real
     * cause.
     */
    private function forgetMarker(modX $modx, ?string $marker): void
    {
        if ($marker === null) {
            return;
        }

        $modx->exec(sprintf(
            'DELETE FROM `%s` WHERE marker = %s',
            self::GUARD_TABLE,
            $modx->quote($marker)
        ));
    }

    /**
     * `_` and `%` in a prefix are LIKE wildcards: without escaping, the prefix `modx_` would match
     * `modxNNN` tables as well.
     */
    private function likePattern(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
    }
}

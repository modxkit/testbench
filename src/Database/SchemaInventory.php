<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Database;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\TestbenchException;
use PDO;
use PDOException;

/**
 * A cheap integrity gate for the test database: how many tables with the configured prefix it holds
 * right now.
 *
 * Before this, "installed" meant exactly the lock plus `config.inc.php` plus `index.php` — not a
 * single call to the database. A wiped core table left the environment "installed": the core booted,
 * one or two tests out of a hundred failed, the diagnostics led astray, and after the very first
 * test under `RefreshesDatabase` the environment "healed itself" — a picture indistinguishable from
 * a flake.
 *
 * @internal
 */
final class SchemaInventory
{
    public static function countTablesWithPrefix(DatabaseConfig $database): int
    {
        $pdo = self::connect($database);

        try {
            // Views do not enter the count: an extra may create one of its own, and the number
            // would diverge from the one recorded at install time without a single lost table.
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES '
                . "WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ? ESCAPE ?"
            );
            $statement->execute([$database->name, self::likePattern($database->prefix), '\\']);

            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new TestbenchException(
                sprintf(
                    'Failed to recount the tables of database "%s": %s. Make sure the DBMS is '
                    . 'reachable and that the user may read information_schema.',
                    $database->name,
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }

    /**
     * The tables with the prefix that were created by an engine without rollback support. An empty
     * list means transaction isolation is workable.
     *
     * @return list<string>
     */
    public static function nonTransactionalTables(DatabaseConfig $database): array
    {
        $pdo = self::connect($database);

        try {
            $statement = $pdo->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES '
                . "WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ? ESCAPE ? "
                . "AND (ENGINE IS NULL OR ENGINE <> 'InnoDB') ORDER BY TABLE_NAME"
            );
            $statement->execute([$database->name, self::likePattern($database->prefix), '\\']);

            $tables = [];

            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
                if (is_string($table)) {
                    $tables[] = $table;
                }
            }

            return $tables;
        } catch (PDOException $exception) {
            throw new TestbenchException(
                sprintf(
                    'Failed to read the table engines of database "%s": %s.',
                    $database->name,
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }

    private static function connect(DatabaseConfig $database): PDO
    {
        try {
            return new PDO(
                $database->dsnWithoutDatabase(),
                $database->user,
                $database->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $exception) {
            throw new TestbenchException(
                sprintf(
                    'Failed to connect to database server %s:%d as user "%s": %s'
                    . ' Check MODX_TESTBENCH_DB_HOST/PORT/USER/PASS.',
                    $database->host,
                    $database->port,
                    $database->user,
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }

    /**
     * `_` and `%` in a prefix are LIKE wildcards: without escaping, the prefix `modx_` would match
     * `modxNNN` tables as well.
     */
    private static function likePattern(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
    }
}

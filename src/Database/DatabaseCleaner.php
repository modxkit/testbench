<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Database;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\Secret;
use PDO;
use PDOException;

/**
 * Removes the traces of a previous MODX installation from the test database.
 *
 * In `--installmode=new` the installer refuses to work if the table prefix is already taken
 * (`setup/includes/request/modinstallclirequest.class.php:331`, the `test_table_prefix_inuse`
 * lexicon entry). We recreate the environment directory ourselves, but the database survives the
 * recreation — without the cleanup a forced reinstall would be impossible.
 *
 * @internal
 */
final class DatabaseCleaner
{
    /**
     * Creates the test database if it does not exist yet.
     *
     * FR-INSTALL-5 placed the creation of the database on the MODX installer, and that turned out
     * to be a misreading of its behaviour. Failing to find the target database, the CLI setup
     * silently sets `OPT_OVERRIDE_TABLE_TYPE = 'MyISAM'` and returns a `warning` without breaking
     * off: the install "succeeds", all 70 tables are created as MyISAM, `rollBack()` runs to no
     * effect, and the package's isolation is switched off entirely — without a line said about
     * it.
     */
    public function ensureDatabaseExists(DatabaseConfig $database): void
    {
        $pdo = $this->connect($database);

        try {
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
                $this->quoteIdentifier($database->name),
                $this->identifierValue($database->charset, 'MODX_TESTBENCH_DB_CHARSET'),
                $this->identifierValue($database->collation, 'MODX_TESTBENCH_DB_COLLATION')
            ));
        } catch (PDOException $exception) {
            throw new TestbenchException(
                sprintf(
                    'Failed to create test database "%s": %s' . "\n"
                    . 'Make sure user "%s" has the CREATE privilege, or create the database in advance.',
                    $database->name,
                    $exception->getMessage(),
                    $database->user
                ),
                0,
                $exception
            );
        }
    }

    /**
     * The charset and collation names cannot be passed to `CREATE DATABASE` as parameters, so we
     * check what they are made of: anything that does not look like a MySQL identifier is
     * rejected — otherwise the value of an environment variable would land in the SQL as is.
     */
    private function identifierValue(string $value, string $variable): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new TestbenchException(sprintf(
                'Invalid value of %s: "%s". Only Latin letters, digits and the underscore are '
                . 'allowed (utf8mb4 and utf8mb4_general_ci, for example).',
                $variable,
                $value
            ));
        }

        return $value;
    }

    /**
     * Drops the tables with the configured prefix. A database that does not exist yet is not an
     * error: {@see self::ensureDatabaseExists()} will create it.
     */
    public function purgeInstallation(DatabaseConfig $database): void
    {
        // An empty prefix would give the LIKE '%' pattern and wipe every table of the database,
        // including somebody else's. It is unreachable through the environment
        // (DatabaseConfig::fromEnvironment() substitutes 'modx_'), but the DatabaseConfig
        // constructor is public and does not validate the value.
        if ($database->prefix === '') {
            throw new TestbenchException(
                'Cleanup of the test database cancelled: the table prefix is empty, and without it '
                . 'every table of database "' . $database->name . '" would have to be dropped. '
                . 'Set MODX_TESTBENCH_DB_PREFIX (modx_ by default).'
            );
        }

        $pdo = $this->connect($database);

        // How many drop statements the server has already executed. It tells "the database is
        // untouched" apart from "some objects are already dropped": the former message said the
        // second even when the failure arrived on the LISTING, that is, before a single DROP.
        //
        // The counter counts STATEMENTS, not objects, and it is accurate only because every
        // statement here executes all-or-nothing. That property does not hold by itself but rests on
        // the `SET FOREIGN_KEY_CHECKS = 0` below: it is measured that on MariaDB 10.11.18 there is
        // no atomic DDL and, with the checks enabled, `DROP TABLE` wipes PART of the list, failing
        // on a table with a foreign key from outside — the counter would stay at zero and the
        // message "The database was left untouched" would become a lie. Removing that line removes
        // this too. The property is held by `DatabaseCleanerTest::
        // testPurgeDropsATableReferencedFromOutsideBecauseForeignKeyChecksAreDisabled()`.
        $dropped = 0;

        // A driver failure at ANY point of the cleanup (NFR-3) — both on listing the objects and on
        // dropping them — is no reason to escape outwards as somebody else's exception type without
        // a word about what the package was doing. The listing sits inside the `try` not for
        // beauty: a connection breaks between queries just as readily as on the first one.
        try {
            $objects = $this->objectsWithPrefix($pdo, $database);
            $statements = [];

            // A view with the prefix takes up the prefix on a par with a table, and
            // `DROP TABLE IF EXISTS` does not remove it. After such a "cleanup" the MODX installer
            // refused to install the environment (`test_table_prefix_inuse`) while `purge` reported
            // success. So views are dropped with their own statement and first: a view over a table
            // being dropped would otherwise be left hanging broken.
            //
            // The second half of that — "silently" — is measured (MySQL 8.4.11, PHP 8.4.8) and
            // narrowed to the measured: the server is not entirely silent — it returns
            // `Note 1051 Unknown table` — but that Note is visible only through a SEPARATE
            // `SHOW WARNINGS` query, which the cleanup does not make. Through the channels of
            // `PDO::exec()` itself the two outcomes are indistinguishable byte for byte: on both a
            // dropped table and a surviving view `exec()` gives `0`, `errorCode()` gives `00000`,
            // `errorInfo()` gives `['00000', null, null]`, and there is no exception. This is held
            // by {@see \ModxKit\Testbench\Tests\Integration\Database\DatabaseCleanerTest::
            // testDroppingAViewAsATableLooksExactlyLikeSuccessThroughEveryChannelOfExec()}.
            foreach (['VIEW' => 'DROP VIEW IF EXISTS ', 'BASE TABLE' => 'DROP TABLE IF EXISTS '] as $type => $drop) {
                $names = array_map(
                    fn (string $name): string => $this->quoteIdentifier($database->name)
                        . '.' . $this->quoteIdentifier($name),
                    $objects[$type] ?? []
                );

                if ($names !== []) {
                    $statements[] = $drop . implode(', ', $names);
                }
            }

            if ($statements === []) {
                return;
            }

            // MODX tables are not always tied by foreign keys, but extensions add them, so the
            // order of removal must not matter.
            //
            // The second, less obvious role of this line is the accuracy of the diagnostics below.
            // With the checks enabled, `DROP TABLE` on MariaDB executes partially (measured), and
            // the `$dropped` counter, which counts statements, would start lying. Both roles are
            // held by one and the same test — see the comment on `$dropped` above.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            try {
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                    $dropped++;
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        } catch (PDOException $exception) {
            throw new TestbenchException(
                sprintf(
                    "Failed to remove the previous MODX installation from database \"%s\" as user \"%s\": %s\n%s",
                    $database->name,
                    $database->user,
                    Secret::mask($exception->getMessage(), $database->password),
                    $dropped === 0
                        ? 'The database was left untouched: it never got as far as dropping objects. '
                            . 'Check that the database is reachable for this user and that DROP TABLE '
                            . 'and DROP VIEW are allowed to them on it.'
                        : sprintf(
                            'Some objects with prefix "%s" are already dropped, some are left. '
                            . 'Make sure DROP TABLE and DROP VIEW are allowed to the user on this '
                            . 'database.',
                            $database->prefix
                        )
                ),
                0,
                $exception
            );
        }
    }

    private function connect(DatabaseConfig $database): PDO
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
                    "Failed to connect to database server %s:%d as user \"%s\" (password ***): %s\n"
                    . 'Make sure the DBMS is running and that MODX_TESTBENCH_DB_HOST/PORT/USER/PASS '
                    . 'point to an account with privileges on the test database.',
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
     * The objects with the configured prefix, laid out by type (`BASE TABLE`, `VIEW`).
     *
     * @return array<string, list<string>>
     */
    private function objectsWithPrefix(PDO $pdo, DatabaseConfig $database): array
    {
        $statement = $pdo->prepare(
            'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ? ESCAPE ?'
        );

        $statement->execute([$database->name, $this->likePattern($database->prefix), '\\']);

        $objects = [];

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            if (!is_array($row) || !isset($row[0], $row[1]) || !is_string($row[0]) || !is_string($row[1])) {
                continue;
            }

            $objects[$row[1]][] = $row[0];
        }

        return $objects;
    }

    /**
     * `_` and `%` in a prefix are LIKE wildcards: without escaping, the prefix `modx_` would match
     * `modxNNN` tables as well.
     */
    private function likePattern(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

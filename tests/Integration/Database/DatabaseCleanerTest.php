<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Database;

use ModxKit\Testbench\Database\DatabaseCleaner;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Tests\Support\FixtureDatabaseUser;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('integration')]
final class DatabaseCleanerTest extends TestCase
{
    use FixtureDatabaseUser;

    /**
     * The prefix for the fixture account's names. It is also what the account is cleaned up by, so it
     * must be recognisable and must not coincide with anything foreign — the prefix of the same
     * fixture in `PhpDumperTest` included.
     */
    private const USER_PREFIX = 'modx_tb_cleaner_';

    /**
     * The name is derived from the run (the environment fingerprint plus the pid) rather than
     * hard-coded — otherwise two runs against one DBMS server wiped out each other's databases in
     * the middle of a foreign test (found live by three reviewers). The compromise of the scheme and
     * its limitations are in {@see RunScopedDatabaseName}.
     */
    private string $dbName;

    private DatabaseConfig $database;

    protected function setUp(): void
    {
        $this->dbName = RunScopedDatabaseName::forBase('modx_testbench_cleaner_test');

        $environment = DatabaseConfig::fromEnvironment();

        $this->database = new DatabaseConfig(
            host: $environment->host,
            port: $environment->port,
            name: $this->dbName,
            user: $environment->user,
            password: $environment->password,
            prefix: 'modx_',
            charset: $environment->charset,
            collation: $environment->collation,
        );
    }

    protected function tearDown(): void
    {
        try {
            $server = $this->serverConnection();
            $server->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            $this->dropFixtureUser($server);
        } catch (Throwable) {
            // Cleanup "where possible": an unreachable DBMS must not break the test report.
        }
    }

    public function testRemovesOnlyTablesWithConfiguredPrefix(): void
    {
        $pdo = $this->serverConnection();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $this->dbName . '`');
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`modx_site_content` (id INT PRIMARY KEY)');
        // The `_` in the prefix is a LIKE wildcard: a table without an underscore after "modx" must
        // not fall under the cleanup.
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`modxOther` (id INT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`legacy_data` (id INT PRIMARY KEY)');

        (new DatabaseCleaner())->purgeInstallation($this->database);

        self::assertSame(['legacy_data', 'modxOther'], $this->tables());
    }

    /**
     * A view with the configured prefix used to survive the cleanup — the enumeration did not look
     * at `TABLE_TYPE`, and `DROP TABLE` does not delete a view. The prefix stayed occupied while
     * this happened, and the MODX installer refused to set the environment up
     * (`test_table_prefix_inuse`) — even though `purge` reported success.
     */
    public function testRemovesViewsWithTheConfiguredPrefixToo(): void
    {
        $pdo = $this->serverConnection();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $this->dbName . '`');
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`modx_site_content` (id INT PRIMARY KEY)');
        $pdo->exec('CREATE VIEW `' . $this->dbName . '`.`modx_published` AS '
            . 'SELECT id FROM `' . $this->dbName . '`.`modx_site_content`');
        $pdo->exec('CREATE VIEW `' . $this->dbName . '`.`legacy_view` AS SELECT 1 AS one');

        (new DatabaseCleaner())->purgeInstallation($this->database);

        self::assertSame(['legacy_view'], $this->tables());
    }

    /**
     * The second half of that line: it had been proved that a view survives a
     * `DROP TABLE IF EXISTS`, but not that it survives it SILENTLY. The earlier oracle
     * (`SHOW WARNINGS`) refuted that half and was removed: there is a Note there.
     *
     * The oracle here is a different and an exact one: ALL the channels `PDO::exec()` itself opens
     * are compared across two calls of one and the same statement — against a real table and against
     * a view. The return value, `errorCode()` and `errorInfo()` must match down to the last byte,
     * while the outcome is the opposite: the table is gone, the view remains. That match is what
     * "silently" means: the caller has nothing to tell what was done from what was not.
     *
     * Note 1051 does exist while this happens, and the test records that rather than hushing it up:
     * it is visible through a SEPARATE `SHOW WARNINGS` query on the same connection. The cleanup
     * makes no such query — and so "silently" here means exactly "through the channels of `exec()`",
     * not "the server said nothing".
     */
    public function testDroppingAViewAsATableLooksExactlyLikeSuccessThroughEveryChannelOfExec(): void
    {
        $pdo = $this->serverConnection();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $this->dbName . '`');
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`modx_site_content` (id INT PRIMARY KEY)');
        $pdo->exec('CREATE VIEW `' . $this->dbName . '`.`modx_published` AS '
            . 'SELECT id FROM `' . $this->dbName . '`.`modx_site_content`');

        // The view is dropped FIRST: it rests on the table, and the reverse order would replace the
        // case under test with a broken view.
        $onView = $this->observeDropTable($pdo, 'modx_published');
        $onTable = $this->observeDropTable($pdo, 'modx_site_content');

        self::assertSame(
            $onTable,
            $onView,
            'The channels of PDO::exec() tell dropping a view from dropping a table — "silently" is wrong.'
        );

        // The outcomes are opposite — otherwise the match above would be a tautology.
        self::assertSame(['modx_published'], $this->tables());

        // The server is not silent after all — but only through a separate query, which the cleanup does not make.
        self::assertSame(
            [],
            $this->warnings($pdo),
            'The last one was the drop of a REAL table: there is nothing to warn about.'
        );

        $pdo->exec('DROP TABLE IF EXISTS `' . $this->dbName . '`.`modx_published`');

        // The Note code is vendor-specific, and both lines are measured (2026-09-02): MySQL 8.4 answers
        // "Note 1051 Unknown table", that is, dropping a view is indistinguishable here too from
        // dropping a table that does not exist; MariaDB 10.11 answers "Note 1965 … is a view" and so
        // speaks of the case outright. The branching here is not a concession: without it the job on
        // MariaDB went red expecting the other vendor's code, and with a general "there is some Note"
        // the check would stop telling these two answers apart at all.
        self::assertSame(
            [$this->isMariadb($pdo) ? 'Note 1965' : 'Note 1051'],
            $this->warnings($pdo)
        );
    }

    /**
     * The server vendor is taken from the connection's version string: MariaDB names itself in it
     * outright (`10.11.18-MariaDB-ubu2204`, measured), Oracle MySQL does not (`8.4.11`).
     */
    private function isMariadb(PDO $pdo): bool
    {
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) && stripos($version, 'mariadb') !== false;
    }

    /**
     * The diagnostics of the last statement in the form "level code" — the shape is deliberately
     * poor: the test speaks of the FACT that a Note exists rather than of the text of the message,
     * which carries the database name.
     *
     * @return list<string>
     */
    private function warnings(PDO $pdo): array
    {
        $statement = $pdo->query('SHOW WARNINGS');

        self::assertInstanceOf(PDOStatement::class, $statement);

        $rows = $statement->fetchAll(PDO::FETCH_NUM);
        $summary = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey(0, $row);
            self::assertArrayHasKey(1, $row);
            self::assertIsScalar($row[0]);
            self::assertIsNumeric($row[1]);

            $summary[] = sprintf('%s %d', (string) $row[0], (int) $row[1]);
        }

        return $summary;
    }

    /**
     * Everything `PDO::exec()` reports to the caller about a single `DROP TABLE IF EXISTS`.
     *
     * @return array<string, mixed>
     */
    private function observeDropTable(PDO $pdo, string $name): array
    {
        $affected = $pdo->exec('DROP TABLE IF EXISTS `' . $this->dbName . '`.`' . $name . '`');

        return [
            'exec' => $affected,
            'errorCode' => $pdo->errorCode(),
            'errorInfo' => $pdo->errorInfo(),
        ];
    }

    /**
     * The guard for the `SET FOREIGN_KEY_CHECKS = 0` line.
     *
     * The line looks like a concern about the order of deletion, but it also holds the accuracy of
     * the diagnostics. It was measured that on MariaDB there is no atomic DDL, and a `DROP TABLE`
     * with `foreign_key_checks = 1` really is executed PARTIALLY — it drops part of the list and
     * fails on a table referenced by a foreign key from outside. The `$dropped` counter stays at zero
     * while this happens (the statement did not complete), and the message "the database is
     * untouched" becomes a lie. Today that does not happen precisely because the cleanup sets the
     * flag to zero; let somebody remove the line and the suite, before this test existed, stayed
     * green (verified in review: `OK (172 tests)` with the line removed).
     *
     * The test reproduces the MECHANISM rather than checking that the line is present: the foreign
     * key comes from a table WITHOUT the prefix, that is, from one the cleanup does not touch and
     * cannot delete. With `foreign_key_checks = 1` the server rejects the `DROP TABLE` on the parent
     * (`3730` on MySQL, `1451` on MariaDB) and the cleanup fails; with zero it goes through.
     */
    public function testPurgeDropsATableReferencedFromOutsideBecauseForeignKeyChecksAreDisabled(): void
    {
        $pdo = $this->serverConnection();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $this->dbName . '`');
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`modx_site_content` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec(
            'CREATE TABLE `' . $this->dbName . '`.`outside_child` (id INT, '
            . 'CONSTRAINT `fk_outside` FOREIGN KEY (id) '
            . 'REFERENCES `' . $this->dbName . '`.`modx_site_content` (id)) ENGINE=InnoDB'
        );

        // Premise: the key really does prevent deletion while the checks are on. Without it the test
        // would go green even on a server where foreign keys are not enforced at all.
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->exec('DROP TABLE `' . $this->dbName . '`.`modx_site_content`');
            self::fail('The server deleted a table referenced by a foreign key — the premise of the test does not hold.');
        } catch (PDOException $exception) {
            self::assertMatchesRegularExpression('/\b(1451|3730)\b/', $exception->getMessage());
        }

        (new DatabaseCleaner())->purgeInstallation($this->database);

        // The prefixed table is dropped, the foreign one is in place.
        self::assertSame(['outside_child'], $this->tables());
    }

    /**
     * NFR-3: a refusal by the DBMS in the middle of the cleanup must not fall out as a raw
     * `PDOException`. The user is allowed only `SELECT` — the database is visible to them, while the
     * server rejects a `DROP`.
     */
    public function testDatabaseFailureDuringPurgeIsReportedAsATestbenchFailure(): void
    {
        $pdo = $this->serverConnection();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $this->dbName . '`');
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`modx_site_content` (id INT PRIMARY KEY)');

        $limited = $this->limitedAccessDatabase();

        try {
            (new DatabaseCleaner())->purgeInstallation($limited);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('Failed to remove the previous MODX installation', $exception->getMessage());
            // The server's message is attached to the failure rather than lost.
            self::assertStringContainsString('DROP command denied', $exception->getMessage());
            self::assertStringContainsString($limited->user, $exception->getMessage());
            self::assertStringNotContainsString($limited->password, $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            // The very first DROP was rejected, which means NOTHING was deleted.
            self::assertStringContainsString('The database was left untouched', $exception->getMessage());
        }

        self::assertSame(['modx_site_content'], $this->tables());
    }

    /**
     * The branch "it never got as far as deleting": the failure arrives on the ENUMERATION of the
     * objects.
     *
     * The trigger is a database name with a broken UTF-8 sequence: `MODX_TESTBENCH_DB_NAME` comes
     * from the environment and nobody checks the bytes in it, while the server rejects such a
     * parameter in a query to `information_schema` (verified on MySQL 8.4 and MariaDB 10.11 — both
     * answer with error 1267). The earlier message said "some of the objects may already have been
     * deleted" here too, although not a single DROP had run.
     */
    public function testFailureWhileListingObjectsSaysTheDatabaseWasNotTouched(): void
    {
        $broken = new DatabaseConfig(
            host: $this->database->host,
            port: $this->database->port,
            name: "modx_testbench_\xC3\x28_broken",
            user: $this->database->user,
            password: $this->database->password,
            prefix: 'modx_',
            charset: $this->database->charset,
            collation: $this->database->collation,
        );

        try {
            (new DatabaseCleaner())->purgeInstallation($broken);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('The database was left untouched', $exception->getMessage());
            self::assertStringNotContainsString('are already dropped', $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }
    }

    /**
     * The reverse branch: one deletion has already gone through, the second is rejected. The user is
     * granted `DROP` on the view but not on the table, and the package drops views first.
     */
    public function testFailureAfterTheFirstDropSaysPartOfTheObjectsAreAlreadyGone(): void
    {
        $pdo = $this->serverConnection();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $this->dbName . '`');
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`modx_site_content` (id INT PRIMARY KEY)');

        $password = 'clean-' . bin2hex(random_bytes(4));
        $user = $this->createFixtureUser($pdo, self::USER_PREFIX, $password);
        $pdo->exec('GRANT SELECT ON `' . $this->dbName . '`.* TO ' . $pdo->quote($user) . "@'%'");
        // The owner of the view is the fixture user itself: deleting an object whose DEFINER is the
        // system one (root) would require the SYSTEM_USER privilege, and the very first DROP would be refused.
        $pdo->exec('CREATE DEFINER=`' . $user . '`@`%` VIEW `' . $this->dbName . '`.`modx_published` AS '
            . 'SELECT id FROM `' . $this->dbName . '`.`modx_site_content`');
        $pdo->exec('GRANT DROP ON `' . $this->dbName . '`.`modx_published` TO ' . $pdo->quote($user) . "@'%'");

        $partial = new DatabaseConfig(
            host: $this->database->host,
            port: $this->database->port,
            name: $this->dbName,
            user: $user,
            password: $password,
            prefix: 'modx_',
            charset: $this->database->charset,
            collation: $this->database->collation,
        );

        try {
            (new DatabaseCleaner())->purgeInstallation($partial);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('are already dropped, some are left', $exception->getMessage());
            self::assertStringNotContainsString('The database was left untouched', $exception->getMessage());
        }

        // The view is dropped, the table remains — exactly what the message says.
        self::assertSame(['modx_site_content'], $this->tables());
    }

    public function testMissingDatabaseIsNotAnError(): void
    {
        $this->serverConnection()->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');

        (new DatabaseCleaner())->purgeInstallation($this->database);

        self::assertSame([], $this->tables());
    }

    public function testWrongCredentialsAreReportedWithoutLeakingThePassword(): void
    {
        $wrongPassword = 'wrong-' . bin2hex(random_bytes(4));

        $database = new DatabaseConfig(
            host: $this->database->host,
            port: $this->database->port,
            name: $this->dbName,
            user: 'modx-testbench-nobody',
            password: $wrongPassword,
            prefix: 'modx_',
            charset: $this->database->charset,
            collation: $this->database->collation,
        );

        try {
            (new DatabaseCleaner())->purgeInstallation($database);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('***', $exception->getMessage());
            self::assertStringNotContainsString($wrongPassword, $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_DB_HOST', $exception->getMessage());
        }
    }

    public function testEmptyTablePrefixIsRefusedInsteadOfWipingTheDatabase(): void
    {
        $pdo = $this->serverConnection();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $this->dbName . '`');
        $pdo->exec('CREATE TABLE `' . $this->dbName . '`.`modx_site_content` (id INT PRIMARY KEY)');

        $database = new DatabaseConfig(
            host: $this->database->host,
            port: $this->database->port,
            name: $this->dbName,
            user: $this->database->user,
            password: $this->database->password,
            prefix: '',
            charset: $this->database->charset,
            collation: $this->database->collation,
        );

        try {
            (new DatabaseCleaner())->purgeInstallation($database);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('MODX_TESTBENCH_DB_PREFIX', $exception->getMessage());
        }

        self::assertSame(['modx_site_content'], $this->tables());
    }

    /**
     * A user with the `SELECT` privilege on one table: the database and its enumeration are available
     * to them, while the server rejects a `DROP`. There is no other way to obtain a refusal from the
     * DBMS in the middle of the cleanup without touching the global settings of the shared server.
     *
     * The name is deterministic and is cleaned up from earlier runs — see {@see FixtureDatabaseUser}.
     */
    private function limitedAccessDatabase(): DatabaseConfig
    {
        $pdo = $this->serverConnection();
        $password = 'clean-' . bin2hex(random_bytes(4));
        $user = $this->createFixtureUser($pdo, self::USER_PREFIX, $password);

        $pdo->exec(
            'GRANT SELECT ON `' . $this->dbName . '`.`modx_site_content` TO ' . $pdo->quote($user) . "@'%'"
        );

        return new DatabaseConfig(
            host: $this->database->host,
            port: $this->database->port,
            name: $this->dbName,
            user: $user,
            password: $password,
            prefix: 'modx_',
            charset: $this->database->charset,
            collation: $this->database->collation,
        );
    }

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        $statement = $this->serverConnection()->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME'
        );
        $statement->execute([$this->dbName]);

        $tables = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (is_string($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    private function serverConnection(): PDO
    {
        return new PDO(
            $this->database->dsnWithoutDatabase(),
            $this->database->user,
            $this->database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}

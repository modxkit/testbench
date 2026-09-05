<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Database;

use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Database\SnapshotFile;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use ModxKit\Testbench\Tests\Support\FixtureDatabaseUser;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The php strategy against a live DBMS. The work is done in a separate database so that the checks
 * of DDL and of deliberately broken snapshots do not touch the MODX environment.
 */
#[Group('integration')]
final class PhpDumperTest extends TestCase
{
    use FixtureDatabaseUser;

    /**
     * The prefix for the fixture account's names. It is also what the account is cleaned up by, so it
     * must be recognisable and must not coincide with anything foreign.
     */
    private const USER_PREFIX = 'modx_tb_lim_';

    /**
     * The name is derived from the run (the environment fingerprint plus the pid) rather than
     * hard-coded — otherwise two runs against one DBMS server wiped out each other's databases in
     * the middle of a foreign test (found live by three reviewers). The compromise of the scheme and
     * its limitations are in {@see RunScopedDatabaseName}.
     */
    private string $dbName;

    private DatabaseConfig $database;
    private string $file;

    protected function setUp(): void
    {
        $this->dbName = RunScopedDatabaseName::forBase('modx_testbench_snapshot_test');

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

        $this->file = sys_get_temp_dir() . '/tb-php-dump-' . bin2hex(random_bytes(4)) . '.sql';

        $server = new PDO(
            $this->database->dsnWithoutDatabase(),
            $this->database->user,
            $this->database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $server->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        $server->exec('CREATE DATABASE `' . $this->dbName . '`');
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '.part'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        try {
            $server = new PDO(
                $this->database->dsnWithoutDatabase(),
                $this->database->user,
                $this->database->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $server->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            $this->dropFixtureUser($server);
        } catch (Throwable) {
            // Cleanup "where possible": an unreachable DBMS must not break the test report.
        }
    }

    public function testRestoreBringsBackTablesDroppedAfterCapture(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_probe` (id INT PRIMARY KEY, name VARCHAR(32)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO `modx_probe` VALUES (1, 'first'), (2, 'second')");

        (new PhpDumper())->dump($this->database, $this->file);

        // DROP TABLE is an implicit commit: transaction isolation does not undo such an edit, and it
        // is for this case that snapshots exist.
        $pdo->exec('DROP TABLE `modx_probe`');

        (new PhpDumper())->load($this->database, $this->file);

        self::assertSame(
            [[1, 'first'], [2, 'second']],
            $this->rows('SELECT id, name FROM `modx_probe` ORDER BY id')
        );
    }

    public function testRestoreRemovesTablesCreatedAfterCapture(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_probe` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO `modx_probe` VALUES (1)');

        (new PhpDumper())->dump($this->database, $this->file);

        // CREATE TABLE is the commonest DDL in extension tests and just as much an implicit commit as
        // DROP TABLE: a transaction does not undo it, a snapshot must.
        $pdo->exec('CREATE TABLE `modx_created_later` (id INT PRIMARY KEY) ENGINE=InnoDB');

        (new PhpDumper())->load($this->database, $this->file);

        self::assertSame(['modx_probe'], $this->baseTables());
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM `modx_probe`'));
    }

    /**
     * Generated columns must not be substituted into an `INSERT` — MySQL answers with error 3105
     * ("The value specified for generated column … is not allowed"). Without an explicit column
     * list, `INSERT INTO … VALUES` substituted ALL the values, computed ones included, and the
     * restore broke off on the very first extension table with such a column — after the database
     * had already been cleaned out. The 3.2.3 core has no such columns, extras may legitimately have
     * them, and the baseline is captured from the WHOLE database.
     */
    public function testRoundTripRestoresTablesWithGeneratedColumns(): void
    {
        $pdo = $this->connection();
        $pdo->exec(
            'CREATE TABLE `modx_generated` ('
            . 'id INT PRIMARY KEY, '
            . 'price INT NOT NULL, '
            . 'quantity INT NOT NULL, '
            . 'total INT GENERATED ALWAYS AS (price * quantity) STORED, '
            . 'label VARCHAR(64) GENERATED ALWAYS AS (CONCAT(\'#\', id)) VIRTUAL, '
            . 'note VARCHAR(32) NULL'
            . ') ENGINE=InnoDB'
        );
        $pdo->exec("INSERT INTO `modx_generated` (id, price, quantity, note) VALUES (1, 3, 4, 'first'), (2, 5, 6, NULL)");

        (new PhpDumper())->dump($this->database, $this->file);

        $pdo->exec('DROP TABLE `modx_generated`');

        (new PhpDumper())->load($this->database, $this->file);

        self::assertSame(
            [[1, 3, 4, 12, '#1', 'first'], [2, 5, 6, 30, '#2', null]],
            $this->rows('SELECT id, price, quantity, total, label, note FROM `modx_generated` ORDER BY id')
        );

        // The columns are listed explicitly, and the computed ones did not make it into the list.
        $snapshot = (string) file_get_contents($this->file);
        self::assertStringContainsString(
            'INSERT INTO `modx_generated` (`id`,`price`,`quantity`,`note`) VALUES ',
            $snapshot
        );
    }

    public function testRoundTripPreservesNullsBinaryValuesAndAwkwardText(): void
    {
        $pdo = $this->connection();
        $pdo->exec(
            'CREATE TABLE `modx_values` '
            . '(id INT PRIMARY KEY, payload BLOB, text_value LONGTEXT NULL, ratio DOUBLE) ENGINE=InnoDB'
        );

        $binary = "\x00\x01\xff\xfe\x80\x1a'\\\"`";
        // A semicolon, quotes and line breaks inside a value must not fool the splitting of the
        // snapshot into statements. The Cyrillic in the long value below is deliberate and must NOT
        // be replaced with ASCII: it is what carries the multibyte text through the snapshot and
        // back, and it doubles the byte length of the value against its character length
        // (70 000 characters, 130 000 bytes).
        $awkward = "line one; -- not a comment\n'quoted'; \"double\"; `tick`; \\ tail;\n"
            . str_repeat('длинный текст ', 5000);

        $insert = $pdo->prepare('INSERT INTO `modx_values` VALUES (1, ?, ?, 0), (2, ?, NULL, 0)');
        $insert->execute([$binary, $awkward, '']);
        // A literal in the SQL rather than a bound parameter: PDO casts a float to a string at the ini
        // precision=14, and a value that this setting rounds would never reach the server.
        $pdo->exec('UPDATE `modx_values` SET ratio = 0.12345678901234567 WHERE id = 1');

        $ratio = $this->scalar('SELECT ratio FROM `modx_values` WHERE id = 1');
        self::assertIsFloat($ratio);

        (new PhpDumper())->dump($this->database, $this->file);

        $pdo->exec('DROP TABLE `modx_values`');
        (new PhpDumper())->load($this->database, $this->file);

        $rows = $this->rows('SELECT id, payload, text_value, ratio FROM `modx_values` ORDER BY id');

        self::assertCount(2, $rows);
        self::assertSame(bin2hex($binary), bin2hex($this->stringAt($rows[0], 1)));
        self::assertSame($awkward, $this->stringAt($rows[0], 2));
        self::assertSame($ratio, $rows[0][3]);
        self::assertSame('', $this->stringAt($rows[1], 1));
        self::assertNull($rows[1][2]);
    }

    public function testViewsAreNotPartOfTheSnapshot(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_probe` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO `modx_probe` VALUES (1)');
        // For a view, SHOW CREATE TABLE would return a CREATE VIEW, which the preceding DROP TABLE
        // does not undo — views must not get into a snapshot.
        $pdo->exec('CREATE VIEW `modx_probe_view` AS SELECT id FROM `modx_probe`');

        (new PhpDumper())->dump($this->database, $this->file);

        $snapshot = (string) file_get_contents($this->file);

        self::assertStringContainsString('CREATE TABLE `modx_probe`', $snapshot);
        self::assertStringNotContainsString('VIEW', $snapshot);

        $pdo->exec('DROP TABLE `modx_probe`');

        (new PhpDumper())->load($this->database, $this->file);

        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM `modx_probe`'));

        // A view that existed BEFORE the capture did not get into the snapshot — and the restore
        // neither brings it back nor leaves it: the database is brought exactly to the contents of
        // the snapshot. That is what the README promises the consumer, so it is checked rather than assumed.
        self::assertSame([], $this->views());
    }

    /**
     * The contract of {@see Dumper}: a restore brings the database back to the state of the snapshot
     * and removes what was created AFTER the capture. It is mandatory for BOTH strategies, and the
     * php strategy works exactly where there are no clients, that is, on most CI runners (FR-ISO-5).
     *
     * A view does not get into a snapshot (see the test above), so a view that survived a restore is
     * a state that is not in the baseline: it goes on answering with data and, having a name matching
     * a table of the snapshot, breaks that table's recreation.
     */
    public function testRestoreRemovesViewsCreatedAfterCapture(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_probe` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO `modx_probe` VALUES (1)');

        (new PhpDumper())->dump($this->database, $this->file);

        // Exactly what snapshots are for: CREATE VIEW is just as much an implicit commit as
        // CREATE TABLE, and the test's transaction does not undo it.
        $pdo->exec('CREATE VIEW `modx_probe_view` AS SELECT id FROM `modx_probe`');
        $pdo->exec('CREATE TABLE `modx_created_later` (id INT PRIMARY KEY) ENGINE=InnoDB');

        (new PhpDumper())->load($this->database, $this->file);

        self::assertSame(['modx_probe'], $this->baseTables());
        self::assertSame([], $this->views());
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM `modx_probe`'));
    }

    public function testFailingStatementIsNamedAndPartialRestoreIsAnnounced(): void
    {
        file_put_contents(
            $this->file,
            "CREATE TABLE `modx_probe` (id INT PRIMARY KEY);\n"
            . "INSERT INTO `modx_absent` VALUES ('\$2y\$10\$stored-password-hash');\n"
            . SnapshotFile::completionLine(1)
        );

        try {
            (new PhpDumper())->load($this->database, $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('statement #2', $exception->getMessage());
            self::assertStringContainsString('INSERT INTO `modx_absent` VALUES', $exception->getMessage());
            self::assertStringContainsString('restored only in part', $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_FORCE_INSTALL=1', $exception->getMessage());
            // Row data does not get into the diagnostics: in a MODX snapshot that includes the password
            // hashes of the users.
            self::assertStringNotContainsString('stored-password-hash', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptySnapshots(): iterable
    {
        // The completion marker is mandatory: without it the file is rejected earlier and the branch
        // "there are no statements" would never be executed.
        yield 'a comment only' => ["-- an empty snapshot\n\n" . SnapshotFile::completionLine(0)];
        // A semicolon with no statement before it does not make the file a snapshot either.
        yield 'a comment and an empty statement' => ["-- an empty snapshot\n;\n" . SnapshotFile::completionLine(0)];
    }

    #[DataProvider('emptySnapshots')]
    public function testSnapshotWithoutStatementsIsRefused(string $contents): void
    {
        $this->connection()->exec('CREATE TABLE `modx_probe` (id INT PRIMARY KEY) ENGINE=InnoDB');
        file_put_contents($this->file, $contents);

        try {
            (new PhpDumper())->load($this->database, $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('contains no SQL statements at all', $exception->getMessage());
        }

        // An empty snapshot must not turn into a cleaning of the database: there would be nothing to restore after it.
        self::assertSame(['modx_probe'], $this->baseTables());
    }

    /**
     * A failure in the middle of a capture must not dare touch a snapshot that is already in place —
     * that is exactly why the write goes into `.part` rather than into the target file.
     */
    public function testInterruptedDumpLeavesNoHalfWrittenSnapshotAndKeepsThePreviousOne(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_probe` (id INT PRIMARY KEY, secret VARCHAR(32)) ENGINE=InnoDB');

        (new PhpDumper())->dump($this->database, $this->file);
        $previous = (string) file_get_contents($this->file);
        self::assertTrue(SnapshotFile::isComplete($this->file));

        // The privileges are granted on a single column: the table is visible in the SHOW FULL TABLES
        // listing, but SHOW CREATE TABLE against it is forbidden — the refusal arrives after the
        $limited = $this->limitedAccessDatabase();

        try {
            (new PhpDumper())->dump($limited, $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('strategy php', $exception->getMessage());
            self::assertStringNotContainsString($limited->password, $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->file . '.part');
        self::assertStringEqualsFile($this->file, $previous);
    }

    /**
     * The dumper's connection cleans the database with `DROP TABLE`, and a foreign transaction's
     * metadata lock is waited for by default for `lock_wait_timeout` — a year on MySQL 8.4
     * (31,536,000 s) and a day on MariaDB 10.11 (86,400 s), measured on both on 2026-09-02. This is
     * NOT `innodb_lock_wait_timeout` (50 s), and that is exactly why the deadlock looked like a hang.
     *
     * The premise is checked by order of magnitude rather than against one vendor's literal: the
     * literal `31536000` went red on MariaDB while asserting something other than what it was
     * introduced for. The proof below works exactly insofar as the server-side wait is
     * incomparably larger than our cap: the value seen in the snapshot was then set by the dumper
     * rather than inherited from the default.
     *
     * The value is taken from the dumper's OWN connection: the snapshot writes
     * `@@SESSION.lock_wait_timeout` into a table, and the test reads that table from its own
     * connection.
     */
    public function testDumperConnectionCapsTheMetadataLockWait(): void
    {
        $default = $this->scalar('SELECT @@SESSION.lock_wait_timeout');

        self::assertIsNumeric($default);
        self::assertGreaterThan(
            3600,
            (int) $default,
            'By default the server waits for a metadata lock for less than hours — the test below would '
            . 'stop telling our cap from the default value.'
        );

        file_put_contents($this->file, implode('', [
            "CREATE TABLE `modx_probe` (waited BIGINT);\n",
            "INSERT INTO `modx_probe` SELECT @@SESSION.lock_wait_timeout;\n",
            SnapshotFile::completionLine(1),
        ]));

        (new PhpDumper())->load($this->database, $this->file);

        self::assertSame(
            PhpDumper::LOCK_WAIT_TIMEOUT_SECONDS,
            $this->scalar('SELECT waited FROM `modx_probe`')
        );
    }

    /**
     * `fwrite()` on a disk that has filled up returns a number SMALLER than the length of the data
     * rather than `false`. The earlier check (`=== false`) counted such a write as successful, and
     * the snapshot went on being appended "successfully" while breaking off in the middle of a
     * statement.
     *
     * A genuinely full disk cannot be reproduced in a test, so the target path is substituted with a
     * stream of finite capacity — for `PhpDumper` that is an ordinary stream differing only in the
     * number of bytes it returns.
     */
    public function testShortWriteIsReportedInsteadOfSilentlyTruncatingTheSnapshot(): void
    {
        $this->connection()->exec('CREATE TABLE `modx_probe` (id INT PRIMARY KEY) ENGINE=InnoDB');

        // The first statement ("SET FOREIGN_KEY_CHECKS=0;") fits into the capacity, the next one does not.
        ShortWriteStreamWrapper::install(26);

        try {
            (new PhpDumper())->dump($this->database, ShortWriteStreamWrapper::SCHEME . '://space/baseline.sql');
            self::fail('A short write was taken for a successful one.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('writing to', $exception->getMessage());
            self::assertStringContainsString('bytes out of', $exception->getMessage());
            self::assertStringContainsString('free space', $exception->getMessage());
        } finally {
            ShortWriteStreamWrapper::uninstall();
        }

        self::assertSame("SET FOREIGN_KEY_CHECKS=0;\n", ShortWriteStreamWrapper::$written);
    }

    /**
     * The central case. A capture killed by SIGKILL, by a fatal or by a CI timeout does not run PHP's
     * `finally`, so a truncated file is left on disk. A break AT A STATEMENT BOUNDARY — roughly half
     * of the positions — used to give a restore WITHOUT a single error: the cleanup dropped all the
     * tables, the snapshot created some of them, and the suite stayed green.
     */
    public function testSnapshotTruncatedAtStatementBoundaryIsRefusedWithoutTouchingTheDatabase(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_alpha` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE `modx_beta` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO `modx_beta` VALUES (7)');

        (new PhpDumper())->dump($this->database, $this->file);

        $complete = (string) file_get_contents($this->file);
        $boundary = strpos($complete, ";\n", strpos($complete, 'CREATE TABLE') ?: 0);
        self::assertIsInt($boundary, 'No statement boundary was found in the snapshot to break it at.');
        file_put_contents($this->file, substr($complete, 0, $boundary + 2));

        try {
            (new PhpDumper())->load($this->database, $this->file);
            self::fail('A truncated snapshot was accepted for restoring.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('is not read to the end', $exception->getMessage());
            self::assertStringContainsString(SnapshotFile::COMPLETION_PREFIX, $exception->getMessage());
        }

        // The main assertion: the database is untouched. Some of the tables used to be left here.
        self::assertSame(['modx_alpha', 'modx_beta'], $this->baseTables());
        self::assertSame(7, $this->scalar('SELECT id FROM `modx_beta`'));
    }

    /**
     * The other side: a capture that finished moves the file into place with a single `rename()` and
     * leaves a marker at its tail, and leaves no temporary `.part` behind.
     */
    public function testCompletedDumpIsRenamedIntoPlaceAndCarriesTheCompletionMarker(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_alpha` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE `modx_beta` (id INT PRIMARY KEY) ENGINE=InnoDB');

        (new PhpDumper())->dump($this->database, $this->file);

        self::assertFileDoesNotExist($this->file . '.part');
        self::assertStringEndsWith(
            SnapshotFile::completionLine(2),
            (string) file_get_contents($this->file)
        );
        self::assertTrue(SnapshotFile::isComplete($this->file));

        // The marker is an SQL comment: the restore must ignore it rather than stumble over it.
        $pdo->exec('DROP TABLE `modx_beta`');
        (new PhpDumper())->load($this->database, $this->file);

        self::assertSame(['modx_alpha', 'modx_beta'], $this->baseTables());
    }

    public function testFailedCleanupBeforeRestoreIsReportedAsATestbenchFailure(): void
    {
        $this->connection()->exec('CREATE TABLE `modx_probe` (id INT PRIMARY KEY, secret VARCHAR(32)) ENGINE=InnoDB');
        file_put_contents(
            $this->file,
            "CREATE TABLE `modx_restored` (id INT PRIMARY KEY);\n" . SnapshotFile::completionLine(1)
        );

        // The same user is forbidden DROP TABLE: the restore stumbles on cleaning the database — on a
        // step that dump() does not have.
        $limited = $this->limitedAccessDatabase();

        try {
            (new PhpDumper())->load($limited, $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('failed to clean database', $exception->getMessage());
            // The server's message attaches the failure to the cleaning step specifically, not to something earlier.
            self::assertStringContainsString('DROP command denied', $exception->getMessage());
            self::assertStringContainsString('DROP TABLE and DROP VIEW are allowed', $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_FORCE_INSTALL=1', $exception->getMessage());
            self::assertStringNotContainsString($limited->password, $exception->getMessage());
        }

        // The snapshot was never applied to the database: the cleaning did not go through, and there is nothing to load on top.
        self::assertSame(['modx_probe'], $this->baseTables());
    }

    /**
     * A user with the SELECT privilege on a single column of `modx_probe`: the database is visible to
     * them, while any operation on the table itself (SHOW CREATE, SELECT *, DROP) is rejected by the
     * server.
     *
     * The name is deterministic and is cleaned up from earlier runs — see {@see FixtureDatabaseUser}.
     */
    private function limitedAccessDatabase(): DatabaseConfig
    {
        $pdo = $this->connection();
        $password = 'snap-' . bin2hex(random_bytes(4));
        $user = $this->createFixtureUser($pdo, self::USER_PREFIX, $password);

        $pdo->exec('GRANT SELECT (id) ON `' . $this->dbName . '`.`modx_probe` TO ' . $pdo->quote($user) . "@'%'");

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
            (new PhpDumper())->dump($database, $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringNotContainsString($wrongPassword, $exception->getMessage());
            self::assertStringContainsString('Check that the DBMS is reachable', $exception->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function baseTables(): array
    {
        return $this->namesOfType('BASE TABLE');
    }

    /**
     * @return list<string>
     */
    private function views(): array
    {
        return $this->namesOfType('VIEW');
    }

    /**
     * @return list<string>
     */
    private function namesOfType(string $type): array
    {
        $names = [];

        foreach ($this->rows("SHOW FULL TABLES WHERE Table_type = '{$type}'") as $row) {
            $names[] = $this->stringAt($row, 0);
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function rows(string $sql): array
    {
        $statement = $this->connection()->query($sql);
        self::assertNotFalse($statement);

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            self::assertIsArray($row);
            $rows[] = array_values($row);
        }

        return $rows;
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->connection()->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    /**
     * @param array<int, mixed> $row
     */
    private function stringAt(array $row, int $index): string
    {
        $value = $row[$index] ?? null;
        self::assertIsString($value);

        return $value;
    }

    private function connection(): PDO
    {
        return new PDO(
            $this->database->dsn(),
            $this->database->user,
            $this->database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}

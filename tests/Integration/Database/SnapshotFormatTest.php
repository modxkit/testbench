<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Database;

use ModxKit\Testbench\Database\MysqlDumper;
use ModxKit\Testbench\Database\SnapshotManager;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use ModxKit\Testbench\Tests\Support\ClientPathControl;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * A snapshot captured by a real `mysqldump` must not fall to another strategy.
 *
 * The work is done in a separate database with a trigger — that is exactly where the difference
 * between the formats shows: `mysqldump` writes the client-side `DELIMITER` command, which
 * `PhpDumper`'s parser does not know, and it stumbles over it only AFTER the database has been
 * cleaned out for the restore.
 *
 * Real MySQL clients are needed, and a local run is possible with a PATH shim proxying
 * `mysqldump`/`mysql` out of the DBMS container. A dependency on an external tool is expressed as a
 * group excluded by configuration rather than as a `markTestSkipped()` (which used to stand here: a
 * skip is indistinguishable from a green run under `failOnSkipped`). The group is excluded by
 * default in `phpunit.xml`, so the check inside {@see self::setUp()} is not a "soft" skip but a hard
 * refusal FOR WHOEVER REQUESTED THE GROUP explicitly without providing the clients: what is
 * forbidden is introducing new conditional skips, not checks as such.
 */
#[Group('integration')]
#[Group('mysql-client-tools')]
final class SnapshotFormatTest extends TestCase
{
    use ClientPathControl;

    private DatabaseConfig $database;
    private string $file;

    protected function setUp(): void
    {
        self::assertTrue(
            (new MysqlDumper())->isAvailable(),
            'The mysql-client-tools group was requested, but the mysqldump/mysql clients are not in PATH — '
            . 'there is nothing to check with.'
        );

        $environment = DatabaseConfig::fromEnvironment();

        $this->database = new DatabaseConfig(
            host: $environment->host,
            port: $environment->port,
            name: RunScopedDatabaseName::forBase('modx_testbench_format_test'),
            user: $environment->user,
            password: $environment->password,
            prefix: 'modx_',
            charset: $environment->charset,
            collation: $environment->collation,
        );

        $this->file = sys_get_temp_dir() . '/tb-format-' . bin2hex(random_bytes(4)) . '.sql';

        $server = $this->serverConnection();
        $server->exec('DROP DATABASE IF EXISTS `' . $this->database->name . '`');
        $server->exec('CREATE DATABASE `' . $this->database->name . '`');

        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_probe` (`id` INT PRIMARY KEY, `note` VARCHAR(32))');
        $pdo->exec('CREATE TRIGGER `modx_probe_before_insert` BEFORE INSERT ON `modx_probe` '
            . "FOR EACH ROW SET NEW.`note` = 'set-by-trigger'");
        $pdo->exec('INSERT INTO `modx_probe` (`id`) VALUES (1)');
    }

    protected function tearDown(): void
    {
        $this->removeBinDirectories();

        // setUp() may have stopped on an assertTrue() that did not pass (no clients while the
        // mysql-client-tools group was requested explicitly) — then there is nothing to clean up, and
        // touching uninitialised properties would turn that refusal into a separate error on top.
        if (!isset($this->database)) {
            return;
        }

        foreach ([$this->file, $this->file . '.part'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        try {
            $this->serverConnection()->exec('DROP DATABASE IF EXISTS `' . $this->database->name . '`');
        } catch (Throwable) {
            // Cleanup "where possible": an unreachable DBMS must not break the test report.
        }
    }

    public function testSnapshotTakenByMysqldumpIsNeverHandedToTheOtherStrategy(): void
    {
        $manager = new SnapshotManager($this->database, $this->file);

        self::assertSame(MysqlDumper::FORMAT, $manager->format());

        $manager->capture();

        // It is these two lines that make the snapshot unreadable for the php strategy.
        $snapshot = (string) file_get_contents($this->file);
        self::assertStringContainsString('DELIMITER', $snapshot);
        self::assertStringContainsString('modx_probe_before_insert', $snapshot);

        // The clients vanished from PATH — exactly what PATH differs by between a terminal, an IDE,
        // `make` and CI. The snapshot format is recorded in the lock while this happens.
        try {
            $this->withPath($this->binDirectoryWith([]), fn (): SnapshotManager => new SnapshotManager(
                $this->database,
                $this->file,
                recordedFormat: $manager->format()
            ));
            self::fail('A mysqldump snapshot went to the php strategy instead of a refusal.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('the baseline was captured with the mysqldump client', $exception->getMessage());
        }

        // The main point: the database is untouched. The earlier behaviour wiped it out entirely and
        // failed on DELIMITER only after that, leaving the environment half dismantled.
        self::assertSame('set-by-trigger', $this->probeNote());
    }

    /**
     * The other side: a mysqldump snapshot read by its own strategy restores both the data and the
     * trigger — that is, the refusal above is not "just in case" but stands in place of a working
     * path.
     */
    public function testSnapshotTakenByMysqldumpIsRestoredByTheSameStrategy(): void
    {
        $manager = new SnapshotManager($this->database, $this->file);
        $manager->capture();

        $pdo = $this->connection();
        $pdo->exec('DROP TABLE `modx_probe`');

        $manager->restore();

        self::assertSame('set-by-trigger', $this->probeNote());

        // The trigger survived the round trip: the insert gets its value from it again.
        $pdo->exec('INSERT INTO `modx_probe` (`id`) VALUES (2)');
        $statement = $pdo->query('SELECT `note` FROM `modx_probe` WHERE `id` = 2');

        self::assertNotFalse($statement);
        self::assertSame('set-by-trigger', (string) $statement->fetchColumn());
    }

    /**
     * `load()` must bring the database back to EXACTLY the state of the snapshot — the contract of
     * {@see Dumper}. The enumeration of objects used to be filtered by `Table_type = 'BASE TABLE'`,
     * so a view created AFTER the capture survived the restore: `DROP TABLE` does not touch it, and
     * it is not in the dump. From there it either went on answering with data that is not in the
     * baseline, or, having a name matching a table of the snapshot, broke `CREATE TABLE`.
     */
    public function testLoadRemovesViewsCreatedAfterTheSnapshot(): void
    {
        $manager = new SnapshotManager($this->database, $this->file);
        $manager->capture();

        $pdo = $this->connection();
        $pdo->exec('CREATE VIEW `modx_probe_view` AS SELECT `id` FROM `modx_probe`');
        $pdo->exec('CREATE TABLE `modx_created_later` (`id` INT PRIMARY KEY)');
        // A trigger created after the capture goes away together with its table: `DROP TABLE` drops the
        // table's triggers, and the dump recreates the table. It needs no separate step — that is
        // checked right here, so that the claim does not stay a piece of reasoning.
        $pdo->exec('CREATE TRIGGER `modx_probe_after_insert` AFTER INSERT ON `modx_probe` '
            . 'FOR EACH ROW SET @tb_probe = 1');

        $manager->restore();

        self::assertSame(['modx_probe'], $this->baseTables());
        self::assertSame([], $this->views());
        self::assertSame(['modx_probe_before_insert'], $this->triggers());
    }

    /**
     * @return list<string>
     */
    private function baseTables(): array
    {
        return $this->names("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    }

    /**
     * @return list<string>
     */
    private function views(): array
    {
        return $this->names("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
    }

    /**
     * @return list<string>
     */
    private function triggers(): array
    {
        return $this->names(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
            . "WHERE TRIGGER_SCHEMA = '{$this->database->name}' ORDER BY TRIGGER_NAME"
        );
    }

    /**
     * @return list<string>
     */
    private function names(string $query): array
    {
        $statement = $this->connection()->query($query);

        self::assertNotFalse($statement);

        $names = [];

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            if (is_array($row) && isset($row[0]) && is_string($row[0])) {
                $names[] = $row[0];
            }
        }

        return $names;
    }

    private function probeNote(): string
    {
        $statement = $this->connection()->query('SELECT `note` FROM `modx_probe` WHERE `id` = 1');

        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
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

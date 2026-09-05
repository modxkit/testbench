<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Installer;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Installer\TestingDefaults;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The settings the install must write into the database itself. The work is done in a separate
 * database with a single table: what is checked is the write and its absence, not the behaviour of
 * the MODX core.
 */
#[Group('integration')]
final class TestingDefaultsTest extends TestCase
{
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
        $this->dbName = RunScopedDatabaseName::forBase('modx_testbench_defaults_test');

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

        $server = $this->server();
        $server->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        $server->exec('CREATE DATABASE `' . $this->dbName . '`');

        $this->connection()->exec(
            'CREATE TABLE `modx_system_settings` (`key` VARCHAR(50) PRIMARY KEY, value TEXT) ENGINE=InnoDB'
        );
    }

    protected function tearDown(): void
    {
        // The same guard as in the neighbouring classes (a comparison with the ACTUAL env name rather
        // than just "drop it and forget"): if it coincides with the ambient environment's own database
        // it must not be dropped — that would be a developer's real database rather than a test
        // fixture. With a computed name (fingerprint plus pid) a coincidence is practically impossible,
        // but the guard was cheap and stays.
        if (DatabaseConfig::fromEnvironment()->name === $this->dbName) {
            return;
        }

        try {
            $this->server()->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        } catch (Throwable) {
            // Cleanup "where possible".
        }
    }

    public function testApplyTurnsTheSettingOff(): void
    {
        $this->connection()->exec("INSERT INTO `modx_system_settings` VALUES ('log_deprecated', '1')");

        (new TestingDefaults())->apply($this->database);

        self::assertSame('0', $this->value('log_deprecated'));
    }

    /**
     * A value that already equals the required one counts as an UNCHANGED row for MySQL:
     * `rowCount()` would return zero both there and when the key is missing entirely. The two
     * situations must be told apart.
     */
    public function testApplyAcceptsASettingThatIsAlreadyOff(): void
    {
        $this->connection()->exec("INSERT INTO `modx_system_settings` VALUES ('log_deprecated', '0')");

        (new TestingDefaults())->apply($this->database);

        self::assertSame('0', $this->value('log_deprecated'));
    }

    public function testApplyRefusesWhenTheSettingIsMissingInsteadOfDoingNothing(): void
    {
        try {
            (new TestingDefaults())->apply($this->database);
            self::fail('A missing key went through as a silent no-op.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('log_deprecated', $exception->getMessage());
            // The advice must lead to a solution: a reinstall against this cause gives exactly the same
            // result, that is, an endless loop.
            self::assertStringNotContainsString('MODX_TESTBENCH_FORCE_INSTALL', $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_VERSION', $exception->getMessage());
        }
    }

    private function value(string $key): string
    {
        $statement = $this->connection()->prepare('SELECT value FROM `modx_system_settings` WHERE `key` = ?');
        $statement->execute([$key]);

        return (string) $statement->fetchColumn();
    }

    private function server(): PDO
    {
        return new PDO(
            $this->database->dsnWithoutDatabase(),
            $this->database->user,
            $this->database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
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

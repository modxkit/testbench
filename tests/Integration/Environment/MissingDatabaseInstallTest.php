<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Environment;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The package did not create the database and delegated that to the installer (FR-INSTALL-5). In
 * fact the MODX CLI setup, finding no target database, silently sets
 * `OPT_OVERRIDE_TABLE_TYPE = 'MyISAM'` and returns a `warning` without stopping: the install
 * "succeeds", all 70 tables become MyISAM, and `rollBack()` after that runs to no effect — the
 * package's isolation is off entirely, without a word said about it.
 *
 * The package's own suite could not see this by construction: all its databases are created in
 * advance.
 */
#[Group('integration')]
final class MissingDatabaseInstallTest extends TestCase
{
    /**
     * The name is derived from the run (the environment fingerprint plus the pid) rather than
     * hard-coded — otherwise two runs against one DBMS server wiped out each other's databases in
     * the middle of a foreign test (found live by three reviewers). The compromise of the scheme and
     * its limitations are in {@see RunScopedDatabaseName}.
     */
    private string $dbName;

    /** @var array<string, string|null> */
    private array $previousEnvironment = [];

    private ?string $temporaryWorkspace = null;

    protected function setUp(): void
    {
        $this->dbName = RunScopedDatabaseName::forBase('modx_testbench_missing_db_test');
    }

    protected function tearDown(): void
    {
        if ($this->temporaryWorkspace !== null) {
            exec('rm -rf ' . escapeshellarg($this->temporaryWorkspace));
            $this->temporaryWorkspace = null;
        }

        $this->dropTestDatabase();

        foreach ($this->previousEnvironment as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        $this->previousEnvironment = [];
        TestbenchKernel::reset();

        parent::tearDown();
    }

    public function testInstallationIntoAMissingDatabaseProducesTransactionalTables(): void
    {
        $this->temporaryWorkspace = sys_get_temp_dir() . '/modx-testbench-missing-db-' . bin2hex(random_bytes(4));

        foreach ([
            'MODX_TESTBENCH_WORKSPACE' => $this->temporaryWorkspace,
            'MODX_TESTBENCH_DB_NAME' => $this->dbName,
            'MODX_TESTBENCH_FORCE_INSTALL' => '1',
        ] as $key => $value) {
            $previous = $_SERVER[$key] ?? null;
            $this->previousEnvironment[$key] = is_string($previous) ? $previous : null;
            $_SERVER[$key] = $value;
        }

        TestbenchKernel::reset();

        // The database is missing — exactly the situation the package led the consumer into by default.
        $this->server()->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        self::assertSame([], $this->schemata());

        TestbenchKernel::instance()->prepare();

        $tables = $this->tableEngines();

        self::assertGreaterThan(60, count($tables), 'The install created no core tables.');
        self::assertSame(
            [],
            array_keys(array_filter($tables, static fn (string $engine): bool => $engine !== 'InnoDB')),
            'The tables were created by an engine without rollback support — transaction isolation does not work.'
        );
    }

    private function server(): PDO
    {
        $database = DatabaseConfig::fromEnvironment();

        return new PDO(
            $database->dsnWithoutDatabase(),
            $database->user,
            $database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * @return list<string>
     */
    private function schemata(): array
    {
        $statement = $this->server()->prepare(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $statement->execute([$this->dbName]);

        $names = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function tableEngines(): array
    {
        $statement = $this->server()->prepare(
            'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $statement->execute([$this->dbName]);

        $engines = [];

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            if (is_array($row) && is_string($row[0] ?? null) && is_string($row[1] ?? null)) {
                $engines[$row[0]] = $row[1];
            }
        }

        return $engines;
    }

    private function dropTestDatabase(): void
    {
        // The same guard as in the neighbouring tests: only OUR OWN database is dropped.
        if (DatabaseConfig::fromEnvironment()->name !== $this->dbName) {
            return;
        }

        try {
            $this->server()->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        } catch (Throwable) {
            // Cleanup "where possible".
        }
    }
}

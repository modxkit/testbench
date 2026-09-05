<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Installer;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\Provider\ZipReleaseProvider;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Exception\InstallationFailedException;
use ModxKit\Testbench\Installer\HeadlessInstaller;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('integration')]
final class HeadlessInstallerTest extends TestCase
{
    /**
     * The name is derived from the run (the environment fingerprint plus the pid) rather than
     * hard-coded — otherwise two runs against one DBMS server wiped out each other's databases in
     * the middle of a foreign test (found live by three reviewers). The compromise of the scheme and
     * its limitations are in {@see RunScopedDatabaseName}. The earlier justification for a constant was that it
     * differed from a developer's default `MODX_TESTBENCH_DB_NAME` ("modx_testbench") — a computed
     * name gives the same thing and, beyond that, without colliding with a foreign run.
     */
    private string $dbName;

    private ?string $target = null;

    private ?string $previousDbName = null;

    private ?string $previousDbPass = null;

    protected function setUp(): void
    {
        $this->dbName = RunScopedDatabaseName::forBase('modx_testbench_headless_installer_test');

        $previousDbName = $_SERVER['MODX_TESTBENCH_DB_NAME'] ?? null;
        $this->previousDbName = is_string($previousDbName) ? $previousDbName : null;
        $_SERVER['MODX_TESTBENCH_DB_NAME'] = $this->dbName;

        $previousDbPass = $_SERVER['MODX_TESTBENCH_DB_PASS'] ?? null;
        $this->previousDbPass = is_string($previousDbPass) ? $previousDbPass : null;
    }

    protected function tearDown(): void
    {
        // Runs even when the test body throws or an assertion fails, so a failed run never
        // leaves a broken database or an unpruned workspace directory behind.
        if ($this->target !== null) {
            exec('rm -rf ' . escapeshellarg($this->target));
            $this->target = null;
        }

        if ($this->previousDbPass === null) {
            unset($_SERVER['MODX_TESTBENCH_DB_PASS']);
        } else {
            $_SERVER['MODX_TESTBENCH_DB_PASS'] = $this->previousDbPass;
        }

        $this->dropTestDatabase();

        if ($this->previousDbName === null) {
            unset($_SERVER['MODX_TESTBENCH_DB_NAME']);
        } else {
            $_SERVER['MODX_TESTBENCH_DB_NAME'] = $this->previousDbName;
        }
    }

    public function testInstallsModxWithoutInteraction(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $this->target = sys_get_temp_dir() . '/modx-install-' . bin2hex(random_bytes(4));

        $core = (new ZipReleaseProvider($config->version, $config->cacheDir))->provide($this->target);

        (new HeadlessInstaller())->install($core, $config);

        self::assertFileExists($core->corePath() . 'config/config.inc.php');

        $pdo = new PDO($config->database->dsn(), $config->database->user, $config->database->password);
        $tables = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($config->database->prefix . '%'));

        self::assertNotFalse($tables);
        self::assertGreaterThan(10, count($tables->fetchAll()));
    }

    public function testFailsDiagnosablyWithWrongDatabasePassword(): void
    {
        $wrongPassword = 'wrong-' . bin2hex(random_bytes(4));
        $_SERVER['MODX_TESTBENCH_DB_PASS'] = $wrongPassword;

        $config = TestbenchConfig::fromEnvironment();
        $this->target = sys_get_temp_dir() . '/modx-install-' . bin2hex(random_bytes(4));

        $core = (new ZipReleaseProvider($config->version, $config->cacheDir))->provide($this->target);

        try {
            (new HeadlessInstaller())->install($core, $config);
            self::fail('Expected InstallationFailedException was not thrown.');
        } catch (InstallationFailedException $exception) {
            $message = $exception->getMessage();

            self::assertStringContainsString($core->setupPath() . 'index.php', $message);
            self::assertStringContainsString($core->setupPath() . 'config.xml', $message);
            self::assertStringNotContainsString($wrongPassword, $message);
        }
    }

    private function dropTestDatabase(): void
    {
        $database = DatabaseConfig::fromEnvironment();

        if ($database->name !== $this->dbName) {
            return;
        }

        try {
            $pdo = new PDO($database->dsnWithoutDatabase(), $database->user, $database->password);
            $pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        } catch (Throwable) {
            // Best-effort cleanup: a database that was never created (e.g. the wrong-password
            // test) or an unreachable server must not turn cleanup itself into a test failure.
        }
    }
}

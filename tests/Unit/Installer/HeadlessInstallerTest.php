<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Installer;

use ModxKit\Testbench\Exception\InstallationFailedException;
use ModxKit\Testbench\Installer\HeadlessInstaller;
use ModxKit\Testbench\Support\ProcessResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises HeadlessInstaller's private failure-detection logic (evaluateOutcome()) directly via
 * reflection, with hand-built ProcessResult instances and temp files — no real installer run, no database,
 * and no mocking of the final ProcessRunner/ConfigXmlWriter/CorePreparer collaborators.
 */
#[Group('unit')]
final class HeadlessInstallerTest extends TestCase
{
    private const REAL_CONFIG_INC = <<<'PHP'
        <?php
        $database_type = 'mysql';
        if (!defined('MODX_CORE_PATH')) {
            define('MODX_CORE_PATH', '/tmp/env/core/');
        }
        PHP;

    private const STUB_CONFIG_INC = '<?php // MODX configuration file';

    private string $configIncFile;

    private HeadlessInstaller $installer;

    protected function setUp(): void
    {
        $this->configIncFile = sys_get_temp_dir() . '/headless-installer-test-' . bin2hex(random_bytes(4)) . '.php';
        $this->installer = new HeadlessInstaller();
    }

    protected function tearDown(): void
    {
        if (is_file($this->configIncFile)) {
            unlink($this->configIncFile);
        }
    }

    public function testPassesOnCleanSuccessfulOutput(): void
    {
        self::expectNotToPerformAssertions();

        file_put_contents($this->configIncFile, self::REAL_CONFIG_INC);

        $this->evaluateOutcome(new ProcessResult(0, 'Installation finished in 2.5 s', ''));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function representativeFailureMarkers(): array
    {
        // A representative sample across distinct lexicon/log families, not all eleven markers:
        // an xPDO error-level log line, a DB-creation failure, a pre-install test failure, a
        // generic PHP fatal, and a table-prefix collision.
        return [
            'xPDO error-level log line' => ['(ERROR) Something went wrong'],
            'db_err_create_database' => ['MODX could not create your database. Please manually create your database and then try again.'],
            'cli_tests_failed' => ['Pre-Install Tests Failed! Errors: php_zip: PHP Zip extension not installed'],
            'generic PHP fatal' => ['Fatal error: Uncaught Error in setup/index.php'],
            'test_table_prefix_inuse' => ['Table prefix is already in use in this database!'],
            'mysql_version_fail' => ['You are running on MySQL 4.0.1, and MODX Revolution requires MySQL 4.1.20 or later.'],
            'mysql_version_5051' => ['MODX will have issues on your MySQL version (5.0.51a), because of the many bugs related to the PDO drivers on this version.'],
        ];
    }

    #[DataProvider('representativeFailureMarkers')]
    public function testThrowsWhenOutputContainsAFailureMarker(string $outputContaining): void
    {
        file_put_contents($this->configIncFile, self::REAL_CONFIG_INC);

        try {
            $this->evaluateOutcome(new ProcessResult(0, $outputContaining, ''));
            self::fail('Expected InstallationFailedException was not thrown.');
        } catch (InstallationFailedException $exception) {
            self::assertStringContainsString('failure marker', $exception->getMessage());
            self::assertStringContainsString($outputContaining, $exception->getMessage());
        }
    }

    public function testThrowsWhenConfigIncFileIsMissing(): void
    {
        // Deliberately not created.
        try {
            $this->evaluateOutcome(new ProcessResult(0, 'Installation finished in 2.5 s', ''));
            self::fail('Expected InstallationFailedException was not thrown.');
        } catch (InstallationFailedException $exception) {
            self::assertStringContainsString('was not created', $exception->getMessage());
        }
    }

    public function testThrowsWhenConfigIncFileIsTheEmptyStub(): void
    {
        // Reproduces the real trap: the installer's own DB-connect failure message does not match
        // any FAILURE_MARKERS entry by design of this test (clean-looking output), yet the
        // config.inc.php it left behind is the placeholder, not a real configuration.
        file_put_contents($this->configIncFile, self::STUB_CONFIG_INC);

        try {
            $this->evaluateOutcome(new ProcessResult(0, 'Installation finished in 2.5 s', ''));
            self::fail('Expected InstallationFailedException was not thrown.');
        } catch (InstallationFailedException $exception) {
            self::assertStringContainsString('empty template', $exception->getMessage());
        }
    }

    public function testThrowsWhenExitCodeIsNonZero(): void
    {
        file_put_contents($this->configIncFile, self::REAL_CONFIG_INC);

        try {
            $this->evaluateOutcome(new ProcessResult(124, '', 'timed out'));
            self::fail('Expected InstallationFailedException was not thrown.');
        } catch (InstallationFailedException $exception) {
            self::assertStringContainsString('code 124', $exception->getMessage());
        }
    }

    /**
     * The markers are looked for in the RAW output, while only the exception text is masked. The
     * test therefore hands the method the raw output and the passwords separately — the earlier
     * version masked the output itself and passed an already masked one, that is, it checked
     * exactly the order in which the defect lived.
     */
    public function testMasksBothPasswordsInTheThrownMessage(): void
    {
        $dbPassword = 'super-secret-db-pw';
        $adminPassword = 'super-secret-admin-pw';
        $rawOutput = "connecting with password {$dbPassword}\nadmin password is {$adminPassword}\n(ERROR) connection refused";

        file_put_contents($this->configIncFile, self::REAL_CONFIG_INC);

        try {
            $this->evaluateOutcome(new ProcessResult(0, $rawOutput, ''), $dbPassword, $adminPassword);
            self::fail('Expected InstallationFailedException was not thrown.');
        } catch (InstallationFailedException $exception) {
            self::assertStringNotContainsString($dbPassword, $exception->getMessage());
            self::assertStringNotContainsString($adminPassword, $exception->getMessage());
            self::assertStringContainsString('***', $exception->getMessage());
        }
    }

    private function evaluateOutcome(ProcessResult $result, string ...$secrets): void
    {
        $method = new ReflectionMethod($this->installer, 'evaluateOutcome');
        $method->invoke(
            $this->installer,
            $result,
            $result->output(),
            $this->configIncFile,
            ['php', 'setup/index.php', '--installmode=new'],
            '/tmp/env/setup/config.xml',
            ...$secrets
        );
    }
}

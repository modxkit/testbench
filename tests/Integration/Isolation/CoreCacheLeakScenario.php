<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use Symfony\Component\Process\Process;

/**
 * The fourth vessel of state: the core's file cache in `core/cache/`.
 *
 * `modX::reloadConfig()` calls `cacheManager->refresh()`, and that one regenerates
 * `core/cache/system_settings/config.cache.php` from the DIRTY, not yet rolled back state of the
 * database. At least 20 of the core's own processors do this, `System/Settings/Update` and
 * `Workspace/Packages/Install` (that is, the `TransportInstaller` path) among them. The
 * contamination survives the process boundary: the row is already gone from the database, while
 * `getOption()` in a NEW PHP process returns the value of the rolled back test — a violation of
 * NFR-2 in its worst form, one that "restart the tests" does not cure.
 */
abstract class CoreCacheLeakScenario extends TestCase
{
    private const SETTING = 'site_name';

    public function testProcessorWritesTheDirtyValueIntoTheFileCache(): string
    {
        $value = 'testbench-leak-' . bin2hex(random_bytes(4));

        $response = $this->runProcessor('system/settings/update', [
            'key' => self::SETTING,
            'value' => $value,
            'namespace' => 'core',
            'area' => 'site',
            'xtype' => 'textfield',
        ]);

        self::assertTrue($response->isError() === false, $response->getMessage());
        self::assertSame($value, $this->modx->getOption(self::SETTING));

        return $value;
    }

    /**
     * Reads the setting in a NEW PHP process: only that one sees the file cache rather than the
     * `$modx->config` of the previous test left in memory.
     */
    #[Depends('testProcessorWritesTheDirtyValueIntoTheFileCache')]
    public function testRolledBackValueIsInvisibleToANewPhpProcess(string $value): void
    {
        // The row is already gone from the database — the transaction was rolled back in the previous test's tearDown().
        $prefix = $this->modx->getOption('table_prefix');
        self::assertIsString($prefix);

        $statement = $this->modx->query(
            'SELECT value FROM `' . $prefix . 'system_settings` WHERE `key` = '
            . $this->modx->quote(self::SETTING)
        );
        self::assertNotFalse($statement);
        self::assertNotSame($value, $statement->fetchColumn());

        $process = new Process(
            [PHP_BINARY, __DIR__ . '/read-setting.php', self::SETTING],
            null,
            ['MODX_TESTBENCH_LEAK_PROBE' => '1'],
            null,
            120
        );
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        self::assertNotSame(
            $value,
            trim($process->getOutput()),
            'The value of a rolled back test survived the rollback in the core file cache (core/cache/).'
        );
    }
}

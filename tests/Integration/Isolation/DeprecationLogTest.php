<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * The sixth vessel: `modX::deprecated()`.
 *
 * The core accumulates deprecated-API marks in memory and saves them from
 * `register_shutdown_function()` — that is, AFTER the last `tearDown()`, past the transaction, past
 * the snapshot and past any other isolation. Any extra written against the MODX 2.x API would drip
 * rows into `modx_deprecated_method`/`modx_deprecated_call` on every run and would spoil the
 * baseline when it is retaken. The solution: in the test environment the deprecated-API log is
 * turned off — `log_deprecated = 0` extinguishes `deprecated()` on its very first line
 * (`core/src/Revolution/modX.php:2482`), before `register_shutdown_function()`.
 */
#[Group('integration')]
final class DeprecationLogTest extends TestCase
{
    public function testDeprecationsAreNotWrittenAtShutdown(): void
    {
        // Counted from a FRESH connection: the core connection sits inside the test's transaction, and
        // REPEATABLE READ would hand it one and the same snapshot before and after the subprocess —
        // the check would degenerate into comparing a number with itself.
        $before = $this->deprecationRowCounts();

        // A separate process: the rows appear only at its shutdown, so checking this in the current
        // PHPUnit process is impossible in principle.
        $process = new Process([PHP_BINARY, __DIR__ . '/deprecated-probe.php'], null, null, null, 120);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('off', trim($process->getOutput()), 'log_deprecated is not turned off in the test core.');

        self::assertSame(
            $before,
            $this->deprecationRowCounts(),
            'A deprecated-API mark was written at shutdown — past any isolation.'
        );
    }

    /**
     * @return array<string, int>
     */
    private function deprecationRowCounts(): array
    {
        $database = DatabaseConfig::fromEnvironment();
        $connection = new PDO(
            $database->dsn(),
            $database->user,
            $database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $counts = [];

        foreach (['deprecated_method', 'deprecated_call'] as $table) {
            $name = $database->prefix . $table;
            $statement = $connection->query('SELECT COUNT(*) FROM `' . $name . '`');

            self::assertNotFalse($statement);
            $counts[$name] = (int) $statement->fetchColumn();
        }

        return $counts;
    }

    /**
     * The point is a write to the DATABASE, not to memory: a `setOption()` applied while the core
     * loads is wiped out by `modX::reloadConfig()`, which at least 20 of the core's own processors
     * call.
     *
     * So the value is read from the database rather than through `getOption()`: the latter would
     * return what the bootstrapper put there, and the assertion would pass even against a database
     * with `log_deprecated = 1`. The second assertion checks the same thing from the other side —
     * that the setting survives a real `reloadConfig()`.
     */
    public function testDeprecationLoggingIsDisabledInTheInstallationItself(): void
    {
        self::assertSame('0', $this->settingValueInDatabase('log_deprecated'));

        $this->modx->reloadConfig();

        self::assertFalse((bool) $this->modx->getOption('log_deprecated'));
    }

    private function settingValueInDatabase(string $key): string
    {
        $database = DatabaseConfig::fromEnvironment();
        $connection = new PDO(
            $database->dsn(),
            $database->user,
            $database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $statement = $connection->prepare(
            'SELECT value FROM `' . $database->prefix . 'system_settings` WHERE `key` = ?'
        );
        $statement->execute([$key]);

        return (string) $statement->fetchColumn();
    }
}

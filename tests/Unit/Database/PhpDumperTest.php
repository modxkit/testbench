<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Database;

use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The refusals that are visible before connecting to the DBMS at all. The behaviour of the
 * strategy against a live database is checked by
 * {@see \ModxKit\Testbench\Tests\Integration\Database\PhpDumperTest}.
 */
#[Group('unit')]
final class PhpDumperTest extends TestCase
{
    public function testStrategyIsAlwaysAvailable(): void
    {
        self::assertTrue((new PhpDumper())->isAvailable());
    }

    public function testLoadRefusesAMissingSnapshot(): void
    {
        $file = sys_get_temp_dir() . '/tb-missing-' . bin2hex(random_bytes(4)) . '.sql';

        try {
            (new PhpDumper())->load($this->database(), $file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('snapshot file not found: ' . $file, $exception->getMessage());
            self::assertStringContainsString('strategy php', $exception->getMessage());
        }
    }

    public function testDumpRefusesAnUnwritableTargetDirectoryInsteadOfWarning(): void
    {
        $directory = sys_get_temp_dir() . '/tb-absent-' . bin2hex(random_bytes(4));
        $file = $directory . '/snapshot.sql';

        try {
            (new PhpDumper())->dump($this->database(), $file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString(
                'the snapshot directory is not writable: ' . $directory,
                $exception->getMessage()
            );
        }

        self::assertFileDoesNotExist($file);
    }

    /**
     * NFR-3: what comes out is the package's own exception — but the genuine driver error must
     * travel with it as `previous`, otherwise the stack down to the real point of failure is lost
     * and "the DBMS is unreachable" has to be guessed from the text of the message.
     */
    public function testConnectionFailureCarriesTheDriverErrorAsPrevious(): void
    {
        $file = sys_get_temp_dir() . '/tb-unreachable-' . bin2hex(random_bytes(4)) . '.sql';
        // Port 1 is closed on any machine: the connection is refused at once, there is nothing to wait for.
        $unreachable = new DatabaseConfig(
            host: '127.0.0.1',
            port: 1,
            name: 'modx_testbench',
            user: 'tester',
            password: 'secret',
            prefix: 'modx_',
            charset: 'utf8mb4',
            collation: 'utf8mb4_general_ci',
        );

        try {
            (new PhpDumper())->dump($unreachable, $file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function database(): DatabaseConfig
    {
        return new DatabaseConfig(
            host: '127.0.0.1',
            port: 3306,
            name: 'modx_testbench',
            user: 'tester',
            password: 'secret',
            prefix: 'modx_',
            charset: 'utf8mb4',
            collation: 'utf8mb4_general_ci',
        );
    }
}

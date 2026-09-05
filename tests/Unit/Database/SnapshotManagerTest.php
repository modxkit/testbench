<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Database;

use ModxKit\Testbench\Database\MysqlDumper;
use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Database\SnapshotFile;
use ModxKit\Testbench\Database\SnapshotManager;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use ModxKit\Testbench\Tests\Support\ClientPathControl;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SnapshotManagerTest extends TestCase
{
    use ClientPathControl;

    /** A script impersonating a working MySQL client: availability is checked by launching it. */
    private const WORKING_CLIENT = "#!/bin/sh\nexit 0\n";

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/tb-snapshot-unit-' . bin2hex(random_bytes(4)) . '.sql';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        $this->removeBinDirectories();
    }

    public function testCaptureAndRestoreDelegateToTheStrategy(): void
    {
        $database = $this->database();
        $dumper = new RecordingDumper();
        $manager = new SnapshotManager($database, $this->file, $dumper);

        $manager->capture();
        $manager->restore();

        self::assertSame(
            [
                ['operation' => 'dump', 'database' => $database, 'file' => $this->file],
                ['operation' => 'load', 'database' => $database, 'file' => $this->file],
            ],
            $dumper->calls
        );
        self::assertSame($this->file, $manager->path());
    }

    /**
     * A non-empty file is not yet a snapshot. An interrupted `capture()` leaves a truncated file,
     * and restoring from it would destroy the database, bringing it back only in part — only a file
     * with the completion marker at its tail counts as sound.
     */
    public function testSnapshotDoesNotExistUntilCaptureCompleted(): void
    {
        $manager = new SnapshotManager($this->database(), $this->file, new RecordingDumper());

        self::assertFalse($manager->exists());

        touch($this->file);

        self::assertFalse($manager->exists());

        // A break exactly at a statement boundary: the earlier guard (`filesize() > 0`) considered
        // such a file sound, and the restore wiped the database silently.
        file_put_contents($this->file, "SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS `modx_probe`;\n");

        self::assertFalse($manager->exists());

        file_put_contents($this->file, SnapshotFile::completionLine(1), FILE_APPEND);

        self::assertTrue($manager->exists());
    }

    /**
     * The strategy is decided by the ORIGIN of the snapshot, recorded in `testbench.lock.json`, and
     * not by what happens to be in PATH. The MySQL clients are present here — and still a snapshot
     * captured by the php strategy is read by the php strategy.
     */
    public function testRecordedFormatDecidesTheStrategyEvenWhenBothAreAvailable(): void
    {
        $clients = $this->clientsAvailable();

        self::assertSame(PhpDumper::FORMAT, $this->manager(PhpDumper::FORMAT, $clients)->format());
        self::assertSame(MysqlDumper::FORMAT, $this->manager(MysqlDumper::FORMAT, $clients)->format());
    }

    /**
     * There is nothing to read a mysqldump snapshot with when the mysqldump clients are absent —
     * and handing it silently to the php strategy is not allowed: that one knows neither the
     * client-side `DELIMITER` command nor views with triggers, and would break off only AFTER the
     * database had been cleaned. The refusal must sound BEFORE anything is touched.
     */
    public function testRecordedMysqlFormatIsRefusedWhenTheClientsAreGone(): void
    {
        try {
            $this->manager(MysqlDumper::FORMAT, $this->clientsMissing());
            self::fail('A mysqldump snapshot went silently to another strategy.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('the baseline was captured with the mysqldump client', $exception->getMessage());
            self::assertStringContainsString('Nothing was touched', $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_FORCE_INSTALL=1', $exception->getMessage());
        }
    }

    public function testRecordedPhpFormatNeedsNoClientsAtAll(): void
    {
        self::assertSame(
            PhpDumper::FORMAT,
            $this->manager(PhpDumper::FORMAT, $this->clientsMissing())->format()
        );
    }

    /**
     * A lock WITHOUT a recorded format is a legitimate input (the environment is only being set
     * up). The strategy is then chosen by availability, and the format is recorded from its
     * outcome.
     */
    public function testStrategyIsProbedWhenNoFormatWasRecorded(): void
    {
        self::assertSame(PhpDumper::FORMAT, $this->manager('', $this->clientsMissing())->format());
        self::assertSame(MysqlDumper::FORMAT, $this->manager('', $this->clientsAvailable())->format());
    }

    /**
     * MariaDB 12 has no `mysql`/`mysqldump` files at all — only `mariadb` and `mariadb-dump`
     * (measured on `mariadb:latest`, 12.3.2; 11.8.6 still has the symlinks). To the package such a
     * runner looked like a runner WITHOUT clients: `isAvailable()` looked for exactly two names,
     * did not find them and silently took the snapshot to the php strategy, losing views and
     * triggers. A silent fallback is not acceptable.
     *
     * The proof is specifically about the names: the directory contains NEITHER `mysqldump` NOR
     * `mysql`, so a strategy with the earlier pair of names would be unavailable and the format
     * would come out as `php`.
     */
    public function testStrategyIsFoundWhenOnlyMariadbNamedClientsAreInstalled(): void
    {
        self::assertSame(MysqlDumper::FORMAT, $this->manager('', $this->mariadbNamedClients())->format());
    }

    /**
     * The same pair of names must serve for READING a snapshot captured by a client too: otherwise
     * an environment installed on a runner with Oracle clients would refuse to be restored on a
     * runner with MariaDB 12 clients — even though the snapshot format is one and the same.
     */
    public function testRecordedMysqlFormatIsAcceptedWithMariadbNamedClients(): void
    {
        self::assertSame(
            MysqlDumper::FORMAT,
            $this->manager(MysqlDumper::FORMAT, $this->mariadbNamedClients())->format()
        );
    }

    public function testUnknownRecordedFormatIsRefusedInsteadOfGuessed(): void
    {
        try {
            $this->manager('sqlite', $this->clientsAvailable());
            self::fail('An unknown snapshot format did not cause a refusal.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('unknown snapshot format "sqlite"', $exception->getMessage());
        }
    }

    private function manager(string $recordedFormat, string $path): SnapshotManager
    {
        return $this->withPath($path, fn (): SnapshotManager => new SnapshotManager(
            $this->database(),
            $this->file,
            recordedFormat: $recordedFormat
        ));
    }

    private function clientsAvailable(): string
    {
        return $this->binDirectoryWith([
            'mysqldump' => self::WORKING_CLIENT,
            'mysql' => self::WORKING_CLIENT,
        ]);
    }

    /**
     * A MariaDB 12 runner: the names `mysqldump`/`mysql` are not in the directory at all.
     */
    private function mariadbNamedClients(): string
    {
        return $this->binDirectoryWith([
            'mariadb-dump' => self::WORKING_CLIENT,
            'mariadb' => self::WORKING_CLIENT,
        ]);
    }

    private function clientsMissing(): string
    {
        return $this->binDirectoryWith([]);
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

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Database;

use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Database\SnapshotManager;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\TestbenchConfig;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A snapshot of an installed MODX environment: the real test database is captured and restored, so
 * the checks must not leave it changed.
 */
#[Group('integration')]
final class SnapshotManagerTest extends TestCase
{
    private DatabaseConfig $database;
    private string $file;

    /** @var array<string, mixed>|null */
    private ?array $removedSetting = null;

    protected function setUp(): void
    {
        $this->database = TestbenchConfig::fromEnvironment()->database;
        $this->file = sys_get_temp_dir() . '/tb-snapshot-' . bin2hex(random_bytes(4)) . '.sql';
    }

    protected function tearDown(): void
    {
        try {
            // The test edits the real environment database. If restore() did not work, a spoiled database
            // would drag every subsequent test of the run down with it, so it is repaired here: first by
            // loading the snapshot again (it is still on disk), then by putting the row itself back.
            $this->repairDatabase();
        } finally {
            // The file is removed on a failed test too: a snapshot of a MODX database weighs hundreds of kilobytes.
            if (is_file($this->file)) {
                unlink($this->file);
            }
        }
    }

    private function repairDatabase(): void
    {
        if ($this->removedSetting === null || $this->settingIsPresent()) {
            return;
        }

        if (is_file($this->file) && filesize($this->file) > 0) {
            // The snapshot is still on disk — restoring the whole database is the more reliable route.
            (new PhpDumper())->load($this->database, $this->file);
        }

        // IGNORE: if the snapshot has already brought the row back, a repeated insert is unnecessary and harmless.
        $columns = array_keys($this->removedSetting);
        $quoted = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $statement = $this->connection()->prepare(sprintf(
            'INSERT IGNORE INTO `%ssystem_settings` (%s) VALUES (%s)',
            $this->database->prefix,
            implode(', ', $quoted),
            implode(', ', array_fill(0, count($columns), '?'))
        ));
        $statement->execute(array_values($this->removedSetting));
    }

    private function settingIsPresent(): bool
    {
        try {
            $statement = $this->connection()->query(sprintf(
                "SELECT COUNT(*) FROM `%ssystem_settings` WHERE `key` = 'site_name'",
                $this->database->prefix
            ));

            return $statement !== false && (int) $statement->fetchColumn() === 1;
        } catch (PDOException) {
            // The table may not exist at all — then there is nothing to repair, let the insert below fail.
            return false;
        }
    }

    public function testRestoreUndoesChangesMadeAfterCapture(): void
    {
        $table = $this->database->prefix . 'system_settings';

        $manager = new SnapshotManager($this->database, $this->file, new PhpDumper());
        $manager->capture();

        self::assertTrue($manager->exists());

        $pdo = $this->connection();
        $removed = $pdo->query("SELECT * FROM `{$table}` WHERE `key` = 'site_name'");
        self::assertNotFalse($removed);
        $row = $removed->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        /** @var array<string, mixed> $row */
        $this->removedSetting = $row;

        $pdo->exec("DELETE FROM `{$table}` WHERE `key` = 'site_name'");

        $manager->restore();

        $statement = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `key` = 'site_name'");
        self::assertNotFalse($statement);
        self::assertSame('1', (string) $statement->fetchColumn());
    }

    public function testDefaultStrategyIsChosenWhenNoneGiven(): void
    {
        // A constructor without a strategy picks mysqldump if external MySQL clients are present and
        // the php strategy if they are not; in both cases the result is a working snapshot.
        $manager = new SnapshotManager($this->database, $this->file);

        self::assertFalse($manager->exists());

        $manager->capture();

        self::assertTrue($manager->exists());
        self::assertSame($this->file, $manager->path());
        self::assertStringContainsString(
            $this->database->prefix . 'system_settings',
            (string) file_get_contents($this->file)
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

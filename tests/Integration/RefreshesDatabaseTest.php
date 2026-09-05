<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration;

use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class RefreshesDatabaseTest extends TestCase
{
    use RefreshesDatabase;

    public function testDdlDoesNotBreakIsolation(): string
    {
        $table = 'tb_ddl_' . bin2hex(random_bytes(4));

        $this->modx->exec("CREATE TABLE `{$table}` (id INT PRIMARY KEY)");

        self::assertNotFalse($this->modx->query("SELECT 1 FROM `{$table}`"));

        return $table;
    }

    #[Depends('testDdlDoesNotBreakIsolation')]
    public function testTableFromPreviousTestIsGone(string $table): void
    {
        $statement = $this->modx->query('SHOW TABLES LIKE ' . $this->modx->quote($table));

        self::assertNotFalse($statement);
        self::assertSame([], $statement->fetchAll());
    }
}

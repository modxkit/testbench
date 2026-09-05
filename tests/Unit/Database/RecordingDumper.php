<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Database;

use ModxKit\Testbench\Database\Dumper;
use ModxKit\Testbench\Environment\DatabaseConfig;

/**
 * A fake strategy: it remembers which configuration and which file it was called with.
 */
final class RecordingDumper implements Dumper
{
    /** @var list<array{operation: string, database: DatabaseConfig, file: string}> */
    public array $calls = [];

    public function format(): string
    {
        return 'recording';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function dump(DatabaseConfig $database, string $file): void
    {
        $this->calls[] = ['operation' => 'dump', 'database' => $database, 'file' => $file];
    }

    public function load(DatabaseConfig $database, string $file): void
    {
        $this->calls[] = ['operation' => 'load', 'database' => $database, 'file' => $file];
    }
}

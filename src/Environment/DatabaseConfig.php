<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

use ModxKit\Testbench\Support\Env;

final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
        public string $prefix,
        public string $charset,
        public string $collation,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            host: Env::get('MODX_TESTBENCH_DB_HOST', '127.0.0.1') ?? '127.0.0.1',
            port: Env::int('MODX_TESTBENCH_DB_PORT', 3306),
            name: Env::get('MODX_TESTBENCH_DB_NAME', 'modx_testbench') ?? 'modx_testbench',
            user: Env::get('MODX_TESTBENCH_DB_USER', 'root') ?? 'root',
            password: Env::get('MODX_TESTBENCH_DB_PASS', '') ?? '',
            prefix: Env::get('MODX_TESTBENCH_DB_PREFIX', 'modx_') ?? 'modx_',
            charset: Env::get('MODX_TESTBENCH_DB_CHARSET', 'utf8mb4') ?? 'utf8mb4',
            collation: Env::get('MODX_TESTBENCH_DB_COLLATION', 'utf8mb4_general_ci') ?? 'utf8mb4_general_ci',
        );
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset
        );
    }

    public function dsnWithoutDatabase(): string
    {
        return sprintf('mysql:host=%s;port=%d;charset=%s', $this->host, $this->port, $this->charset);
    }
}

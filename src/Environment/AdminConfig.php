<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

use ModxKit\Testbench\Support\Env;

final readonly class AdminConfig
{
    public function __construct(
        public string $user,
        public string $password,
        public string $email,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            user: Env::get('MODX_TESTBENCH_ADMIN_USER', 'testbench') ?? 'testbench',
            password: Env::get('MODX_TESTBENCH_ADMIN_PASS', 'TestbenchPass123!') ?? 'TestbenchPass123!',
            email: Env::get('MODX_TESTBENCH_ADMIN_EMAIL', 'testbench@example.com') ?? 'testbench@example.com',
        );
    }
}

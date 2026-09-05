<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Support;

/**
 * @internal
 */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function output(): string
    {
        return trim($this->stdout . "\n" . $this->stderr);
    }
}

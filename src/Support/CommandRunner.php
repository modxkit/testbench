<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Support;

/**
 * Running an external command. The interface was extracted from {@see ProcessRunner} so that its
 * consumers (for example {@see \ModxKit\Testbench\Database\MysqlDumper}) can be checked with a
 * substitute implementation: ProcessRunner is declared final and cannot be mocked by PHPUnit, and
 * external MySQL clients are far from present on every machine.
 */
interface CommandRunner
{
    /**
     * It returns the result of the run for a non-zero exit code too: a failure is expressed through
     * {@see ProcessResult::isSuccessful()} rather than by an exception.
     *
     * @param array<int, string> $command
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 600): ProcessResult;
}

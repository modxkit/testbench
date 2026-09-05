<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Support;

use ModxKit\Testbench\Support\ProcessRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ProcessRunnerTest extends TestCase
{
    public function testCapturesStdoutAndExitCode(): void
    {
        $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'echo "hello";']);

        self::assertTrue($result->isSuccessful());
        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('hello', $result->stdout);
    }

    public function testReportsFailingCommand(): void
    {
        $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'fwrite(STDERR, "bad"); exit(3);']);

        self::assertFalse($result->isSuccessful());
        self::assertSame(3, $result->exitCode);
        self::assertStringContainsString('bad', $result->stderr);
        self::assertStringContainsString('bad', $result->output());
    }

    public function testConvertsTimeoutIntoAFailingResultInsteadOfThrowing(): void
    {
        $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'sleep(2);'], null, 1);

        self::assertFalse($result->isSuccessful());
        self::assertNotSame(0, $result->exitCode);
        self::assertStringContainsString('exceeded the timeout', $result->output());
    }

    /**
     * A process killed by a signal (the real trigger being the OOM killer on a CLI install of MODX
     * or on `mysqldump` in CI) used to land in the general `catch (ExceptionInterface)`, and that
     * one builds the result out of a SINGLE exception message: the stdout/stderr already captured
     * were thrown away, and the exit code became `1` instead of 128+signal. All that was left of
     * an install dump was "signal 9".
     */
    public function testKilledProcessKeepsItsOutputAndReportsTheSignalInTheExitCode(): void
    {
        $result = (new ProcessRunner())->run([
            PHP_BINARY,
            '-r',
            'echo "partial output"; fwrite(STDERR, "partial error"); posix_kill(getmypid(), 9);',
        ]);

        self::assertFalse($result->isSuccessful());
        self::assertSame(137, $result->exitCode, '128 + SIGKILL(9)');
        self::assertStringContainsString('partial output', $result->stdout);
        self::assertStringContainsString('partial error', $result->stderr);
    }

    public function testConvertsFailureToStartIntoAFailingResultInsteadOfThrowing(): void
    {
        $missingCwd = sys_get_temp_dir() . '/modx-testbench-missing-cwd-' . bin2hex(random_bytes(4));

        $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'echo "hi";'], $missingCwd);

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString($missingCwd, $result->output());
    }
}

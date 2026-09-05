<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Support;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class ProcessRunner implements CommandRunner
{
    /**
     * @param array<int, string> $command
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 600): ProcessResult
    {
        $process = new Process($command, $cwd, null, null, (float) $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            // The timeout fires inside Process::wait(); the process had already been started, so
            // the partial output can safely be read.
            return new ProcessResult(
                124,
                $process->getOutput(),
                trim($process->getErrorOutput() . "\n" . $exception->getMessage())
            );
        } catch (ProcessSignaledException $exception) {
            // A separate branch BEFORE the general one: the process managed to run and print
            // something (the real trigger is the OOM killer on the MODX CLI install or on
            // `mysqldump` in CI), while the general `catch` below builds the result out of the
            // exception message alone and loses both the output and the real exit code — all that
            // was left of the install dump was "signal 9".
            return new ProcessResult(
                // The shell convention: a process killed by signal N returns 128+N
                // (SIGKILL → 137).
                128 + $exception->getSignal(),
                $process->getOutput(),
                trim($process->getErrorOutput() . "\n" . $exception->getMessage())
            );
        } catch (ExceptionInterface $exception) {
            // The process may not have managed to start (for example a non-existent cwd) — in that
            // case getOutput()/getErrorOutput() would throw a LogicException, so we use the
            // exception message alone.
            return new ProcessResult(1, '', $exception->getMessage());
        }

        return new ProcessResult(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput()
        );
    }
}

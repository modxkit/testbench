<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Package;

use LogicException;
use ModxKit\Testbench\Support\CommandRunner;
use ModxKit\Testbench\Support\ProcessResult;

/**
 * A fake command runner for {@see \ModxKit\Testbench\Package\TransportInstaller}: it returns a
 * result prepared in advance and does not launch a real PHP subprocess.
 *
 * `$commands` records every call in full (the command, `cwd`, the timeout) — that is used to pin the
 * real shape of the build subprocess call
 * ({@see \ModxKit\Testbench\Tests\Integration\Package\TransportInstallerTest::testBuildInvokesSubprocessWithPhpBinaryScriptPathAndItsDirectoryAsCwd()}),
 * not merely to substitute the result.
 *
 * ANY call counts as unplanned here if no result has been set: that is how the tests prove that the
 * production code never got as far as launching the subprocess.
 *
 * The same-named double in `tests/Unit/Database/` was renamed to
 * {@see \ModxKit\Testbench\Tests\Unit\Database\DefaultsFileRecordingCommandRunner} rather than
 * merged with this class: there it is a queue of results and a snapshot of the MySQL options file,
 * here a single result and the full shape of the call.
 */
final class CallRecordingCommandRunner implements CommandRunner
{
    /** @var list<array{command: array<int, string>, cwd: string|null, timeout: int}> */
    public array $commands = [];

    public function __construct(private readonly ?ProcessResult $result = null)
    {
    }

    public function run(array $command, ?string $cwd = null, int $timeout = 600): ProcessResult
    {
        $this->commands[] = ['command' => $command, 'cwd' => $cwd, 'timeout' => $timeout];

        if (!$this->result instanceof ProcessResult) {
            // A silent success on a call that was not prepared would hide the fact that the production code
            // did reach the subprocess launch, though the test expected it to be cut off earlier.
            throw new LogicException('Unplanned command launch: ' . implode(' ', $command));
        }

        return $this->result;
    }
}

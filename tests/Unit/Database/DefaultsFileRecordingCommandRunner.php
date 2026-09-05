<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Database;

use LogicException;
use ModxKit\Testbench\Support\CommandRunner;
use ModxKit\Testbench\Support\ProcessResult;

/**
 * A fake command runner for {@see \ModxKit\Testbench\Database\MysqlDumper}: it returns a QUEUE of
 * results prepared in advance and takes a snapshot of the MySQL options file at the moment of the
 * call — by the time the dumper returns the file is already deleted, and both its contents and its
 * permissions need checking.
 *
 * A call is considered unplanned when it goes BEYOND the queue: the dumper launches the client
 * several times, and the property under test is that there are no extra launches.
 *
 * The same-named double in `tests/Integration/Package/` was renamed to
 * {@see \ModxKit\Testbench\Tests\Integration\Package\CallRecordingCommandRunner} rather than
 * merged with this class: the shapes in which a call is recorded are incompatible (here only the
 * command, there the command together with `cwd` and the timeout), and "an unplanned call" means
 * different things for the two.
 */
final class DefaultsFileRecordingCommandRunner implements CommandRunner
{
    /** @var list<array<int, string>> */
    public array $commands = [];

    /** @var list<array{path: string, contents: string, mode: string}> */
    public array $defaultsFiles = [];

    /** @var list<ProcessResult> */
    private array $results;

    public function __construct(ProcessResult ...$results)
    {
        $this->results = array_values($results);
    }

    public function run(array $command, ?string $cwd = null, int $timeout = 600): ProcessResult
    {
        $this->commands[] = $command;
        $this->captureDefaultsFile($command);

        $result = array_shift($this->results);

        if ($result === null) {
            // A silent success on a call that was not prepared would hide an extra command:
            // the test would stay green whatever the production code launched beyond what was expected.
            throw new LogicException('Unplanned command launch: ' . implode(' ', $command));
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function lastCommand(): array
    {
        $last = $this->commands[count($this->commands) - 1] ?? [];

        return array_values($last);
    }

    /**
     * There is one options file for the whole operation, so all the snapshots must match.
     *
     * @return array{path: string, contents: string, mode: string}
     */
    public function defaultsFile(): array
    {
        if ($this->defaultsFiles === []) {
            throw new LogicException('Not a single command received --defaults-extra-file.');
        }

        foreach ($this->defaultsFiles as $captured) {
            if ($captured !== $this->defaultsFiles[0]) {
                throw new LogicException('The commands received different options files.');
            }
        }

        return $this->defaultsFiles[0];
    }

    /**
     * @param array<int, string> $command
     */
    private function captureDefaultsFile(array $command): void
    {
        foreach ($command as $argument) {
            if (preg_match('/--defaults-extra-file=\'?([^\'\s]+)/', $argument, $matches) !== 1) {
                continue;
            }

            $path = $matches[1];
            $contents = file_get_contents($path);
            $mode = fileperms($path);

            $this->defaultsFiles[] = [
                'path' => $path,
                'contents' => $contents === false ? '' : $contents,
                'mode' => $mode === false ? '' : substr(sprintf('%o', $mode), -4),
            ];
        }
    }
}

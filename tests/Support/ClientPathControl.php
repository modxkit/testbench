<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Control over which MySQL clients the code under test sees.
 *
 * The presence of `mysqldump`/`mysql` is an input the snapshot strategy choice depends on, and a
 * fake command runner cannot check it: substituting `CommandRunner` proves only that the code
 * asked — not WHAT it will ask the real system. So `PATH` is substituted here, and the commands
 * are executed by the ordinary `ProcessRunner`.
 *
 * The assertions are called statically through {@see Assert}: the trait is mixed into test
 * classes, but is itself tied to `TestCase` in no way, and `self::assertTrue()` here would only
 * give the language server another reason to complain about a method that does not exist.
 */
trait ClientPathControl
{
    /** @var list<string> */
    private array $binDirectories = [];

    /**
     * A directory holding executables with the given contents. An empty array yields a directory
     * without a single client — that is, a system where `mysqldump` does not run at all.
     *
     * @param array<string, string> $executables file name => script body
     */
    private function binDirectoryWith(array $executables): string
    {
        $directory = sys_get_temp_dir() . '/tb-bin-' . bin2hex(random_bytes(4));

        Assert::assertTrue(mkdir($directory, 0o775, true));
        $this->binDirectories[] = $directory;
        $this->assertScriptsRunFrom($directory);

        foreach ($executables as $name => $script) {
            $path = $directory . '/' . $name;

            Assert::assertNotFalse(file_put_contents($path, $script));
            Assert::assertTrue(chmod($path, 0o755));
        }

        return $directory;
    }

    /**
     * Checks that a script from this directory runs at all.
     *
     * The fake clients live in a TEMPORARY directory, and on a `noexec` mount (`/tmp` is mounted
     * that way both in containers and on tightened machines) the kernel refuses to execute them.
     * A test failing because of that used to say "Failed asserting that false is true" (measured:
     * `--tmpfs /tmp:noexec` in `php:8.4-cli`) — not a word about the temporary directory not
     * being executable. The test is still entitled to fail here: working on `noexec` is not
     * promised, a named cause is.
     */
    private function assertScriptsRunFrom(string $directory): void
    {
        $probe = $directory . '/tb-exec-probe';

        Assert::assertNotFalse(file_put_contents($probe, "#!/bin/sh\nexit 7\n"));
        Assert::assertTrue(chmod($probe, 0o755));

        $output = [];
        $code = 0;
        exec(escapeshellarg($probe) . ' 2>&1', $output, $code);
        unlink($probe);

        Assert::assertSame(7, $code, sprintf(
            "A script from «%s» does not run (code %d): %s\n"
            . 'The fake MySQL clients live in a temporary directory, so '
            . 'sys_get_temp_dir() must be mounted WITHOUT noexec. Point TMPDIR at a '
            . 'directory that is allowed to execute.',
            $directory,
            $code,
            implode(' ', $output)
        ));
    }

    /**
     * Runs the callback with a single directory in `PATH`.
     *
     * Symfony Process assembles the subprocess environment from THREE sources — `getenv()`,
     * `$_SERVER` and `$_ENV` — so all three have to be substituted and restored. A forgotten
     * `$_ENV` is neither harmless nor hypothetical: `$_ENV` in the test process is filled in by
     * loading the MODX core, and the subprocess would then receive the ORIGINAL `PATH`, silently
     * turning a "there are no clients" check into "there are clients".
     *
     * @template TResult
     *
     * @param callable(): TResult $run
     *
     * @return TResult
     */
    private function withPath(string $path, callable $run): mixed
    {
        $previousEnv = getenv('PATH');
        $previousServer = $_SERVER['PATH'] ?? null;
        $previousSuperglobal = $_ENV['PATH'] ?? null;

        putenv('PATH=' . $path);
        $_SERVER['PATH'] = $path;
        $_ENV['PATH'] = $path;

        try {
            return $run();
        } finally {
            if ($previousEnv === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousEnv);
            }

            if ($previousServer === null) {
                unset($_SERVER['PATH']);
            } else {
                $_SERVER['PATH'] = $previousServer;
            }

            if ($previousSuperglobal === null) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $previousSuperglobal;
            }
        }
    }

    private function removeBinDirectories(): void
    {
        foreach ($this->binDirectories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }

        $this->binDirectories = [];
    }
}

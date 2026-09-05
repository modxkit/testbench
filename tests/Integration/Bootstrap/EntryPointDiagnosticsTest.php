<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Bootstrap;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Both entry points of the package look for Composer's autoloader by trying a list of paths, and
 * neither had a failure branch: without `vendor/` the consumer got
 * `Class "Symfony\…\Application" not found` (rc=255) or
 * `Class "ModxKit\Testbench\Environment\TestbenchKernel" not found` — a diagnosis that sends them
 * looking for symfony/console instead of the dependencies that were never installed.
 *
 * Both entry points are copied into a throwaway directory: there is no `vendor/autoload.php` beside
 * them there, and the fallback path `../../autoload.php` leads nowhere either — exactly the state
 * "the dependencies are not installed".
 */
#[Group('integration')]
final class EntryPointDiagnosticsTest extends TestCase
{
    private string $sandbox = '';

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            foreach (glob($this->sandbox . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->sandbox);
        }

        parent::tearDown();
    }

    public function testExecutableNamesComposerInstallInsteadOfAMissingSymfonyClass(): void
    {
        $copy = $this->sandboxCopy('bin/modx-testbench', 'modx-testbench');

        $process = new Process([PHP_BINARY, $copy, 'list']);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertSame(1, $process->getExitCode(), $output);
        self::assertStringContainsString('composer install', $output);
        self::assertStringNotContainsString('Class "Symfony', $output);
    }

    public function testBootstrapNamesComposerInstallInsteadOfAMissingKernelClass(): void
    {
        $copy = $this->sandboxCopy('bootstrap.php', 'bootstrap.php');

        $process = new Process([PHP_BINARY, '-r', 'require ' . var_export($copy, true) . ';']);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertNotSame(0, $process->getExitCode(), $output);
        self::assertStringContainsString('composer install', $output);
        self::assertStringNotContainsString('Class "ModxKit\\Testbench', $output);
    }

    private function sandboxCopy(string $relativePath, string $name): string
    {
        $this->sandbox = sys_get_temp_dir() . '/testbench-entrypoint-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->sandbox, 0o700));

        $source = dirname(__DIR__, 3) . '/' . $relativePath;
        $copy = $this->sandbox . '/' . $name;

        self::assertTrue(copy($source, $copy), "Could not copy {$source}");

        return $copy;
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\PHPUnit;

use ModxKit\Testbench\Environment\TestbenchKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The channel `bootstrap.php` does not have.
 *
 * `bootstrap.php` explains at length why it says nothing about environment files that hold the
 * database password and are readable by more than their owner: a warning raised there is raised
 * again inside every `#[RunInSeparateProcess]` child, and PHPUnit turns a non-empty child STDERR
 * into a test error. That analysis used to end with "no signal whose failure would be LOUD was
 * found", and this test is the measurement that sentence did not survive: a PHPUnit extension is
 * such a signal.
 *
 * The probe suite below holds two tests, one of them isolated, and that is the whole design: if the
 * extension ran in the child too, the isolated test would come back as an error, and the run would
 * not be green.
 *
 * The child is a REAL `vendor/bin/phpunit` process rather than `#[RunInSeparateProcess]`, for the
 * reason given in {@see \ModxKit\Testbench\Tests\Integration\Bootstrap\BootstrapGuardTest}: isolation
 * forks before the body of the test, so nothing set from inside can reach the bootstrap of that
 * same fork.
 */
#[Group('integration')]
final class ExposureWarningExtensionTest extends TestCase
{
    private ?string $probeDir = null;

    /**
     * The file of the SHARED environment whose permissions this test loosens. It is restored on a
     * failed test as well — otherwise the next run would inherit an environment whose password is
     * readable by everyone.
     */
    private ?string $restoreConfigPermissions = null;

    protected function tearDown(): void
    {
        if ($this->restoreConfigPermissions !== null) {
            chmod($this->restoreConfigPermissions, 0o600);
            $this->restoreConfigPermissions = null;
        }

        if ($this->probeDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->probeDir));
            $this->probeDir = null;
        }
    }

    public function testTheWarningIsPrintedOncePerRunAndIsolatedTestsStayGreen(): void
    {
        $configFile = TestbenchKernel::instance()->prepare()->configFile();

        self::assertFileExists($configFile);
        self::assertTrue(chmod($configFile, 0o644));
        $this->restoreConfigPermissions = $configFile;

        // Premise: the environment really is in the state the extension is supposed to talk about.
        // Without it a silent extension and a private environment look the same.
        self::assertNotSame([], TestbenchKernel::instance()->workspace()->exposedSecretFiles());

        $process = $this->runProbeSuite();
        $stderr = $process->getErrorOutput();

        self::assertSame(
            0,
            $process->getExitCode(),
            "The probe suite was not green:\n" . $process->getOutput() . "\n" . $stderr
        );
        self::assertStringContainsString('OK (2 tests', $process->getOutput());

        self::assertStringContainsString('modx-testbench:', $stderr, 'The extension said nothing.');
        self::assertSame(
            1,
            substr_count($stderr, 'modx-testbench:'),
            "The warning must be printed once per run, not once per process:\n" . $stderr
        );
        self::assertStringContainsString(basename($configFile), $stderr, 'The warning does not name the file.');
    }

    /**
     * The mirror half: with the environment private, the extension is silent. Without this the test
     * above would also pass for an extension that prints unconditionally.
     */
    public function testNothingIsPrintedWhenTheEnvironmentIsPrivate(): void
    {
        $workspace = TestbenchKernel::instance()->prepare();

        self::assertSame([], $workspace->exposedSecretFiles(), 'The environment is not private to begin with.');

        $process = $this->runProbeSuite();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('modx-testbench:', $process->getErrorOutput());
    }

    private function runProbeSuite(): Process
    {
        $root = dirname(__DIR__, 3);
        $this->probeDir = sys_get_temp_dir() . '/modx-testbench-extension-' . bin2hex(random_bytes(4));

        if (!mkdir($this->probeDir . '/tests', 0o700, true)) {
            self::fail('Unable to create the probe directory ' . $this->probeDir);
        }

        file_put_contents($this->probeDir . '/tests/ExtensionProbeTest.php', <<<'PROBE'
            <?php

            use PHPUnit\Framework\Attributes\RunInSeparateProcess;
            use PHPUnit\Framework\TestCase;

            final class ExtensionProbeTest extends TestCase
            {
                public function testInTheRunnerProcess(): void
                {
                    self::assertTrue(true);
                }

                #[RunInSeparateProcess]
                public function testInAnIsolatedProcess(): void
                {
                    self::assertTrue(true);
                }
            }
            PROBE);

        file_put_contents($this->probeDir . '/phpunit.xml', sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<phpunit bootstrap="%s/bootstrap.php" cacheDirectory="%s/.cache" failOnWarning="true">'
            . '<testsuites><testsuite name="probe"><directory>%s/tests</directory></testsuite></testsuites>'
            . '<extensions><bootstrap class="ModxKit\Testbench\PHPUnit\ExposureWarningExtension"/></extensions>'
            . '</phpunit>',
            $root,
            $this->probeDir,
            $this->probeDir
        ));

        $process = new Process(
            [$root . '/vendor/bin/phpunit', '--configuration', $this->probeDir . '/phpunit.xml', '--colors=never'],
            $root,
            null,
            null,
            300
        );
        $process->run();

        return $process;
    }
}

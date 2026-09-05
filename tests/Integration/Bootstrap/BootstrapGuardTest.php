<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Bootstrap;

use ModxKit\Testbench\Environment\TestbenchKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The bootstrap guard, with a second scenario added later.
 *
 * `#[RunInSeparateProcess]` is no good here (verified by hand and confirmed independently by a
 * reviewer): the isolation forks a child process BEFORE the body of the test, so nothing set FROM
 * INSIDE such a test can affect the bootstrap of that same fork — the child process's
 * `bootstrap.php` would already have run by the time the body of the test starts configuring
 * anything. The only way to check `bootstrap.php` itself as a process is to launch a REAL separate
 * PHP process through `Symfony\Component\Process\Process` (already a dependency of the package,
 * `src/Support/ProcessRunner.php`) rather than through the PHPUnit attribute.
 *
 * The database port is closed LOCALLY (127.0.0.1:1 — not a CI container, not a `docker stop`): the
 * connection is refused instantly, without the network and without depending on whether
 * `modx-testbench-mysql` is up right now. The working directory is certainly a new one (a random
 * name, never `install`ed under this fingerprint), so `TestbenchKernel::prepare()` inside the child
 * process's `bootstrap.php` unconditionally goes into the "install from scratch" branch and fails on
 * the very first attempt to connect to the database — exactly the scenario the guard is about (CI
 * prepared the environment with one set of `MODX_TESTBENCH_DB_*`, and the unit job runs with a
 * different or a stopped one).
 *
 * Without the `try`/`catch` in `bootstrap.php` that error kills the child process BEFORE a single
 * test — reproduced by hand: a `Fatal error` or an unhandled exception, PHPUnit returns a non-zero
 * exit code and prints nothing resembling `Tests: N`. With the guard the process survives the
 * failure of `prepare()`, and that is what both tests below check.
 */
#[Group('integration')]
final class BootstrapGuardTest extends TestCase
{
    private ?string $workspacePath = null;

    private ?string $fakeDistributionPath = null;

    /**
     * A file of the shared environment whose permissions the test loosened: they must be restored on
     * a failed test too — otherwise the next run would find an environment with a password readable
     * by everyone.
     */
    private ?string $restoreConfigPermissions = null;

    protected function tearDown(): void
    {
        if ($this->restoreConfigPermissions !== null) {
            chmod($this->restoreConfigPermissions, 0o600);
            $this->restoreConfigPermissions = null;
        }

        foreach ([$this->workspacePath, $this->fakeDistributionPath] as $path) {
            if ($path !== null && is_dir($path)) {
                exec('rm -rf ' . escapeshellarg($path));
            }
        }

        $this->workspacePath = null;
        $this->fakeDistributionPath = null;
    }

    /**
     * Here `prepare()` fails AFTER the provider has already laid the distribution out into the
     * working directory: the `zip` provider needs no database at all, and only the step that
     * installs into the DBMS stumbles. That is exactly the shape of the CI job "level 1 with the
     * database off", which docs/SPEC.md:31 declares an invariant of the package — and the `catch` in
     * `bootstrap.php` must register the core autoloader all the same, otherwise the whole
     * `UnitTestCase` of that job fails with its own "The MODX core was not found" exception
     * (reproduced).
     *
     * What is checked is the EFFECT rather than the fact of the call: `registered=true` is reachable
     * only through `CoreAutoloader::register()`, and the only thing that could have called it in this
     * process is the `catch` branch (the main branch of `bootstrap.php` never reaches `register()`,
     * `prepare()` threw). It is additionally verified that what was registered is the `core/` of this
     * working directory — otherwise the test would pass against a foreign core inherited by chance.
     */
    public function testFailedInstallStillRegistersTheCoreThatIsAlreadyOnDisk(): void
    {
        $this->workspacePath = sys_get_temp_dir() . '/modx-testbench-guard-' . bin2hex(random_bytes(6));

        $process = $this->runBootstrap([
            'MODX_TESTBENCH_WORKSPACE' => $this->workspacePath,
            'MODX_TESTBENCH_DB_HOST' => '127.0.0.1',
            // Refused instantly by the OS kernel (there is not and cannot be a listener on 1) — it requires
            // neither a running modx-testbench-mysql, nor docker, nor a network timeout.
            'MODX_TESTBENCH_DB_PORT' => '1',
            // Cleared explicitly: otherwise it would be inherited from the process that launched this test,
            // and the result would depend on how exactly the outer `vendor/bin/phpunit` was invoked. The
            // mere presence or absence of this variable does not affect the scenario (the default provider
            // is `zip` anyway), but for the test to be deterministic it must not be part of its
            // environment.
            'MODX_TESTBENCH_CORE_PATH' => false,
        ]);

        self::assertSame(
            0,
            $process->getExitCode(),
            "The child process failed instead of exiting normally:\n" . $process->getErrorOutput()
        );
        self::assertFileExists(
            $this->workspacePath . '/core/vendor/autoload.php',
            'Precondition of the scenario: the provider must have laid the distribution out BEFORE failing on the database install step.'
        );
        self::assertStringContainsString('registered=true', $process->getOutput());
        self::assertStringContainsString(
            'path=' . $this->workspacePath . '/core',
            $process->getOutput(),
            'Some core was registered, but not the one lying in the working directory of this run.'
        );
    }

    /**
     * The other side: if the core files are NOT on disk, the `catch` must stay silent. The `local`
     * provider with a non-existent directory fails in `LocalPathProvider::provide()` — before a
     * single file has been copied — so the working directory stays empty. A `register()` at that
     * point would throw a second exception, this time caught by nobody, straight out of the `catch`,
     * killing the whole bootstrap again; it is for this that the soft `registerIfAvailable()` exists
     * in `CoreAutoloader`.
     */
    public function testFailedPrepareWithoutCoreFilesLeavesTheAutoloaderUnregistered(): void
    {
        $this->workspacePath = sys_get_temp_dir() . '/modx-testbench-guard-' . bin2hex(random_bytes(6));

        $process = $this->runBootstrap([
            'MODX_TESTBENCH_WORKSPACE' => $this->workspacePath,
            'MODX_TESTBENCH_PROVIDER' => 'local',
            'MODX_TESTBENCH_CORE_PATH' => sys_get_temp_dir() . '/modx-testbench-absent-' . bin2hex(random_bytes(6)),
            // The port is still closed: the scenario must fail in the provider rather than depend on
            // whether a live DBMS happened to be nearby.
            'MODX_TESTBENCH_DB_HOST' => '127.0.0.1',
            'MODX_TESTBENCH_DB_PORT' => '1',
        ]);

        self::assertSame(
            0,
            $process->getExitCode(),
            "The child process failed instead of exiting normally:\n" . $process->getErrorOutput()
        );
        self::assertFileDoesNotExist($this->workspacePath . '/core/vendor/autoload.php');
        self::assertStringContainsString('registered=false', $process->getOutput());
    }

    /**
     * The third state, which no test had: the autoloader file is IN PLACE but corrupted.
     *
     * `CoreAutoloader::registerIfAvailable()` muffles only the ABSENCE of the core; a corrupted file
     * must fail loudly, naming the specific file. This is a load-bearing property: an error
     * swallowed silently here would make diagnosis impossible —
     * `KernelBootstrapper::assertEnvironmentIsComplete()` catches a MISSING file with an explicit
     * exception, but not a present-and-broken one.
     *
     * The scenario is built by the `local` provider against a fake "distribution": `index.php` and
     * `setup/` are there (otherwise `LocalPathProvider::provide()` would refuse ahead of time), while
     * `core/vendor/autoload.php` certainly does not parse as PHP. The provider copies the tree into
     * the working directory and only THEN does `prepare()` stumble over the dead database (port 1) —
     * that is, by the time of the `catch` the broken file is already on disk, `is_file()` lets it
     * through, and control reaches `require_once`.
     */
    public function testCorruptedCoreAutoloaderFailsLoudlyInsteadOfBeingSwallowed(): void
    {
        $this->workspacePath = sys_get_temp_dir() . '/modx-testbench-guard-' . bin2hex(random_bytes(6));
        $this->fakeDistributionPath = sys_get_temp_dir() . '/modx-testbench-broken-' . bin2hex(random_bytes(6));

        mkdir($this->fakeDistributionPath . '/setup', 0o775, true);
        mkdir($this->fakeDistributionPath . '/core/vendor', 0o775, true);
        file_put_contents($this->fakeDistributionPath . '/index.php', '<?php // a distribution stub');
        // Deliberately unparseable PHP: `require_once` must break the process off with a parse error.
        file_put_contents(
            $this->fakeDistributionPath . '/core/vendor/autoload.php',
            '<?php this is not valid php ((('
        );

        $process = $this->runBootstrap([
            'MODX_TESTBENCH_WORKSPACE' => $this->workspacePath,
            'MODX_TESTBENCH_PROVIDER' => 'local',
            'MODX_TESTBENCH_CORE_PATH' => $this->fakeDistributionPath,
            'MODX_TESTBENCH_DB_HOST' => '127.0.0.1',
            'MODX_TESTBENCH_DB_PORT' => '1',
        ]);

        self::assertFileExists(
            $this->workspacePath . '/core/vendor/autoload.php',
            'Precondition of the scenario: the broken autoloader must end up in the working directory before prepare() fails.'
        );
        self::assertNotSame(
            0,
            $process->getExitCode(),
            'A corrupted core autoloader was swallowed silently — the process exited normally.'
        );

        $output = $process->getErrorOutput() . $process->getOutput();

        self::assertStringContainsString(
            $this->workspacePath . '/core/vendor/autoload.php',
            $output,
            'The diagnostics do not name the specific file that brought everything down.'
        );
        self::assertStringContainsString('rror', $output, 'There is neither a Parse error nor a Fatal error in the output.');
    }

    /**
     * A settled decision: the bootstrap SAYS NOTHING about unprotected files, and that is pinned
     * rather than left to chance.
     *
     * Printing indiscriminately breaks the tests that run in a separate process: PHPUnit treats a
     * non-empty child STDERR as an error. Measured on this very suite — both `fwrite(STDERR, …)` and
     * `trigger_error(…, E_USER_WARNING)` in `bootstrap.php` turn eight unit tests with
     * `#[RunInSeparateProcess]` into errors as soon as the permissions of the shared environment
     * become 0644.
     *
     * A working channel does EXIST and has also been measured: with the condition
     * `ob_get_level() === 0` the warning gets through and the run stays green. It was not taken
     * because it fails silently — a consumer's bootstrap that begins with `ob_start()` extinguishes
     * the warning without a trace (measured). The full analysis is in `bootstrap.php`.
     *
     * The test holds the boundary from both sides: that the process survives loosened permissions
     * normally, and that there is no output in either stream while it does.
     *
     * A later change narrowed the question without cancelling it. The default environment directory
     * is now created with 0700 permissions ({@see \ModxKit\Testbench\Environment\Workspace}), and
     * without the right to enter the directory the mode of a file inside it plays no role — measured
     * on debian:stable: a foreign user gets `Permission denied` both on a `cat` of a 0644 file and on
     * a listing of a 0700 directory. The bootstrap's silence therefore costs less now than it did
     * when the question was first raised. The condition `exposedSecretFiles() !== []` remains
     * meaningful further on — but in exactly one case: `MODX_TESTBENCH_WORKSPACE` may point at a
     * directory with any permissions. The fallback into a world-writable temporary directory does not
     * belong here: it has been measured that the whole path segment is created with 0700 in the
     * fallback too.
     */
    public function testBootstrapPrintsNothingEvenWhenSecretFilesAreExposed(): void
    {
        $configFile = TestbenchKernel::instance()->prepare()->configFile();

        self::assertFileExists($configFile);
        self::assertTrue(chmod($configFile, 0o644));
        $this->restoreConfigPermissions = $configFile;

        // Premise: the environment really is in the state the check would be about.
        self::assertNotSame([], TestbenchKernel::instance()->workspace()->exposedSecretFiles());

        $process = $this->runBootstrap([]);

        self::assertSame(
            0,
            $process->getExitCode(),
            "The child process failed instead of exiting normally:\n" . $process->getErrorOutput()
        );
        self::assertSame('', $process->getErrorOutput(), 'The bootstrap printed to STDERR.');
        self::assertSame(
            'registered=true path=' . rtrim(TestbenchKernel::instance()->workspace()->corePath(), '/'),
            $process->getOutput(),
            'The bootstrap printed to STDOUT beyond what the wrapper script itself prints.'
        );
    }

    /**
     * @param array<string, string|false> $env
     */
    private function runBootstrap(array $env): Process
    {
        $script = 'require ' . var_export(dirname(__DIR__, 3) . '/bootstrap.php', true) . ';'
            . 'echo "registered=" . var_export(\ModxKit\Testbench\Support\CoreAutoloader::isRegistered(), true);'
            . 'echo " path=" . (\ModxKit\Testbench\Support\CoreAutoloader::registeredPath() ?? "NULL");';

        $process = new Process([\PHP_BINARY, '-r', $script], null, $env, null, 300);
        $process->run();

        return $process;
    }
}

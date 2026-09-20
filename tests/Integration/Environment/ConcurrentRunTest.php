<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Environment;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\RunLock;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Exception\ConcurrentRunException;
use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * The guard between two REAL processes (FR-ENV-9).
 *
 * The unit tests of {@see RunLock} check the same mechanism within one process — `flock()` belongs
 * to the open file description, so a second `fopen()` is refused even by oneself — but two claims
 * cannot be made there at all: that a stranger is refused while a CHILD of the holder is let
 * through (the difference is an inherited environment, which one process cannot have), and that the
 * lock dies with its holder (one cannot `kill -9` oneself and then check anything).
 *
 * This suite's own bootstrap holds the lock on the environment for the whole run, and that is the
 * fixture: the environment here is INSTALLED and HELD, which is precisely the case the guard exists
 * for — two runs that both find everything ready and then drop each other's tables.
 */
#[Group('integration')]
final class ConcurrentRunTest extends TestCase
{
    /**
     * A stranger is refused although the environment is installed and nothing needs doing to it.
     *
     * The variable is not merely left unset but REMOVED from the child's environment (`false` in
     * Symfony's env array): the child would otherwise inherit the token of this very run and be
     * family rather than a stranger.
     */
    public function testAStrangerIsRefusedOnAnEnvironmentThisRunAlreadyHolds(): void
    {
        $probe = $this->probe(['MODX_TESTBENCH_RUN_TOKEN' => false]);

        self::assertSame('refused', $probe, 'A foreign run was let into an environment already held.');
    }

    /**
     * The package spawns children that boot the same environment itself — the build script of a
     * transport package is one ({@see \ModxKit\Testbench\Package\TransportInstaller}). Refusing them
     * would break a documented feature rather than prevent a collision.
     */
    public function testAChildOfThisRunIsLetThrough(): void
    {
        self::assertSame('prepared', $this->probe([]));
    }

    /**
     * `kill -9` runs no destructor, no shutdown function and no `finally`. The environment is free
     * all the same, because the hold is the descriptor and closing it is the kernel's business, not
     * the program's.
     *
     * A lock of its own (a database name nobody else uses) rather than this run's: the holder of
     * this run's lock is the PHPUnit process, and killing it would end the test rather than pass it.
     * No database is created — the guard is taken before anything touches the DBMS, and the name
     * only has to differ.
     */
    public function testTheLockDiesWithItsHolder(): void
    {
        $database = 'modx_tb_concurrent_' . bin2hex(random_bytes(4));
        $config = $this->configForDatabase($database);

        $holder = new Process([PHP_BINARY, __DIR__ . '/run-lock-holder.php'], null, [
            'MODX_TESTBENCH_DB_NAME' => $database,
            // Without this the holder would inherit OUR token, and everything below would be
            // family talking to itself.
            'MODX_TESTBENCH_RUN_TOKEN' => false,
        ]);
        $holder->start();

        try {
            $this->waitForHold($holder);

            try {
                RunLock::acquire($config);
                self::fail('The lock of a live holder was handed out to somebody else.');
            } catch (ConcurrentRunException) {
                // The refusal is the expected half; the half being checked is what follows.
            }

            $holder->signal(SIGKILL);
            $holder->wait();

            $lock = RunLock::acquire($config);

            self::assertNotNull($lock, 'The lock stayed held by a process that no longer exists.');

            $lock->release();
        } finally {
            if ($holder->isRunning()) {
                $holder->stop(0);
            }

            @unlink(RunLock::pathFor($config));
        }
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function probe(array $environment): string
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/run-lock-probe.php'], null, $environment, null, 120);
        $process->run();

        self::assertTrue(
            $process->isSuccessful(),
            $process->getOutput() . $process->getErrorOutput()
        );

        return trim($process->getOutput());
    }

    private function waitForHold(Process $holder): void
    {
        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            if (str_contains($holder->getIncrementalOutput(), 'held')) {
                return;
            }

            if (!$holder->isRunning()) {
                self::fail('The holder died before taking the lock: ' . $holder->getErrorOutput());
            }

            usleep(20_000);
        }

        self::fail('The holder did not report taking the lock within 30 seconds.');
    }

    private function configForDatabase(string $name): TestbenchConfig
    {
        $current = TestbenchConfig::fromEnvironment();
        $database = $current->database;

        return new TestbenchConfig(
            provider: $current->provider,
            version: $current->version,
            gitRef: $current->gitRef,
            localCorePath: $current->localCorePath,
            database: new DatabaseConfig(
                host: $database->host,
                port: $database->port,
                name: $name,
                user: $database->user,
                password: $database->password,
                prefix: $database->prefix,
                charset: $database->charset,
                collation: $database->collation,
            ),
            admin: $current->admin,
            cacheDir: $current->cacheDir,
            workspaceDir: $current->workspaceDir,
            forceInstall: $current->forceInstall,
        );
    }
}

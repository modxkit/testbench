<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Environment;

use ModxKit\Testbench\Environment\RunLock;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Exception\ConcurrentRunException;
use ModxKit\Testbench\Tests\Support\OwnsTestbenchEnvironment;
use ModxKit\Testbench\Tests\Support\RestoresServerVariables;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Where the guard sits inside {@see TestbenchKernel::prepare()}.
 *
 * The position is the whole point, not a detail of style: a guard placed after the "is it installed
 * already" branch would let through exactly the case the guard exists for — two runs that both find
 * the environment ready and then write over each other. Order cannot be asserted by reading the
 * source, so it is asserted by CONSEQUENCE. The configuration here is broken on purpose
 * (`provider=local` with no `MODX_TESTBENCH_CORE_PATH`), and a `prepare()` that reached the
 * provider would say so in a `TestbenchException` of a different type. Getting
 * {@see ConcurrentRunException} instead is the proof that nothing before it ran.
 *
 * That the ALREADY INSTALLED environment is covered too needs an installed environment and lives in
 * `tests/Integration/Environment/ConcurrentRunTest.php`.
 *
 * Deliberately a unit test: it touches neither network nor DBMS — the refusal happens before the
 * core would be fetched, and that is precisely what is being checked.
 */
#[Group('unit')]
final class KernelRunLockTest extends TestCase
{
    use OwnsTestbenchEnvironment {
        setUp as private clearTestbenchEnvironment;
        tearDown as private restoreTestbenchEnvironment;
    }
    use RestoresServerVariables;

    private string $cacheHome;

    /** @var callable(): void */
    private $restoreHomeVariables;

    private ?RunLock $holder = null;

    protected function setUp(): void
    {
        $this->clearTestbenchEnvironment();

        $this->restoreHomeVariables = $this->serverVariableRestorer(['HOME', 'XDG_CACHE_HOME']);

        $this->cacheHome = sys_get_temp_dir() . '/modx-testbench-kernel-lock-' . bin2hex(random_bytes(4));
        $_SERVER['XDG_CACHE_HOME'] = $this->cacheHome;

        // The configuration that cannot possibly install: if the guard is not the first thing
        // `prepare()` does, the failure will be about the provider and the test will say so.
        $_SERVER['MODX_TESTBENCH_PROVIDER'] = 'local';

        TestbenchKernel::reset();
    }

    protected function tearDown(): void
    {
        $this->holder?->release();
        $this->holder = null;
        $this->forgetInheritedToken();

        TestbenchKernel::reset();

        ($this->restoreHomeVariables)();
        $this->restoreTestbenchEnvironment();

        $this->removeRecursively($this->cacheHome);
    }

    public function testPrepareIsRefusedWhileAnotherRunHoldsTheEnvironment(): void
    {
        $this->holder = RunLock::acquire(TestbenchConfig::fromEnvironment());

        // The holder was taken inside THIS process, so it published its token here and the kernel
        // below would be its child rather than a stranger. Forgetting the token is what turns the
        // fixture into the case the test is named after.
        $this->forgetInheritedToken();

        $this->expectException(ConcurrentRunException::class);

        TestbenchKernel::instance()->prepare();
    }

    /** With the opt-out set the kernel goes on to its ordinary work — and fails at the provider. */
    public function testTheOptOutLetsThePreparationReachItsOrdinaryFailure(): void
    {
        $_SERVER['MODX_TESTBENCH_ALLOW_CONCURRENT'] = '1';

        $this->holder = RunLock::acquire(TestbenchConfig::fromEnvironment());

        $this->expectExceptionMessageMatches('/MODX_TESTBENCH_CORE_PATH/');

        TestbenchKernel::instance()->prepare();
    }

    private function forgetInheritedToken(): void
    {
        putenv('MODX_TESTBENCH_RUN_TOKEN');
        unset($_SERVER['MODX_TESTBENCH_RUN_TOKEN'], $_ENV['MODX_TESTBENCH_RUN_TOKEN']);
    }

    private function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            is_dir($child) ? $this->removeRecursively($child) : @unlink($child);
        }

        @rmdir($path);
    }
}

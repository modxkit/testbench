<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Environment;

use ModxKit\Testbench\Environment\RunLock;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Exception\ConcurrentRunException;
use ModxKit\Testbench\Tests\Support\OwnsTestbenchEnvironment;
use ModxKit\Testbench\Tests\Support\RestoresServerVariables;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The guard against two runs sharing one environment.
 *
 * Two projects that both leave `MODX_TESTBENCH_DB_NAME` at its default get the same database, the
 * same table prefix AND the same environment directory — the fingerprint
 * ({@see TestbenchConfig::fingerprint()}) is built out of the DBMS coordinates and the admin
 * account, and holds nothing that would tell one consumer's project from another's. Run at the same
 * time, they drop each other's tables.
 *
 * What is checked here is the CHEAP half: where the lock file goes, and that a second holder is
 * refused. The half that needs a real second process — that the operating system releases the lock
 * when the holder dies — lives in the integration suite, because `kill -9` cannot be aimed at
 * oneself.
 *
 * Exclusion is checked WITHIN one process on purpose, and it is not a weaker substitute for two
 * processes: `flock()` locks belong to the open file description, not to the process, so a second
 * `fopen()` of the same path is refused by the lock the first one holds even when both live in the
 * same PHP. That is the very mechanism the second process would run into.
 */
#[Group('unit')]
final class RunLockTest extends TestCase
{
    use OwnsTestbenchEnvironment {
        setUp as private clearTestbenchEnvironment;
        tearDown as private restoreTestbenchEnvironment;
    }
    use RestoresServerVariables;

    private string $cacheHome;

    /** @var callable(): void */
    private $restoreHomeVariables;

    /** @var list<RunLock> */
    private array $held = [];

    /**
     * Takes a lock and remembers it, so that {@see self::tearDown()} can let it go. A lock left
     * held would make the NEXT test of this class refuse itself.
     */
    private function hold(TestbenchConfig $config): ?RunLock
    {
        $lock = RunLock::acquire($config);

        if ($lock instanceof RunLock) {
            $this->held[] = $lock;
        }

        return $lock;
    }

    protected function setUp(): void
    {
        $this->clearTestbenchEnvironment();

        $this->restoreHomeVariables = $this->serverVariableRestorer(['HOME', 'XDG_CACHE_HOME']);

        $this->cacheHome = sys_get_temp_dir() . '/modx-testbench-lock-' . bin2hex(random_bytes(4));
        $_SERVER['XDG_CACHE_HOME'] = $this->cacheHome;
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }

        $this->held = [];
        $this->forgetInheritedToken();

        ($this->restoreHomeVariables)();
        $this->restoreTestbenchEnvironment();

        $this->removeRecursively($this->cacheHome);
    }

    /**
     * The lock lives beside `workspaces/`, and the base directory is chosen the way
     * {@see \ModxKit\Testbench\Environment\Workspace::defaultLocation()} chooses it — NOT out of
     * `MODX_TESTBENCH_CACHE_DIR`. In CI that variable points at a directory uploaded by
     * `actions/cache` as a build artifact, and the file name carries the fingerprint, into which
     * FR-ENV-4 hashes the database password.
     */
    public function testLockFileLivesInThePrivateCacheAndNotInTheReleaseCache(): void
    {
        $_SERVER['MODX_TESTBENCH_CACHE_DIR'] = sys_get_temp_dir() . '/modx-testbench-release-cache';

        $config = TestbenchConfig::fromEnvironment();

        self::assertSame(
            $this->cacheHome . '/modx-testbench/locks/' . $config->fingerprint() . '.lock',
            RunLock::pathFor($config)
        );
    }

    /** Two environments that differ get two locks, or the guard would serialise unrelated runs. */
    public function testDifferentDatabasesGetDifferentLocks(): void
    {
        $_SERVER['MODX_TESTBENCH_DB_NAME'] = 'modx_project_a';
        $first = RunLock::pathFor(TestbenchConfig::fromEnvironment());

        $_SERVER['MODX_TESTBENCH_DB_NAME'] = 'modx_project_b';
        $second = RunLock::pathFor(TestbenchConfig::fromEnvironment());

        self::assertNotSame($first, $second);
    }

    /** FR-ENV-8 keeps the fingerprint unreadable by outsiders; a lock named by it is no different. */
    public function testTheDirectoryOfTheLockIsCreatedPrivate(): void
    {
        $this->hold(TestbenchConfig::fromEnvironment());

        self::assertSame(
            '0700',
            substr(sprintf('%o', fileperms($this->cacheHome . '/modx-testbench/locks')), -4)
        );
    }

    public function testAStrangerIsRefused(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $this->hold($config);

        // What makes the next caller a STRANGER and not a child: it did not inherit the holder's
        // token. Without this line the call below would be a child of this very run and would be
        // let through, and the test would be checking the opposite of its own name.
        $this->forgetInheritedToken();

        $this->expectException(ConcurrentRunException::class);

        RunLock::acquire($config);
    }

    /**
     * A subprocess of the run is not a second run. The package spawns such children itself — the
     * build script of a transport package ({@see \ModxKit\Testbench\Package\TransportInstaller})
     * boots the very same environment — and refusing them would break a documented feature rather
     * than prevent a collision.
     *
     * The child is recognised by an inherited token, not by a pid: pids are reissued, and a check
     * by ancestry is not portable. The token reaches a real child through the process environment,
     * which is what {@see RunLock} publishes it into.
     */
    public function testAChildThatInheritedTheTokenIsLetThrough(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $this->hold($config);

        self::assertNull(RunLock::acquire($config));
    }

    public function testTheHolderPublishesItsTokenForChildrenToInherit(): void
    {
        $this->hold(TestbenchConfig::fromEnvironment());

        // Both places, because the two ways of spawning a child read different ones: `proc_open()`
        // and friends take the real process environment (`putenv()`), while Symfony's `Process`
        // passes on only what `getenv()` and `$_SERVER` have in common
        // (`vendor/symfony/process/Process.php:1713`). Publishing into one of them alone leaves
        // half the children outside the family.
        self::assertNotFalse(getenv('MODX_TESTBENCH_RUN_TOKEN'));
        self::assertNotSame('', getenv('MODX_TESTBENCH_RUN_TOKEN'));
        self::assertSame(getenv('MODX_TESTBENCH_RUN_TOKEN'), $_SERVER['MODX_TESTBENCH_RUN_TOKEN'] ?? null);
    }

    /**
     * A second lock taken by the same run MUST NOT disown the children of the first one.
     *
     * The token identifies the RUN, not one lock of it. A run that takes a second environment —
     * which the package's own suite does while checking that a lock dies with its holder — used to
     * write a fresh token and publish it, and from that moment its subprocesses carried a token
     * that matched nothing in the file of the FIRST lock: they were refused entry into the very
     * environment their parent was holding.
     *
     * Found by `--order-by=reverse`, and only by it: in the declared order the test that takes a
     * second lock ran last, so nothing came after it to notice.
     */
    public function testASecondLockOfTheSameRunKeepsTheTokenTheFirstOnePublished(): void
    {
        $this->hold(TestbenchConfig::fromEnvironment());

        $published = getenv('MODX_TESTBENCH_RUN_TOKEN');

        $_SERVER['MODX_TESTBENCH_DB_NAME'] = 'modx_testbench_elsewhere';
        $this->hold(TestbenchConfig::fromEnvironment());

        self::assertSame($published, getenv('MODX_TESTBENCH_RUN_TOKEN'));
    }

    /** A token left over from an unrelated shell export holds nothing: the lock is free, so it is taken. */
    public function testAnInheritedTokenThatNobodyHoldsDoesNotStandInTheWay(): void
    {
        putenv('MODX_TESTBENCH_RUN_TOKEN=deadbeefdeadbeef');

        self::assertNotNull($this->hold(TestbenchConfig::fromEnvironment()));
    }

    /**
     * The refusal has to carry the way out, not merely the fact. Both ways out are named: a
     * database of one's own, and the opt-out for someone who runs several processes over one
     * environment deliberately.
     */
    public function testTheRefusalNamesBothWaysOut(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $this->hold($config);
        $this->forgetInheritedToken();

        try {
            RunLock::acquire($config);
            self::fail('The second holder was not refused.');
        } catch (ConcurrentRunException $refusal) {
            self::assertStringContainsString('MODX_TESTBENCH_DB_NAME', $refusal->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_ALLOW_CONCURRENT', $refusal->getMessage());
            self::assertStringContainsString((string) getmypid(), $refusal->getMessage());
        }
    }

    public function testTheOptOutTakesNoLockAtAll(): void
    {
        $_SERVER['MODX_TESTBENCH_ALLOW_CONCURRENT'] = '1';

        $config = TestbenchConfig::fromEnvironment();

        self::assertNull(RunLock::acquire($config));
        self::assertFileDoesNotExist(RunLock::pathFor($config));
    }

    /** The opt-out is an escape hatch, not a mode: with it set, nobody is refused. */
    public function testTheOptOutLetsASecondHolderThrough(): void
    {
        $_SERVER['MODX_TESTBENCH_ALLOW_CONCURRENT'] = '1';

        $config = TestbenchConfig::fromEnvironment();

        self::assertNull(RunLock::acquire($config));
        self::assertNull(RunLock::acquire($config));
    }

    /** `putenv()` with no `=` unsets the variable — for this process and for children yet to come. */
    private function forgetInheritedToken(): void
    {
        putenv('MODX_TESTBENCH_RUN_TOKEN');
        unset($_SERVER['MODX_TESTBENCH_RUN_TOKEN'], $_ENV['MODX_TESTBENCH_RUN_TOKEN']);
    }

    /**
     * A lock file nobody holds and nobody has touched for a week is litter: it is recreated by the
     * next `fopen()` for a tenth of a millisecond. Without a sweep the directory grows forever —
     * measured on the package's own machine, 91 files in a single day of work, because the suite's
     * throwaway configurations produce a fingerprint apiece.
     */
    public function testAFreeLockFileNobodyTouchedForAWeekIsSweptAway(): void
    {
        $stale = $this->existingLockFile('stale', age: 8 * 86400);

        $this->hold(TestbenchConfig::fromEnvironment());

        self::assertFileDoesNotExist($stale);
    }

    /**
     * Age alone is not permission to delete. A run that has been going for over a week is still a
     * run, and unlinking the file under it is exactly the failure of mutual exclusion the file is
     * kept outside the environment directory to avoid: the next process would create a new inode
     * at the same path and both would hold "the" lock.
     */
    public function testALockFileSomebodyStillHoldsSurvivesTheSweepHoweverOldItIs(): void
    {
        $held = $this->existingLockFile('held', age: 400 * 86400);

        $handle = fopen($held, 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $this->hold(TestbenchConfig::fromEnvironment());

            self::assertFileExists($held);
        } finally {
            fclose($handle);
        }
    }

    public function testAFreeButRecentLockFileIsLeftAlone(): void
    {
        $recent = $this->existingLockFile('recent', age: 60);

        $this->hold(TestbenchConfig::fromEnvironment());

        self::assertFileExists($recent);
    }

    /** The sweep runs while we hold our own lock, and it must not sweep the hand that runs it. */
    public function testOurOwnLockFileSurvivesTheSweep(): void
    {
        $config = TestbenchConfig::fromEnvironment();

        $this->hold($config);

        self::assertFileExists(RunLock::pathFor($config));
    }

    /**
     * A file of somebody else's, in the same directory, under a name that is not ours. The sweep
     * looks at `*.lock` and leaves everything else alone rather than tidying a directory it only
     * shares.
     */
    public function testTheSweepTouchesNothingButLockFiles(): void
    {
        // Through the helper, so that the directory exists: it is created by the first `acquire()`,
        // and this test writes into it before any lock has been taken.
        $foreign = dirname($this->existingLockFile('neighbour', age: 400 * 86400)) . '/notes.txt';
        self::assertNotFalse(file_put_contents($foreign, 'not ours'));
        touch($foreign, time() - 400 * 86400);

        $this->hold(TestbenchConfig::fromEnvironment());

        self::assertFileExists($foreign);
    }

    /** Creates a lock file of the given age next to ours, without taking it. */
    private function existingLockFile(string $name, int $age): string
    {
        $directory = dirname(RunLock::pathFor(TestbenchConfig::fromEnvironment()));

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $path = $directory . '/' . $name . '.lock';

        file_put_contents($path, "0000000000000000\npid 1, started long ago, database \"whatever\"");
        touch($path, time() - $age);

        return $path;
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

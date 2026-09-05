<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Support;

use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The mechanism behind the finding: six integration classes held a hard-coded name for the service
 * database, and a `DROP DATABASE IF EXISTS <name>` in a foreign `setUp()`/`tearDown()` wiped the
 * database out in the middle of a running test of another run — found live by three reviewers
 * independently.
 *
 * The test reproduces the MECHANISM rather than checking the format of the name: two real OS
 * processes perform exactly the sequence the `setUp()` of the six classes performs
 * (`DROP DATABASE IF EXISTS <name>; CREATE DATABASE <name>`), with a SHARED environment — that is,
 * exactly the case for which the {@see RunScopedDatabaseName} fingerprint alone is not enough: "two
 * terminals of one developer with one environment produce the same fingerprint". The processes
 * differ only in `getmypid()`, and that is the part this test is about.
 *
 * The synchronisation is by flag files rather than by `sleep()`: bare simultaneity is
 * non-deterministic (the OS does not guarantee two processes a simultaneous start), and the order
 * needed to reproduce the finding is this: "A created its database and wrote a marker → B created
 * ITS database and wrote a marker → both re-read THEIR OWN marker". With coinciding names step B
 * (`DROP DATABASE IF EXISTS`) wipes out what A wrote BEFORE A manages to read it — waiting on the
 * other's flag guarantees that by the time of the read both DROPs have already happened.
 */
#[Group('integration')]
final class RunScopedDatabaseNameTest extends TestCase
{
    private const BASE = 'modx_testbench_race_test';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->temporaryFiles = [];

        // The databases are cleaned up by the race participants themselves (the `finally` in
        // run-scoped-database-race-participant.php) rather than here: their names are computed from
        // the pid of the CHILD process, which the parent test does not know and cannot reproduce after
        // the fact.
    }

    public function testConcurrentRunsWithIdenticalEnvironmentDoNotWipeEachOthersDatabase(): void
    {
        $script = __DIR__ . '/run-scoped-database-race-participant.php';

        $aReady = $this->flagFile('a-ready');
        $bReady = $this->flagFile('b-ready');
        $aResult = $this->flagFile('a-result');
        $bResult = $this->flagFile('b-result');

        // Both participants compute the name of their database by ONE and the same formula
        // (RunScopedDatabaseName::forBase(self::BASE), with not a single addition from the test) in
        // THEIR OWN process — that is, with their own pid but with the environment inherited from the
        // parent (see the class docblock). `A`/`B` are only participant labels for the flag files and
        // for the markers inside the probe table; they are not mixed into the database name.
        $processA = new Process([PHP_BINARY, $script, self::BASE, 'A', $aReady, $bReady, $aResult]);
        $processB = new Process([PHP_BINARY, $script, self::BASE, 'B', $bReady, $aReady, $bResult]);

        $processA->setTimeout(30);
        $processB->setTimeout(30);

        $processA->start();
        $processB->start();

        try {
            $processA->wait();
            $processB->wait();
        } catch (ProcessTimedOutException $exception) {
            self::fail(
                'One of the race participants hung (waited for the other flag longer than allowed): '
                . $exception->getMessage()
            );
        }

        self::assertSame(
            0,
            $processA->getExitCode(),
            "Participant A: \n" . $processA->getOutput() . $processA->getErrorOutput()
        );
        self::assertSame(
            0,
            $processB->getExitCode(),
            "Participant B: \n" . $processB->getOutput() . $processB->getErrorOutput()
        );

        self::assertSame('own-marker-intact', trim((string) file_get_contents($aResult)));
        self::assertSame('own-marker-intact', trim((string) file_get_contents($bResult)));
    }

    private function flagFile(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/tb-race-' . $suffix . '-' . bin2hex(random_bytes(4)) . '.flag';
        $this->temporaryFiles[] = $path;

        return $path;
    }
}

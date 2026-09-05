<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use ModxKit\Testbench\Database\SnapshotFile;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use ModxKit\Testbench\Isolation\SnapshotIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `SnapshotIsolation::begin()` is a no-op while `PhpDumper` opens a connection of ITS OWN, so an
 * unclosed transaction on the core connection held a metadata lock, and a `DROP TABLE` from the
 * dumper's connection waited on it for `lock_wait_timeout` seconds. By default that is 31,536,000 —
 * a year, and not `innodb_lock_wait_timeout` as is commonly assumed. Reproduced by a reviewer: the
 * run stalled dead, and after the process was killed the database was left with 0 tables.
 */
#[Group('integration')]
final class SnapshotIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        $modx = TestbenchKernel::instance()->modx();

        if ($modx->pdo !== null && $modx->pdo->inTransaction()) {
            $modx->pdo->rollBack();
        }

        parent::tearDown();
    }

    public function testOpenTransactionOnTheKernelConnectionIsRolledBackAndReported(): void
    {
        $kernel = TestbenchKernel::instance();
        $modx = $kernel->modx();
        $isolation = new SnapshotIsolation($kernel->snapshots());
        $table = 'tb_a3_' . bin2hex(random_bytes(4));

        $isolation->begin($modx);

        // The test's transaction left open: it holds a metadata lock on everything it touched, and
        // the restore from the dumper's connection runs into it.
        self::assertTrue($modx->beginTransaction());
        $modx->exec("CREATE TEMPORARY TABLE `{$table}` (id INT)");
        $modx->query('SELECT id FROM `modx_site_content` LIMIT 1');

        $started = microtime(true);

        try {
            $isolation->end($modx);
            self::fail('The open transaction of the test went through silently.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('transaction', $exception->getMessage());
            self::assertStringContainsString('RefreshesDatabase', $exception->getMessage());
        }

        // Not "in a year" but at once: the refusal arrives BEFORE the dumper is approached.
        self::assertLessThan(30.0, microtime(true) - $started);

        // The transaction is closed, otherwise the next test would start on top of it and hit the same MDL.
        self::assertNotNull($modx->pdo);
        self::assertFalse($modx->pdo->inTransaction());
    }

    /**
     * `begin()` is no longer a silent no-op: an unusable baseline must be named BEFORE the body of
     * the test rather than after — otherwise the diagnostics arrive in the `tearDown()` of somebody
     * else's test.
     */
    public function testBeginRefusesWhenTheBaselineIsUnusable(): void
    {
        $kernel = TestbenchKernel::instance();
        $modx = $kernel->modx();
        $snapshots = $kernel->snapshots();
        $path = $snapshots->path();
        $backup = (string) file_get_contents($path);

        self::assertTrue(SnapshotFile::isComplete($path));

        try {
            file_put_contents($path, "SET FOREIGN_KEY_CHECKS=0;\n");

            $this->expectException(SnapshotFailedException::class);
            $this->expectExceptionMessage('is missing or is not read to the end');
            (new SnapshotIsolation($snapshots))->begin($modx);
        } finally {
            file_put_contents($path, $backup);
        }
    }
}

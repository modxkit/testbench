<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use MODX\Revolution\modX;
use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Exception\PackageRegistrationException;
use ModxKit\Testbench\Exception\TransactionLostException;
use ModxKit\Testbench\Exception\TransactionNotStartedException;
use ModxKit\Testbench\Isolation\TransactionIsolation;
use ModxKit\Testbench\Package\TransportInstaller;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checks the contract of `TransactionIsolation` directly, without `ModxKit\Testbench\TestCase`.
 *
 * There would otherwise be no test at all: a lost transaction is exactly the state in which the base
 * class's `tearDown()` throws a `TransactionLostException`, and the expected failure would have to
 * be caught outside the test. Here the transaction is managed by the test itself, so the failure is
 * checked where it happens, and the decoy table is dropped in `tearDown()` whatever the outcome.
 */
#[Group('integration')]
final class TransactionLossDetectionTest extends TestCase
{
    private const PROBE_TABLE = 'tb_transaction_loss_probe';

    /**
     * A table with the CORE prefix and the MyISAM engine — it reproduces the case in which the MODX
     * CLI setup silently sets OPT_OVERRIDE_TABLE_TYPE = 'MyISAM' and all 70 tables stop obeying the
     * rollback.
     */
    private const MYISAM_PROBE_TABLE = 'modx_tb_myisam_probe';

    /** The service table for the sentinel marker — the same one `TransactionIsolation` creates. */
    private const GUARD_TABLE = 'testbench_isolation_guard';

    private ?modX $modx = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modx = TestbenchKernel::instance()->modx();

        // `CREATE TABLE` commits instantly and survives even a killed PHPUnit process, so the decoy
        // table is dropped not only after the test but before it as well: otherwise the next run would
        // fail on "Table already exists" instead of checking the detector.
        $this->modx->exec('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
        $this->modx->exec('DROP TABLE IF EXISTS ' . self::MYISAM_PROBE_TABLE);
    }

    protected function tearDown(): void
    {
        if ($this->modx instanceof modX) {
            // An unclosed transaction would wreck the `beginTransaction()` of the next test ("There is
            // already an active transaction"), so it is closed first and the table dropped only after
            // that — a DROP would make an implicit commit anyway.
            if ($this->modx->pdo !== null && $this->modx->pdo->inTransaction()) {
                $this->modx->rollBack();
            }

            $this->modx->exec('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
            $this->modx->exec('DROP TABLE IF EXISTS ' . self::MYISAM_PROBE_TABLE);
            // The test below deliberately corrupts the service table: the next `begin()` must be given the
            // chance to create it anew in the right shape.
            $this->modx->exec('DROP TABLE IF EXISTS ' . self::GUARD_TABLE);
        }

        $this->modx = null;

        parent::tearDown();
    }

    public function testEndRollsBackTheOpenTransaction(): void
    {
        $modx = $this->kernel();
        $isolation = new TransactionIsolation();

        $isolation->begin($modx);

        $connection = $modx->pdo;

        self::assertNotNull($connection);
        self::assertTrue($connection->inTransaction());

        $isolation->end($modx);

        self::assertFalse($connection->inTransaction());
    }

    public function testEndReportsTransactionLostToDdl(): void
    {
        $modx = $this->kernel();
        $isolation = new TransactionIsolation();

        $isolation->begin($modx);

        // MySQL makes an implicit commit on DDL — from this moment the isolation is lost.
        self::assertNotFalse($modx->exec('CREATE TABLE ' . self::PROBE_TABLE . ' (id INT)'));
        self::assertNotNull($modx->pdo);
        self::assertFalse($modx->pdo->inTransaction());

        try {
            $isolation->end($modx);

            self::fail('A TransactionLostException was expected after DDL inside a transaction.');
        } catch (TransactionLostException $exception) {
            // The message must name both the cause and the next action: there is nothing left to roll
            // back, so the environment has to be rebuilt.
            self::assertStringContainsString('CREATE/ALTER/DROP TABLE', $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_FORCE_INSTALL=1', $exception->getMessage());
        }
    }

    public function testEndReportsMissingTransaction(): void
    {
        $modx = $this->kernel();

        self::assertNotNull($modx->pdo);
        self::assertFalse($modx->pdo->inTransaction());

        $this->expectException(TransactionLostException::class);
        $this->expectExceptionMessage(RefreshesDatabase::class);

        (new TransactionIsolation())->end($modx);
    }

    /**
     * Branch 1. A `START TRANSACTION` through `exec()` makes an implicit commit of the previous
     * transaction and opens a new one right away, so `PDO::inTransaction()` stays `true`: the
     * earlier detector let this case through silently, and everything the test had done up to that
     * line stayed in the database forever.
     *
     * @return iterable<string, array{string}>
     */
    public static function implicitCommitsThatKeepTheServerFlag(): iterable
    {
        yield 'START TRANSACTION' => ['START TRANSACTION'];
        yield 'BEGIN' => ['BEGIN'];
    }

    #[DataProvider('implicitCommitsThatKeepTheServerFlag')]
    public function testEndReportsTransactionRestartedByRawSql(string $statement): void
    {
        $modx = $this->kernel();
        $isolation = new TransactionIsolation();

        $isolation->begin($modx);
        $modx->exec($statement);

        // The server still considers the transaction open — the earlier detector had nothing to
        // object to.
        self::assertNotNull($modx->pdo);
        self::assertTrue($modx->pdo->inTransaction());

        $this->expectException(TransactionLostException::class);
        $this->expectExceptionMessage('was committed and opened again');
        $isolation->end($modx);
    }

    /**
     * Branch 2. `commit()` + `beginTransaction()` — the same thing, but by means of PDO.
     */
    public function testEndReportsTransactionCommittedAndReopened(): void
    {
        $modx = $this->kernel();
        $isolation = new TransactionIsolation();

        $isolation->begin($modx);
        self::assertTrue($modx->commit());
        self::assertTrue($modx->beginTransaction());

        self::assertNotNull($modx->pdo);
        self::assertTrue($modx->pdo->inTransaction());

        $this->expectException(TransactionLostException::class);
        $this->expectExceptionMessage('was committed and opened again');
        $isolation->end($modx);
    }

    /**
     * Branch 4. MyISAM tables ignore a transaction: `rollBack()` runs to no effect while
     * `inTransaction()` is honestly `true`. The detector must name this BEFORE the test believes the
     * rollback.
     */
    public function testBeginRefusesWhenPrefixedTablesAreNotTransactional(): void
    {
        $modx = $this->kernel();

        self::assertNotFalse(
            $modx->exec('CREATE TABLE ' . self::MYISAM_PROBE_TABLE . ' (id INT) ENGINE=MyISAM')
        );

        try {
            (new TransactionIsolation())->begin($modx);
            self::fail('A MyISAM table with the core prefix went past the detector.');
        } catch (TransactionLostException $exception) {
            self::assertStringContainsString('MyISAM', $exception->getMessage());
            self::assertStringContainsString(self::MYISAM_PROBE_TABLE, $exception->getMessage());
        }
    }

    /**
     * The detector must refuse rather than disarm itself quietly.
     *
     * A `CREATE TABLE IF NOT EXISTS` against an existing table with a DIFFERENT schema is not an
     * error but a no-op, and the marker insert that follows it silently returns `false`. An
     * unchecked result of `xPDO::exec()` left two arms of the detector (a raw SQL
     * `START TRANSACTION` and `commit()` + `beginTransaction()`) inoperative: at the consumer's that
     * would look like green tests without isolation — exactly the class of defect this work was
     * started for.
     */
    public function testBeginRefusesWhenTheGuardTableCannotHoldTheMarker(): void
    {
        $modx = $this->kernel();

        self::assertNotFalse($modx->exec('DROP TABLE IF EXISTS ' . self::GUARD_TABLE));
        self::assertNotFalse(
            $modx->exec('CREATE TABLE ' . self::GUARD_TABLE . ' (id INT PRIMARY KEY) ENGINE=InnoDB')
        );

        try {
            (new TransactionIsolation())->begin($modx);
            self::fail('An unusable service table went past the detector.');
        } catch (TransactionNotStartedException $exception) {
            self::assertStringContainsString(self::GUARD_TABLE, $exception->getMessage());
            self::assertStringContainsString('marker', $exception->getMessage());
        }

        // The refusal must not leave an open transaction behind it: the next test would run into
        // "There is already an active transaction" and would look for the cause in the wrong place.
        self::assertNotNull($modx->pdo);
        self::assertFalse($modx->pdo->inTransaction());
    }

    /**
     * Branch 3 — the BOUNDARY of the detector, not a defect in it.
     *
     * A write made from a DIFFERENT connection (including one from the `TransportInstaller::build()`
     * subprocess) is not subject to the test's transaction in principle and is not removed by a
     * rollback. The sentinel marker does not catch this and cannot: it lives on the core's connection
     * and knows nothing of foreign ones, while comparing the whole database before and after every
     * test is a snapshot, that is, a different isolation strategy.
     *
     * The test pins the boundary explicitly so that `docs/DX_GUIDE.md` does not promise more, and
     * points at the real protection: {@see \ModxKit\Testbench\Package\TransportInstaller} refuses to
     * work inside a test's transaction.
     */
    public function testWriteFromASecondConnectionIsOutsideTheDetectorsReach(): void
    {
        $modx = $this->kernel();
        $isolation = new TransactionIsolation();

        $modx->exec('CREATE TABLE ' . self::PROBE_TABLE . ' (id INT PRIMARY KEY)');

        $isolation->begin($modx);

        $second = $this->secondConnection();
        $second->exec('INSERT INTO ' . self::PROBE_TABLE . ' VALUES (1)');

        $isolation->end($modx);

        // The detector said nothing — and that is expected…
        self::assertNotNull($modx->pdo);
        self::assertFalse($modx->pdo->inTransaction());
        // …while the row survived the rollback.
        $statement = $modx->query('SELECT COUNT(*) FROM ' . self::PROBE_TABLE);
        self::assertNotFalse($statement);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    /**
     * Branch 3, the real protection: `TransportInstaller` is the only place in the package where a
     * foreign connection appears as a matter of course (the build subprocess), and that is exactly
     * where the refusal stands. The message must name the trait rather than leave the developer with
     * the consequences.
     */
    public function testTransportInstallerRefusesToRunInsideTheTestTransaction(): void
    {
        $modx = $this->kernel();
        $isolation = new TransactionIsolation();

        $isolation->begin($modx);

        try {
            (new TransportInstaller($modx))->buildAndInstall(__DIR__ . '/nonexistent.build.php');
            self::fail('TransportInstaller ran inside the test transaction.');
        } catch (PackageRegistrationException $exception) {
            self::assertStringContainsString(RefreshesDatabase::class, $exception->getMessage());
            self::assertStringContainsString('subprocess', $exception->getMessage());
        } finally {
            $isolation->end($modx);
        }
    }

    private function secondConnection(): PDO
    {
        $database = DatabaseConfig::fromEnvironment();

        return new PDO(
            $database->dsn(),
            $database->user,
            $database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * `begin()` must tell "the database is unreachable" from "the transaction was lost": otherwise
     * the test would run without isolation, and `end()` would name DDL as the cause.
     *
     * `$modx->pdo` cannot be substituted here: `beginTransaction()` first calls `connect()`, which
     * assigns `$this->pdo =& $this->connection->pdo` anew
     * (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:418-428`, the reference on line 424) — the
     * connection would simply be restored. The documented `false` (`xPDO.php:2475-2477`) is honestly
     * reproduced only by substituting the method itself.
     */
    public function testBeginReportsUnavailableConnection(): void
    {
        $modx = $this->createStub(modX::class);
        $modx->method('beginTransaction')->willReturn(false);

        $this->expectException(TransactionNotStartedException::class);
        $this->expectExceptionMessage('MODX_TESTBENCH_DB_HOST');

        (new TransactionIsolation())->begin($modx);
    }

    /**
     * The `pdo === null` branch in `end()` is a core whose connection never came up. A real
     * connection cannot be brought up "halfway", so the state is reproduced by substituting the
     * public property and is restored in `finally`; the assignment also goes by reference into
     * `xPDOConnection::$pdo`, so the restoration brings that one back too.
     */
    public function testEndReportsUnavailableConnection(): void
    {
        $modx = $this->kernel();
        $connection = $modx->pdo;

        self::assertNotNull($connection);

        $modx->pdo = null;

        try {
            (new TransactionIsolation())->end($modx);

            self::fail('A TransactionLostException was expected for a core with no connection.');
        } catch (TransactionLostException $exception) {
            self::assertStringContainsString('isolation is lost', $exception->getMessage());
        } finally {
            $modx->pdo = $connection;
        }
    }

    private function kernel(): modX
    {
        self::assertInstanceOf(modX::class, $this->modx);

        return $this->modx;
    }
}

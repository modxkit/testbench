<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Support;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Tests\Support\FixtureDatabaseUser;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The fixture that creates a DBMS account is checked itself: it lives on the SHARED server,
 * survives `DROP DATABASE` and must therefore clean up after itself whatever the outcome of the run.
 *
 * The promise in the README ("whatever a killed run leaves behind is cleaned up on the next launch")
 * is kept by exactly this code — which means it must be checked rather than asserted.
 *
 * The check itself reads `mysql.user` (enumerating the fixture accounts and sweeping up orphans),
 * and the global `CREATE USER`/`GRANT` privileges do not grant that. A dependency on a privilege of
 * the run's account is expressed as a group excluded by configuration rather than as a
 * `markTestSkipped()` (which used to stand here: a skip is indistinguishable from a green run under
 * `failOnSkipped`). The group is excluded by default in `phpunit.xml`; the check inside
 * {@see self::setUp()} is a hard refusal for whoever requested the group explicitly without
 * sufficient privileges, rather than a "soft" skip.
 */
#[Group('integration')]
#[Group('mysql-user-table')]
final class FixtureDatabaseUserTest extends TestCase
{
    use FixtureDatabaseUser;

    private const USER_PREFIX = 'modx_tb_selftest_';

    /**
     * The name of an "orphaned" account: deliberately not the pid of this process — that is exactly
     * what a leftover from a killed run looks like, and the current one has nothing to collide with.
     */
    private const ORPHAN = self::USER_PREFIX . 'killedrun';

    protected function setUp(): void
    {
        $server = $this->serverConnection();

        // The check of the fixture itself reads `mysql.user` — as does the sweeping of orphans inside
        // it. The global CREATE USER/GRANT do not grant that: the requirement is named in the README,
        // in the "Developing the package itself" section. That is exactly why the mysql-user-table
        // group is excluded by default — if it is requested explicitly and the privilege is still
        // missing, that is a refusal rather than a silent skip.
        try {
            $server->query('SELECT `user` FROM mysql.user LIMIT 0');
        } catch (PDOException $exception) {
            self::fail(
                'The mysql-user-table group was requested, but the account of this run is not allowed '
                . 'SELECT on mysql.user, without which there is nothing to check the fixture-account cleanup with: '
                . $exception->getMessage()
            );
        }

        // A leftover of a killed run of THIS class (`modx_tb_selftest_<pid>`) would otherwise redden
        // the strict comparison below: a test about orphans must not break because of an orphan.
        $this->dropStaleFixtureUsers($server, self::USER_PREFIX);
    }

    protected function tearDown(): void
    {
        try {
            $server = $this->serverConnection();
            $this->dropFixtureUser($server);
            $server->exec('DROP USER IF EXISTS ' . $server->quote(self::ORPHAN) . "@'%'");
        } catch (Throwable) {
            // Cleanup "where possible": an unreachable DBMS must not break the test report.
        }
    }

    public function testCreatingTheUserSweepsOrphansAndTakesADeterministicName(): void
    {
        $server = $this->serverConnection();
        $server->exec(
            'CREATE USER IF NOT EXISTS ' . $server->quote(self::ORPHAN) . "@'%' IDENTIFIED BY 'orphan'"
        );

        self::assertSame([self::ORPHAN], $this->fixtureUsers(), 'No orphan was created — there is nothing to check.');

        $user = $this->createFixtureUser($server, self::USER_PREFIX, 'p-' . bin2hex(random_bytes(4)));

        // The name is deterministic: a leftover of a killed run is predictable and therefore found.
        self::assertSame(self::USER_PREFIX . getmypid(), $user);
        // The orphan is gone, and the account of this run is in place and alone.
        self::assertSame([$user], $this->fixtureUsers());
    }

    public function testDropRemovesTheUserAndSurvivesASecondCall(): void
    {
        $server = $this->serverConnection();
        $user = $this->createFixtureUser($server, self::USER_PREFIX, 'p-' . bin2hex(random_bytes(4)));

        self::assertSame([$user], $this->fixtureUsers());

        $this->dropFixtureUser($server);

        self::assertSame([], $this->fixtureUsers());

        // A repeated call from tearDown() after the cleanup in the test itself breaks nothing.
        $this->dropFixtureUser($server);

        self::assertSame([], $this->fixtureUsers());
    }

    /**
     * The accounts carrying the fixture prefix that are on the server right now.
     *
     * @return list<string>
     */
    private function fixtureUsers(): array
    {
        $statement = $this->serverConnection()->prepare(
            "SELECT `user` FROM mysql.user WHERE `user` LIKE ? ESCAPE '\\\\' ORDER BY `user`"
        );
        $statement->execute([$this->fixtureUserLikePattern(self::USER_PREFIX)]);

        $users = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $user) {
            if (is_string($user)) {
                $users[] = $user;
            }
        }

        return $users;
    }

    private function serverConnection(): PDO
    {
        $database = DatabaseConfig::fromEnvironment();

        return new PDO(
            $database->dsnWithoutDatabase(),
            $database->user,
            $database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}

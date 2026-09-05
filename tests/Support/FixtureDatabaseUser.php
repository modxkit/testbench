<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Support;

use PDO;
use PDOException;

/**
 * A temporary DBMS account with cut-down privileges — a fixture for the checks that need the
 * server to REFUSE (no privilege to `DROP`, no privilege on a table). There is no other way to
 * obtain such a refusal without touching the global settings of the shared server.
 *
 * The account lives on the SERVER rather than in the test database and survives `DROP DATABASE`,
 * so the fixture must clean up after itself on ANY outcome of the run, not only the regular one:
 *
 * - the name is deterministic (`<prefix><pid>`), not random;
 * - before creating one, everything left over from earlier runs with the same prefix is dropped:
 *   the operating system does not reuse a pid immediately, and an account left by a foreign pid
 *   would otherwise live on the shared server until a chance collision — that is, practically
 *   forever.
 *
 * A random name cannot give that guarantee in principle: there is nothing for it to collide with,
 * and every killed run (Ctrl+C, a CI timeout) would leave one more user on the server.
 */
trait FixtureDatabaseUser
{
    /** The account created by this run; `null` — none was created. */
    private ?string $fixtureUser = null;

    /**
     * Creates an account with no privileges at all and returns its name. Granting privileges is
     * the caller's business: every check needs its own.
     */
    private function createFixtureUser(PDO $server, string $prefix, string $password): string
    {
        $this->dropStaleFixtureUsers($server, $prefix);

        $this->fixtureUser = $prefix . getmypid();

        $server->exec('DROP USER IF EXISTS ' . $this->quoteFixtureUser($server, $this->fixtureUser));
        $server->exec(
            'CREATE USER ' . $this->quoteFixtureUser($server, $this->fixtureUser)
            . ' IDENTIFIED BY ' . $server->quote($password)
        );

        return $this->fixtureUser;
    }

    /**
     * Drops the account of this run. Called from `tearDown()`, that is, on a failed test too.
     */
    private function dropFixtureUser(PDO $server): void
    {
        if ($this->fixtureUser === null) {
            return;
        }

        $server->exec('DROP USER IF EXISTS ' . $this->quoteFixtureUser($server, $this->fixtureUser));
        $this->fixtureUser = null;
    }

    private function dropStaleFixtureUsers(PDO $server, string $prefix): void
    {
        try {
            $statement = $server->prepare("SELECT `user` FROM mysql.user WHERE `user` LIKE ? ESCAPE '\\\\'");
            $statement->execute([$this->fixtureUserLikePattern($prefix)]);

            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $user) {
                if (is_string($user) && $user !== '') {
                    $server->exec('DROP USER IF EXISTS ' . $this->quoteFixtureUser($server, $user));
                }
            }
        } catch (PDOException) {
            // The run's account may lack the privileges on `mysql.user` — cleanup is then
            // impossible, but the fixture below will itself fail on CREATE USER with a clear error
            // from the server. What is caught is EXACTLY a refusal by the DBMS: a broad `Throwable`
            // would swallow an error in the fixture itself, and the cleanup would silently stop
            // working (which has already happened).
        }
    }

    /**
     * The LIKE pattern for the accounts carrying this prefix.
     *
     * Shared between the trait and its test deliberately: `_`, `%` and `\` are LIKE wildcards and
     * the escape character, and a second, separately written copy of this expression would select
     * a DIFFERENT set of accounts than the trait drops.
     */
    protected function fixtureUserLikePattern(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
    }

    private function quoteFixtureUser(PDO $server, string $user): string
    {
        return $server->quote($user) . "@'%'";
    }
}

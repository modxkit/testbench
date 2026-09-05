<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Isolation;

use MODX\Revolution\modX;

/**
 * The fifth vessel of state — the MySQL connection's session.
 *
 * There is one core connection for the whole run, so a `SET SESSION sql_mode = ''`, a
 * `SET autocommit = 0` or a cleared `foreign_key_checks` made by a test (or by the code under test)
 * live until the end of the run and change the behaviour of ALL subsequent tests: an empty
 * `sql_mode` turns truncation errors into warnings, and `autocommit = 0` leaves an implicit
 * transaction open, because of which the next `beginTransaction()` fails with "There is already an
 * active transaction". Neither a transaction nor a snapshot rolls the session back — it has to be
 * restored explicitly.
 *
 * @internal
 */
final class SessionState
{
    /**
     * Returns the session variables to the server's values.
     *
     * Call strictly BEFORE the test's transaction is opened: a `SET autocommit = 1` with an open
     * transaction commits it.
     */
    public static function reset(modX $modx): void
    {
        $modx->exec(
            'SET SESSION sql_mode = DEFAULT, SESSION autocommit = 1, '
            . 'SESSION foreign_key_checks = 1, SESSION unique_checks = 1'
        );
    }
}

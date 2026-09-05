<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Support;

use ModxKit\Testbench\Environment\TestbenchConfig;

/**
 * The name of this run's service database is derived, not hard-coded. A hard-coded name meant that
 * two runs pointing at the same DBMS server (say, an implementation in one session and a review in
 * another, or two terminals of the same developer) dropped each other's databases in the middle of
 * a foreign test with their own `DROP DATABASE IF EXISTS` — a finding caught live by three
 * reviewers independently.
 *
 * The discriminator is composite, and that is a conscious compromise rather than an oversight —
 * each part closes its own half of the collision, and NEITHER closes both:
 *
 * - {@see TestbenchConfig::fingerprint()} (12 hex characters — that length is held precisely for
 *   this place) separates runs with DIFFERENT environments: different credentials, a different
 *   database name in `MODX_TESTBENCH_DB_NAME`, and so on. But two terminals of ONE developer with
 *   ONE environment produce the SAME fingerprint — on its own it does not protect against that
 *   collision;
 * - `getmypid()` separates any two processes, identically configured ones included — but does not
 *   survive a restart. The pid space is finite and the operating system hands a pid out again
 *   sooner or later — reasoning, not a measurement (wrapping the pid just to check it costs more
 *   than the finding itself): with the same environment fingerprint a reissued pid would give the
 *   very same name, and then the `DROP DATABASE IF EXISTS` in `setUp()` would pick up exactly that
 *   orphan. There is NO automatic cleanup of "orphaned" databases here — unlike the accounts of
 *   {@see FixtureDatabaseUser}, which have a recognisable prefix and `dropStaleFixtureUsers()`,
 *   the databases of this scheme have no such collector. An orphaned database is recognised by the
 *   SHAPE of its name, `<base>_<12 hex>_<digits>` (for example,
 *   `modx_testbench_snapshot_test_4170f4b0fbac_51234`), and is dropped by hand with
 *   `DROP DATABASE` by exactly that shape. The shared prefix `modx_testbench_` is unusable for
 *   cleanup: the working databases of other agents and of earlier rounds fall under it as well
 *   (measured on the development server of this branch — `modx_testbench_r1`..`_r4`,
 *   `_t23`..`_t27d`, `_v23`..`_v27d`, `_leftovers_guard`, 23 databases under the prefix in total,
 *   not one of them an orphan).
 *
 * This introduces no new global constant for the database name — the name is computed afresh on
 * every call rather than declared.
 */
final class RunScopedDatabaseName
{
    public static function forBase(string $base): string
    {
        return $base . '_' . TestbenchConfig::fromEnvironment()->fingerprint() . '_' . getmypid();
    }
}

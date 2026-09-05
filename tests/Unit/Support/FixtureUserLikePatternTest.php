<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Support;

use ModxKit\Testbench\Tests\Support\FixtureDatabaseUser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The LIKE pattern by which {@see FixtureDatabaseUser} drops the accounts of earlier runs.
 *
 * The check lives here rather than in `tests/Integration/Support/FixtureDatabaseUserTest.php`:
 * that class belongs to the `mysql-user-table` group, excluded by configuration — it requires the
 * `SELECT` privilege on `mysql.user`, whereas building the pattern does not touch the DBMS at all
 * and must be checkable on any machine.
 *
 * All three characters that are significant for LIKE are escaped. An unescaped `_` matches ANY
 * character and `%` matches any sequence: an under-escaped pattern would drop accounts the fixture
 * never created.
 */
#[Group('unit')]
final class FixtureUserLikePatternTest extends TestCase
{
    use FixtureDatabaseUser;

    public function testUnderscoreOfTheOrdinaryPrefixIsEscaped(): void
    {
        self::assertSame('modx\_tb\_%', $this->fixtureUserLikePattern('modx_tb_'));
    }

    public function testBackslashAndPercentAreEscapedToo(): void
    {
        self::assertSame('a\\\\b\%c\_%', $this->fixtureUserLikePattern('a\\b%c_'));
    }
}

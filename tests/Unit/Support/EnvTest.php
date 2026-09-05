<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Support;

use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\Env;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class EnvTest extends TestCase
{
    private const KEY = 'MODX_TESTBENCH_ENV_PROBE';

    protected function tearDown(): void
    {
        putenv(self::KEY);
        unset($_SERVER[self::KEY], $_ENV[self::KEY]);

        parent::tearDown();
    }

    /**
     * A non-numeric value used to be truncated silently: `'330a'` turned into `330`, `'abc'` into
     * `0`. A typo in `MODX_TESTBENCH_DB_PORT` sent the connection to a different port and was then
     * explained away as "the DBMS is unreachable".
     */
    public function testIntRefusesANonNumericValueInsteadOfTruncatingIt(): void
    {
        $_SERVER[self::KEY] = '330a';

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessageMatches('/' . self::KEY . '/');

        Env::int(self::KEY, 3306);
    }

    public function testIntReadsAWholeNumberAndFallsBackToTheDefault(): void
    {
        $_SERVER[self::KEY] = ' 3307 ';

        self::assertSame(3307, Env::int(self::KEY, 3306));

        unset($_SERVER[self::KEY]);

        self::assertSame(3306, Env::int(self::KEY, 3306));
    }

    /**
     * `bool()` had no test at all, and the hole was measured rather than suspected: a reviewer
     * replaced the whole body of the method with `return true;` and the unit suite stayed green
     * (`OK (253 tests, 845 assertions)`), while five neighbouring mutations of `src/` were caught.
     *
     * Its single consumer is `MODX_TESTBENCH_FORCE_INSTALL` ({@see \ModxKit\Testbench\Environment\TestbenchConfig}),
     * where a wrong `true` reinstalls MODX on every run and a wrong `false` silently ignores what
     * the consumer asked for.
     */
    public function testBoolRecognisesTheWrittenWordsAndRefusesEverythingElse(): void
    {
        foreach (['1', 'true', 'TRUE', 'True', 'yes', 'YES', 'on', 'ON'] as $value) {
            $_SERVER[self::KEY] = $value;

            self::assertTrue(Env::bool(self::KEY), sprintf('"%s" must read as true', $value));
        }

        // `0` and `off` are the mirror of the list above; `2`, `y` and `да` are the shape of a
        // typo. None of them may become `true` by accident: the flag reinstalls the environment.
        foreach (['0', 'false', 'no', 'off', 'OFF', '2', 'y', 'да', ' 1'] as $value) {
            $_SERVER[self::KEY] = $value;

            self::assertFalse(Env::bool(self::KEY), sprintf('"%s" must not read as true', $value));
        }
    }

    /**
     * The default applies to the ABSENCE of a value, not to a value that was not recognised: an
     * unrecognised value is an answer, and answering `on` to it because the default says so would
     * turn a typo into the opposite of what is written.
     */
    public function testBoolFallsBackToTheDefaultOnlyWhenTheVariableIsUnset(): void
    {
        $_SERVER[self::KEY] = '';

        self::assertFalse(Env::bool(self::KEY), 'an empty string is an unset variable');
        self::assertTrue(Env::bool(self::KEY, true), 'and the default is what an unset variable gives');

        $_SERVER[self::KEY] = 'off';

        self::assertFalse(Env::bool(self::KEY, true), 'a written value overrides the default');
    }

    /**
     * The pattern the whole of `tests/` rests on: a variable can only be cleared for the code
     * under test by an EMPTY STRING in `$_SERVER`. `unset($_SERVER[…])` does not override the real
     * process variable — `Env::get()` will read it from `getenv()` as a fallback, and a test that
     * "cleared" the variable would go on working with a value inherited from the CI environment.
     */
    public function testEmptyStringHidesARealProcessVariableWhileUnsetDoesNot(): void
    {
        putenv(self::KEY . '=a real value');

        $_SERVER[self::KEY] = '';

        self::assertNull(Env::get(self::KEY), 'an empty string must clear the variable');

        unset($_SERVER[self::KEY]);

        self::assertSame(
            'a real value',
            Env::get(self::KEY),
            'unset() hands control back to the real process variable — and that is the trap'
        );
    }
}

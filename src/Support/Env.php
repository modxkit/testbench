<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Support;

use ModxKit\Testbench\Exception\TestbenchException;

/**
 * Reading the package's environment variables.
 *
 * IMPORTANT for tests: "unsetting" a variable for the code under test is possible only with an EMPTY
 * STRING (`$_SERVER['MODX_TESTBENCH_…'] = ''`). `unset($_SERVER[…])` does not override the real
 * process variable — {@see self::get()} will still read it from `getenv()`, and a test that believed
 * it had unset the variable will carry on working with the value inherited from the CI environment.
 * Overwriting (`$_SERVER[…] = 'value'`) works as expected — it is unsetting specifically that does
 * not. The pattern is pinned by the test `tests/Unit/Support/EnvTest.php`.
 *
 * @internal
 */
final class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        if (!is_scalar($value)) {
            return $default;
        }

        $value = (string) $value;

        return $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * A non-numeric value is a refusal rather than a silent truncation: `(int) '330a'` gives `330`
     * and `(int) 'abc'` gives `0`, and a typo in `MODX_TESTBENCH_DB_PORT` used to steer the
     * connection to a different port, explaining itself afterwards as "the DBMS is unreachable".
     */
    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        // `filter_var` allows surrounding whitespace by itself and rejects everything else —
        // unlike a type cast, which takes the numeric prefix and stays silent.
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        if ($parsed === false) {
            throw new TestbenchException(sprintf(
                'Environment variable %s must be an integer, and its value is "%s". '
                . 'Fix the value or unset the variable so that the default (%d) is used.',
                $key,
                $value,
                $default
            ));
        }

        return $parsed;
    }
}

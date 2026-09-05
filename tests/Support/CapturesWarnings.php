<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Support;

/**
 * Capture of PHP warnings for the tests.
 *
 * Independent of `display_errors`: with `display_errors=0` (the production-ini standard the CI
 * images are built with) a warning is printed nowhere, and a check made against the output would
 * go falsely green. It also honours the `@` operator honestly: a test must see exactly what the
 * user sees, otherwise a suppressed warning would count as "shown". The suppression criterion is
 * the one PHPUnit itself uses: `@` leaves only the insuppressible bits of the reporting level.
 */
trait CapturesWarnings
{
    /**
     * @param callable(): void $run
     *
     * @return list<string>
     */
    protected function captureWarnings(callable $run): array
    {
        $captured = [];

        // The levels the `@` operator cannot clear: for the duration of a suppressed expression
        // PHP 8 leaves only those of the current level. Checking `error_reporting() & $severity`
        // is not an option — PHPUnit lowers the level itself for the duration of a test
        // (`Runner\ErrorHandler`), and then every warning would look "suppressed"; PHPUnit uses
        // the same criterion as here on its own side.
        $insuppressible = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR
            | E_RECOVERABLE_ERROR | E_PARSE;

        set_error_handler(static function (int $severity, string $message) use (&$captured, $insuppressible): bool {
            // A diagnostic suppressed by the `@` operator is not printed by the regular
            // handler — and is not recorded here either: otherwise the capture would "see" what
            // the user does not.
            if ((error_reporting() & ~$insuppressible) !== 0) {
                $captured[] = $message;
            }

            return true;
        });

        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $captured;
    }
}

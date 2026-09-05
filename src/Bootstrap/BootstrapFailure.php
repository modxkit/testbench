<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Bootstrap;

use Throwable;

/**
 * The reason `bootstrap.php` failed to prepare the environment.
 *
 * The bootstrap is obliged to swallow that failure (level 1 does not need a database, and a failed
 * preparation must not fail the unit job), but "not throwing" is not "erasing". The `catch` used to
 * catch a `\Throwable` without even a variable, and the genuine cause vanished without a trace:
 * level 1 then threw an exception of its own under the SINGLE hypothesis "the core is not on disk"
 * and gave two pieces of advice, both wrong when in truth the DBMS had not come up.
 *
 * Here the cause survives the bootstrap so that `Unit\UnitTestCase::setUp()` can attach it as the
 * `previous` of its own exception.
 *
 * @internal
 */
final class BootstrapFailure
{
    private static ?Throwable $failure = null;

    public static function record(Throwable $failure): void
    {
        self::$failure = $failure;
    }

    public static function last(): ?Throwable
    {
        return self::$failure;
    }

    /**
     * Needed by tests that put a cause here themselves: the static lives until the end of the
     * PHPUnit process and without a reset would leak into the next test.
     */
    public static function forget(): void
    {
        self::$failure = null;
    }
}

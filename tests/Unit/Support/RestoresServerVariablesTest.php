<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Support;

use ModxKit\Testbench\Tests\Support\RestoresServerVariables;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Restoring `$_SERVER` must tell "the variable was not there" from "the variable was there with a
 * value that looks like an absence sentinel".
 *
 * Both earlier copies of this code told them apart by value: `CommandsTest::useIsolatedKernel()`
 * recorded absence as `false`, {@see \ModxKit\Testbench\Tests\Support\OwnsTestbenchEnvironment}
 * as `null`. Both `false` and `null` are legitimate values of a `$_SERVER` entry, so the
 * restoration erased a variable it was obliged to bring back.
 */
#[Group('unit')]
final class RestoresServerVariablesTest extends TestCase
{
    use RestoresServerVariables;

    private const KEY = 'MODX_TESTBENCH_RESTORE_PROBE';

    public function testRestoresValueThatLooksLikeTheAbsenceSentinel(): void
    {
        $_SERVER[self::KEY] = false;

        $restore = $this->serverVariableRestorer([self::KEY]);
        $_SERVER[self::KEY] = 'substituted';
        $restore();

        self::assertArrayHasKey(self::KEY, $_SERVER, 'The variable was there — it must be brought back.');
        self::assertFalse($_SERVER[self::KEY]);

        unset($_SERVER[self::KEY]);
    }

    public function testRestoresNullValueRatherThanRemovingTheVariable(): void
    {
        $_SERVER[self::KEY] = null;

        $restore = $this->serverVariableRestorer([self::KEY]);
        $_SERVER[self::KEY] = 'substituted';
        $restore();

        self::assertArrayHasKey(self::KEY, $_SERVER);
        self::assertNull($_SERVER[self::KEY]);

        unset($_SERVER[self::KEY]);
    }

    public function testRemovesVariableThatDidNotExistBefore(): void
    {
        unset($_SERVER[self::KEY]);

        $restore = $this->serverVariableRestorer([self::KEY]);
        $_SERVER[self::KEY] = 'substituted';
        $restore();

        self::assertArrayNotHasKey(self::KEY, $_SERVER);
    }
}

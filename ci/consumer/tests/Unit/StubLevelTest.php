<?php

declare(strict_types=1);

namespace ModxKit\Testbench\ReusableWorkflowConsumer\Tests\Unit;

use MODX\Revolution\modX;
use ModxKit\Testbench\Unit\UnitTestCase;

/**
 * Level 1 as a third-party extra sees it: no database, the stub passes `instanceof modX`.
 */
final class StubLevelTest extends UnitTestCase
{
    public function testTheStubIsAModxInstance(): void
    {
        self::assertInstanceOf(modX::class, $this->modx);
    }
}

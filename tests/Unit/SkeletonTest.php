<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use ModxKit\Testbench\Exception\TestbenchException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
final class SkeletonTest extends TestCase
{
    public function testBaseExceptionIsRuntimeException(): void
    {
        $exception = new TestbenchException('boom');

        // `class_parents()` rather than `is_subclass_of()`/`assertInstanceOf()`/`instanceof`: all
        // three direct forms are rejected by PHPStan at level max as certainly true
        // (`function.alreadyNarrowedType`, `staticMethod.alreadyNarrowedType`) — measured on each
        // of them. The assertion is still needed: it goes red if the parent changes.
        self::assertContains(RuntimeException::class, class_parents(TestbenchException::class));
        self::assertSame('boom', $exception->getMessage());
    }
}

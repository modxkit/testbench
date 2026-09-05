<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Exception;

use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Exception\UnsupportedStubOperationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class UnsupportedStubOperationExceptionTest extends TestCase
{
    public function testForMethodBuildsMessageNamingTheMethod(): void
    {
        $exception = UnsupportedStubOperationException::forMethod('runProcessor');

        self::assertContains(TestbenchException::class, class_parents(UnsupportedStubOperationException::class));
        self::assertStringContainsString('runProcessor()', $exception->getMessage());
    }

    public function testActuallyFiresAsAThrowableException(): void
    {
        $this->expectException(UnsupportedStubOperationException::class);
        $this->expectExceptionMessage('runProcessor()');

        throw UnsupportedStubOperationException::forMethod('runProcessor');
    }
}

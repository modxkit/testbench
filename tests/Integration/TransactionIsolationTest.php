<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration;

use MODX\Revolution\modResource;
use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class TransactionIsolationTest extends TestCase
{
    public function testCreatesResourceInsideTransaction(): string
    {
        $pagetitle = 'testbench-' . bin2hex(random_bytes(4));

        $resource = $this->modx->newObject(modResource::class);
        self::assertInstanceOf(modResource::class, $resource);

        $resource->fromArray(['pagetitle' => $pagetitle, 'alias' => $pagetitle, 'context_key' => 'web']);

        self::assertTrue($resource->save());
        self::assertNotNull($this->modx->getObject(modResource::class, ['pagetitle' => $pagetitle]));

        return $pagetitle;
    }

    #[Depends('testCreatesResourceInsideTransaction')]
    public function testResourceFromPreviousTestWasRolledBack(string $pagetitle): void
    {
        self::assertNull($this->modx->getObject(modResource::class, ['pagetitle' => $pagetitle]));
    }
}

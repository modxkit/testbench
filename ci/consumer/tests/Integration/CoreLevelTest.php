<?php

declare(strict_types=1);

namespace ModxKit\Testbench\ReusableWorkflowConsumer\Tests\Integration;

use ModxKit\Testbench\TestCase;

/**
 * Level 2 as a third-party extra sees it: a real core, installed by the workflow's own
 * `vendor/bin/modx-testbench install` step, is up and answers about itself.
 */
final class CoreLevelTest extends TestCase
{
    public function testTheInstalledCoreIsUp(): void
    {
        self::assertNotSame('', (string) $this->modx->getOption('core_path'));
        self::assertSame(1, (int) $this->modx->getCount(\MODX\Revolution\modContext::class, ['key' => 'web']));
    }
}

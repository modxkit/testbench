<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Stubs;

use MODX\Revolution\modResource;
use ModxKit\Testbench\Stubs\TestbenchModx;
use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A comparison of the level 1 stub against the real core: one and the same query is put to both and
 * must yield one and the same outcome.
 *
 * The test is an integration one (it needs a database and an installed core), but what it checks is
 * level 1: without a live core beside it there is nothing to check "does the stub repeat the
 * semantics of the core" with — all that is left is to believe the docblock. That is exactly what
 * let us down: the stub used to ignore a scalar criterion silently, and not one test of the package
 * ever passed it one.
 */
#[Group('integration')]
final class StubCoreParityTest extends TestCase
{
    /**
     * A scalar criterion is the primary key: `xPDO::sanitizePKCriteria()`
     * (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2764-2785`). Both edge branches come from there
     * too: a foreign identifier finds nothing, and `getObject()` with no criterion at all finds
     * nothing even against a non-empty table (`xPDO.php:839-846`), whereas `getCollection()` with
     * no criterion returns everything (`xPDO.php:862-864`).
     */
    public function testScalarCriterionSelectsTheSameWayInTheStubAndInTheCore(): void
    {
        $resource = $this->createResource();
        // The type of the primary key value (`int` or a numeric string) does not matter here and is
        // deliberately not cast: both the core and the stub get exactly what the core returned.
        $id = $resource->get('id');

        $stub = TestbenchModx::create();
        $stub->seed(modResource::class, new class ($id) {
            public function __construct(private readonly mixed $id)
            {
            }

            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return ['id' => $this->id];
            }
        });

        self::assertNotNull($this->modx->getObject(modResource::class, $id), 'core: its own key');
        self::assertNotNull($stub->getObject(modResource::class, $id), 'stub: its own key');

        self::assertNull($this->modx->getObject(modResource::class, 999999), 'core: a foreign key');
        self::assertNull($stub->getObject(modResource::class, 999999), 'stub: a foreign key');

        self::assertNull($this->modx->getObject(modResource::class), 'core: no criterion');
        self::assertNull($stub->getObject(modResource::class), 'stub: no criterion');

        self::assertCount(0, $this->modx->getCollection(modResource::class, 999999), 'core: collection by a foreign key');
        self::assertCount(0, $stub->getCollection(modResource::class, 999999), 'stub: collection by a foreign key');

        self::assertCount(1, $this->modx->getCollection(modResource::class, $id), 'core: collection by its own key');
        self::assertCount(1, $stub->getCollection(modResource::class, $id), 'stub: collection by its own key');
    }

    /**
     * The stub's `getOption()` is a port of `xPDO::getOption()` (xPDO.php:711-741). All three
     * divergences it used to have are compared: reading `$modx->config`, `$skipEmpty` and an array
     * of keys (in that branch the core deliberately loses the default, and the stub loses it the
     * same way).
     *
     * The test puts the live core's configuration back itself: there is one core per process, while
     * the transaction rolls back only the database.
     */
    public function testGetOptionAnswersTheSameWayInTheStubAndInTheCore(): void
    {
        $config = ['testbench_parity_value' => 'set', 'testbench_parity_empty' => ''];
        $stub = TestbenchModx::create($config);
        $original = $this->modx->config;
        $this->modx->config = array_merge($original, $config);

        try {
            foreach ([$this->modx, $stub] as $modx) {
                $where = $modx instanceof TestbenchModx ? 'stub' : 'core';

                self::assertSame('set', $modx->getOption('testbench_parity_value'), $where . ': config');
                self::assertSame('', $modx->getOption('testbench_parity_empty', null, 'fallback'), $where . ': an empty string');
                self::assertSame(
                    'fallback',
                    $modx->getOption('testbench_parity_empty', null, 'fallback', true),
                    $where . ': skipEmpty'
                );
                self::assertSame(
                    ['testbench_parity_value' => 'set', 'testbench_parity_missing' => null],
                    $modx->getOption(['testbench_parity_value', 'testbench_parity_missing'], null, 'fallback'),
                    $where . ': an array of keys'
                );
            }
        } finally {
            $this->modx->config = $original;
        }
    }

    /**
     * The core refuses an event with no active plugins with `false` (modX.php:1704-1711). The stub
     * has no event map at all — which means all events are like that.
     */
    public function testUnknownEventIsRefusedTheSameWayInTheStubAndInTheCore(): void
    {
        $stub = TestbenchModx::create();

        self::assertFalse($this->modx->invokeEvent('OnTestbenchEventThatWasNeverRegistered'));
        self::assertFalse($stub->invokeEvent('OnTestbenchEventThatWasNeverRegistered'));
    }
}

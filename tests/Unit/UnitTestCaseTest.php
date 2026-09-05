<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use MODX\Revolution\modX;
use ModxKit\Testbench\Exception\UnsupportedStubOperationException;
use ModxKit\Testbench\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\ExpectationFailedException;
use SampleExtra\Model\SampleItem;

#[Group('unit')]
final class UnitTestCaseTest extends UnitTestCase
{
    public function testStubIsARealModxInstance(): void
    {
        // No explicit assertInstanceOf(modX::class, ...): the `$this->modx` property is declared
        // with the type `TestbenchModx` (a descendant of modX), PHPStan already knows that from
        // the declaration — a repeated check would be provably redundant for it
        // (staticMethod.alreadyNarrowedType), as in TestbenchKernelTest. The real level 1
        // invariant is that the stub did not open a database connection while doing this.
        self::assertNull($this->modx->pdo, 'A level 1 stub must not open a database connection.');
    }

    public function testOptionsRoundTripWithoutDatabase(): void
    {
        $this->modx->setOption('sampleextra_limit', 15);

        self::assertSame(15, $this->modx->getOption('sampleextra_limit'));
        self::assertSame('fallback', $this->modx->getOption('missing_key', null, 'fallback'));
    }

    public function testExplicitOptionsArrayTakesPriorityOverStoredOptions(): void
    {
        $this->modx->setOption('sampleextra_limit', 15);

        // The second parameter of `getOption()` (`$options`) is a one-off set of values checked
        // BEFORE the stub's own settings store; that is how `xPDO::getOption()` behaves in the
        // real core (xPDO.php:711).
        self::assertSame(99, $this->modx->getOption('sampleextra_limit', ['sampleextra_limit' => 99]));
    }

    public function testRecordsEventsLogsAndLexiconKeys(): void
    {
        $this->modx->invokeEvent('OnDocFormSave', ['id' => 7]);
        $this->modx->log(modX::LOG_LEVEL_ERROR, 'import failed');
        $this->modx->lexicon('sampleextra.title');

        $this->assertEventInvoked('OnDocFormSave');
        $this->assertLogged('import failed');
        $this->assertLexiconUsed('sampleextra.title');
    }

    public function testAssertEventInvokedFailsWhenTheEventWasNotRecorded(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Event "OnNeverCalled" was never fired.');

        $this->assertEventInvoked('OnNeverCalled');
    }

    public function testAssertLoggedFailsWhenTheMessageWasNotRecorded(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The log holds no entry containing "never logged".');

        $this->assertLogged('never logged');
    }

    public function testAssertLexiconUsedFailsWhenTheKeyWasNotRequested(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Lexicon key "never.requested" was never requested.');

        $this->assertLexiconUsed('never.requested');
    }

    public function testSeededObjectsAreQueryable(): void
    {
        $item = new class () {
            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return ['name' => 'widget'];
            }
        };
        $other = new class () {
            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return ['name' => 'gadget'];
            }
        };

        $this->modx->seed(SampleItem::class, $item);
        $this->modx->seed(SampleItem::class, $other);

        self::assertSame($item, $this->modx->getObject(SampleItem::class, ['name' => 'widget']));
        self::assertNull($this->modx->getObject(SampleItem::class, ['name' => 'missing']));
        self::assertCount(2, $this->modx->getCollection(SampleItem::class));
        self::assertCount(1, $this->modx->getCollection(SampleItem::class, ['name' => 'gadget']));
    }

    public function testUnsupportedOperationExplainsItself(): void
    {
        $this->expectException(UnsupportedStubOperationException::class);
        $this->expectExceptionMessageMatches('/TestCase/');

        $this->modx->newObject(SampleItem::class);
    }

    public function testServicesContainerIsUsable(): void
    {
        $this->modx->services->add('probe', static fn (): string => 'ok');

        self::assertTrue($this->modx->services->has('probe'));
        self::assertSame('ok', $this->modx->services->get('probe'));
    }
}

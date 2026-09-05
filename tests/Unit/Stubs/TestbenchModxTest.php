<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Stubs;

use Closure;
use Error;
use MODX\Revolution\Error\modError;
use ModxKit\Testbench\Exception\UnsupportedStubOperationException;
use ModxKit\Testbench\Stubs\TestbenchModx;
use ModxKit\Testbench\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use SampleExtra\Model\SampleItem;
use Throwable;

/**
 * The contract of the core stub: what it can do and where its boundary runs.
 */
#[Group('unit')]
final class TestbenchModxTest extends UnitTestCase
{
    /**
     * A scalar criterion is the commonest xPDO idiom (`getObject($class, $id)`).
     *
     * The real core reduces it to `[primary key => value]`
     * (`xPDO::sanitizePKCriteria()`, core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2764-2785), so a
     * non-existent identifier finds nothing. The stub must behave the same way: before the fix it
     * silently ignored a non-scalar criterion and returned the first seeded object it came across.
     */
    public function testScalarCriterionIsTreatedAsPrimaryKey(): void
    {
        [$first, $second] = $this->seedTwoItems();

        self::assertSame($first, $this->modx->getObject(SampleItem::class, 1));
        self::assertSame($second, $this->modx->getObject(SampleItem::class, 2));
    }

    public function testScalarCriterionThatMatchesNothingFindsNothing(): void
    {
        $this->seedTwoItems();

        self::assertNull($this->modx->getObject(SampleItem::class, 999999));
        self::assertSame([], $this->modx->getCollection(SampleItem::class, 999999));
    }

    /**
     * `xPDO::getObject()` with no criterion returns `null`: `sanitizePKCriteria()` leaves `null` as
     * it is, and an `if ($criteria !== null)` stands after it (xPDO.php:839-846). In the same case
     * `getCollection()`, on the contrary, returns the whole collection (xPDO.php:862-864).
     */
    public function testMissingCriterionFindsNothingForSingleObject(): void
    {
        $this->seedTwoItems();

        self::assertNull($this->modx->getObject(SampleItem::class));
        self::assertCount(2, $this->modx->getCollection(SampleItem::class));
    }

    /**
     * A criterion object (`xPDOQuery`, `xPDOCriteria`) is SQL, and the stub has nothing to build
     * that with. The earlier edition silently turned it into "no criteria" and returned the first
     * object it came across.
     */
    public function testQueryObjectCriterionIsRefusedInsteadOfIgnored(): void
    {
        $this->seedTwoItems();

        $this->expectException(UnsupportedStubOperationException::class);
        $this->expectExceptionMessageMatches('/TestCase/');

        $this->modx->getObject(SampleItem::class, new \stdClass());
    }

    /**
     * `getOption()` in the core reads SPECIFICALLY `$this->config` (xPDO.php:711-741) — the stub, by
     * contrast, kept an `$options` array of its own and did not notice an edit to `$modx->config`.
     * Meanwhile `$modx->config` is the only thing `PackageRegistrar::applySettings()` and
     * `InteractsWithModx::setSetting()` write, and it is what a test edits when preparing its
     * environment.
     */
    public function testGetOptionReadsTheCoreConfig(): void
    {
        $this->modx->config['sampleextra_limit'] = 15;

        self::assertSame(15, $this->modx->getOption('sampleextra_limit'));
    }

    public function testSetOptionWritesTheCoreConfig(): void
    {
        $this->modx->setOption('sampleextra_limit', 15);

        self::assertSame(15, $this->modx->config['sampleextra_limit']);
    }

    /**
     * The stub ignored the fourth parameter, `$skipEmpty`, entirely: an empty string in a setting
     * arrived in the extra's code instead of the default.
     */
    public function testGetOptionHonoursSkipEmpty(): void
    {
        $this->modx->setOption('sampleextra_prefix', '');

        self::assertSame('', $this->modx->getOption('sampleextra_prefix', null, 'fallback'));
        self::assertSame('fallback', $this->modx->getOption('sampleextra_prefix', null, 'fallback', true));
    }

    /**
     * The core returns an array of keys as an array of values (xPDO.php:728-736); the stub reduced
     * it to a string — `Array to string conversion` and `null` instead of the values.
     *
     * In this branch the core deliberately loses the default (`$default = $option;`, where `$option`
     * is certainly `null`), so a missing key here is always `null` rather than the default that was
     * passed. The stub repeats that literally: a divergence would be worse.
     */
    public function testGetOptionAcceptsAnArrayOfKeys(): void
    {
        $this->modx->setOption('sampleextra_limit', 15);

        self::assertSame(
            ['sampleextra_limit' => 15, 'sampleextra_missing' => null],
            $this->modx->getOption(['sampleextra_limit', 'sampleextra_missing'], null, 'fallback')
        );
    }

    /**
     * The core returns `false` if the event is unknown or has no active plugins (modX.php:1704-1711).
     * The stub has no event map at all, so all events are unknown to it — and it used to answer with
     * an empty array, that is, "the event exists, the plugins said nothing". The static stub
     * `stubs/modx-revolution.php` declared an honest `array|bool`: only the runtime lied.
     */
    public function testInvokeEventAnswersLikeTheCoreDoesForAnUnknownEvent(): void
    {
        self::assertFalse($this->modx->invokeEvent('OnDocFormSave', ['id' => 7]));
        $this->assertEventInvoked('OnDocFormSave');
    }

    /**
     * The public `modX` constructor succeeded when `MODX_CORE_PATH` was defined, and after that
     * every method of such an object failed on an uninitialised `$store`. The stub is created only
     * through `create()` (`newInstanceWithoutConstructor()`), so forbidding the constructor breaks
     * nothing.
     */
    public function testConstructorIsRefused(): void
    {
        $this->expectException(UnsupportedStubOperationException::class);
        $this->expectExceptionMessageMatches('/TestbenchModx::create\(\)/');

        // Through reflection and with the arguments of the original `modX` constructor: their absence
        // from the stub's signature opens no loophole — PHP does not object to extra arguments to a
        // user-defined function.
        (new ReflectionClass(TestbenchModx::class))->newInstanceArgs(['/path/to/config', [], []]);
    }

    /**
     * A deliberate price of level 1, named in the DX guide: `seed()` accepts ANY object under any
     * `class-string`, while `getObject()` is declared as `@return T|null` (`T of xPDOObject`) —
     * otherwise consuming code under PHPStan max could not call `get()` on the result at all. So for
     * a seeded double the static analysis guarantee does not hold: PHPStan says nothing, and at
     * runtime `get()` is a bare `\Error`.
     *
     * The test holds both halves of the bargain at once: the double is seeded and is found
     * (the convenience), and the same double yields an `\Error` on an `xPDOObject` method (the
     * price). If `seed()` is ever narrowed to `xPDOObject`, this test will go red first — and the
     * price will stop being hidden in either direction.
     */
    public function testSeedAcceptsAnyObjectAtTheCostOfStaticGuarantees(): void
    {
        $double = new class () {
            public string $name = 'nightly';
        };

        $this->modx->seed(SampleItem::class, $double);
        $found = $this->modx->getObject(SampleItem::class, ['name' => 'nightly']);

        self::assertSame($double, $found);

        // PHPStan (max) considers the call correct: by declaration this is a `SampleItem`.
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/get\(\)/');

        $found->get('name');
    }

    /**
     * The core methods that are easy to reach for out of habit from the stub, and that there is
     * nothing to emulate with: without a constructor it has neither a connection nor a class map.
     *
     * Half of them used to produce an UNCATCHABLE fatal: `newQuery()` built an `xPDOQuery` out of an
     * unfilled `config['dbtype']`, the first call produced a catchable `Error`
     * "Class "\xPDO\Om\xPDOQuery" not found", and the second a `Cannot redeclare class
     * xPDO\Om\xPDOQuery` plus `Premature end of PHP process`: the remaining tests of the file did
     * not run and the cause was not named (reproduced in a consumer's configuration). The other half
     * answered silently and wrongly: `getFields()` with `[]`, `getPK()` with `null`,
     * `getService()` with `null`, `getCacheManager()` with a real `modCacheManager`.
     *
     * @return iterable<string, array{\Closure(TestbenchModx): mixed}>
     */
    public static function unsupportedOperationProvider(): iterable
    {
        yield 'newQuery' => [static fn (TestbenchModx $modx): mixed => $modx->newQuery(SampleItem::class)];
        yield 'getCount' => [static fn (TestbenchModx $modx): mixed => $modx->getCount(SampleItem::class)];
        yield 'getIterator' => [static fn (TestbenchModx $modx): mixed => $modx->getIterator(SampleItem::class)];
        yield 'getObjectGraph' => [
            static fn (TestbenchModx $modx): mixed => $modx->getObjectGraph(SampleItem::class, ['Category' => []]),
        ];
        yield 'getCollectionGraph' => [
            static fn (TestbenchModx $modx): mixed => $modx->getCollectionGraph(SampleItem::class, ['Category' => []]),
        ];
        yield 'removeCollection' => [
            static fn (TestbenchModx $modx): mixed => $modx->removeCollection(SampleItem::class, ['id' => 1]),
        ];
        yield 'removeObject' => [
            static fn (TestbenchModx $modx): mixed => $modx->removeObject(SampleItem::class, ['id' => 1]),
        ];
        yield 'updateCollection' => [
            static fn (TestbenchModx $modx): mixed => $modx->updateCollection(SampleItem::class, ['name' => 'x']),
        ];
        yield 'getFields' => [static fn (TestbenchModx $modx): mixed => $modx->getFields(SampleItem::class)];
        yield 'getPK' => [static fn (TestbenchModx $modx): mixed => $modx->getPK(SampleItem::class)];
        yield 'getService' => [static fn (TestbenchModx $modx): mixed => $modx->getService('registry')];
        yield 'getCacheManager' => [static fn (TestbenchModx $modx): mixed => $modx->getCacheManager()];
        yield 'newObject' => [static fn (TestbenchModx $modx): mixed => $modx->newObject(SampleItem::class)];
        yield 'runProcessor' => [
            static fn (TestbenchModx $modx): mixed => $modx->runProcessor('mgr/job/create', ['name' => 'nightly']),
        ];
    }

    /**
     * @param \Closure(TestbenchModx): mixed $operation
     */
    #[DataProvider('unsupportedOperationProvider')]
    public function testStubRefusesWhatItCannotEmulate(Closure $operation): void
    {
        $this->expectException(UnsupportedStubOperationException::class);
        $this->expectExceptionMessageMatches('/TestCase/');

        $operation($this->modx);
    }

    /**
     * `$modx->error` is a core service (`modError`), and an extra's code reads it without checks
     * (`$modx->error->hasError()`). The stub left the property equal to `null`
     * (`public $error = null;`, modX.php:210): a bare `\Error` on the very first access, while the
     * `null` itself is indistinguishable from "there were no errors". A real `modError` works
     * without a database too.
     */
    public function testErrorServiceIsUsableInsteadOfSilentlyBeingNull(): void
    {
        $error = $this->modx->error;

        self::assertInstanceOf(modError::class, $error);
        self::assertFalse($error->hasError());

        $error->addError('import failed');

        self::assertTrue($error->hasError());
    }

    /**
     * The recipe from docs/DX_GUIDE.md §6 ("Do not try to seed a REAL model object") is pinned here
     * in exactly the form in which it was MEASURED, not the form in which it was expected.
     *
     * The `xPDOObject` constructor (`core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOObject.php:618-640`)
     * reads `config['dbname']` and `config['dbtype']` before the class map, and `stubOptions()`
     * returns an empty array by default — which means the consumer will see TWO
     * `Undefined array key` notices and only then the package's exception about `getFields()`.
     * The stub has nothing to fill those keys with: it has no database connection, and an invented
     * `dbtype` would send the core off building SQL for a driver that does not exist.
     */
    public function testSeedingARealModelObjectWarnsTwiceAndThenRefuses(): void
    {
        $modx = $this->modx;
        $warnings = [];

        // A handler of our own rather than capturing the output: with `display_errors=0` (the
        // production-ini standard the CI images are built with) a warning is printed nowhere, and a
        // check made against the output would go falsely green. Returning `true` also takes the warning
        // away from PHPUnit's handler, which would otherwise redden the test through `failOnWarning`.
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $item = new SampleItem($modx);

            self::fail('The package exception was expected, but an object was built: ' . $item::class . '.');
        } catch (Throwable $exception) {
            // `\Throwable` is caught rather than the type itself: the exception comes from the innards of
            // the real core, whose bodies the static analysis does not see (the methods in `stubs/` have
            // empty bodies), and it would declare a narrow `catch` dead.
            self::assertInstanceOf(UnsupportedStubOperationException::class, $exception);
            self::assertStringContainsString('getFields()', $exception->getMessage());
        } finally {
            restore_error_handler();
        }

        self::assertSame(
            ['Undefined array key "dbname"', 'Undefined array key "dbtype"'],
            $warnings
        );
    }

    /**
     * @return array<int, object>
     */
    private function seedTwoItems(): array
    {
        $make = static fn (int $id, string $name): object => new class ($id, $name) {
            public function __construct(private readonly int $id, private readonly string $name)
            {
            }

            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return ['id' => $this->id, 'name' => $this->name];
            }
        };

        $first = $make(1, 'widget');
        $second = $make(2, 'gadget');

        $this->modx->seed(SampleItem::class, $first);
        $this->modx->seed(SampleItem::class, $second);

        return [$first, $second];
    }
}

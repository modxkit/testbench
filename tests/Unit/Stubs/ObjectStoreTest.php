<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Stubs;

use ModxKit\Testbench\Exception\UnsupportedStubOperationException;
use ModxKit\Testbench\Stubs\LogRecorder;
use ModxKit\Testbench\Stubs\ObjectStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ObjectStoreTest extends TestCase
{
    public function testFindsObjectByCriteria(): void
    {
        $store = new ObjectStore();
        $store->put('Sample', new class () {
            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return ['name' => 'first', 'quantity' => 1];
            }
        });
        $store->put('Sample', new class () {
            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return ['name' => 'second', 'quantity' => 2];
            }
        });

        $found = $store->first('Sample', ['name' => 'second']);

        self::assertNotNull($found);
        self::assertCount(2, $store->all('Sample'));
        self::assertCount(1, $store->all('Sample', ['quantity' => 1]));
        self::assertNull($store->first('Sample', ['name' => 'missing']));
    }

    public function testFindsObjectByCriteriaUsingPlainPublicProperties(): void
    {
        $store = new ObjectStore();
        $store->put('Plain', new class () {
            public string $name = 'first';

            public int $quantity = 1;
        });
        $store->put('Plain', new class () {
            public string $name = 'second';

            public int $quantity = 2;
        });

        $found = $store->first('Plain', ['name' => 'second']);

        self::assertNotNull($found);
        self::assertCount(2, $store->all('Plain'));
        self::assertCount(1, $store->all('Plain', ['quantity' => 1]));
        self::assertNull($store->first('Plain', ['name' => 'missing']));
    }

    /**
     * A double with non-public fields and accessors — a typical imitation of `xPDOObject`. The
     * earlier implementation read the fields through `get_object_vars()` from the store's own
     * scope and therefore saw only the public ones: such a double was EMPTY as far as the store
     * was concerned, without an exception or a warning, and any criterion against it simply did
     * not match.
     */
    public function testFindsObjectByNonPublicProperties(): void
    {
        $store = new ObjectStore();
        $store->put('Encapsulated', new class (1, 'first') {
            public function __construct(private readonly int $id, private readonly string $name)
            {
            }

            public function id(): int
            {
                return $this->id;
            }

            public function name(): string
            {
                return $this->name;
            }
        });
        $store->put('Encapsulated', new class (2, 'second') {
            public function __construct(private readonly int $id, private readonly string $name)
            {
            }

            public function id(): int
            {
                return $this->id;
            }

            public function name(): string
            {
                return $this->name;
            }
        });

        self::assertCount(1, $store->all('Encapsulated', ['name' => 'second']));
        self::assertNotNull($store->first('Encapsulated', ['id' => 1]));
        self::assertNull($store->first('Encapsulated', ['id' => 3]));
    }

    /**
     * Non-public fields of the parent are visible just like the object's own: otherwise a double
     * inherited from a shared test base would again be half empty as far as the store is
     * concerned.
     */
    public function testFindsObjectByInheritedNonPublicProperties(): void
    {
        $store = new ObjectStore();
        $store->put('Inherited', new class () extends ObjectStoreTestParentDouble {
            protected string $name = 'child';
        });

        self::assertCount(1, $store->all('Inherited', ['id' => 7, 'name' => 'child']));
        self::assertCount(0, $store->all('Inherited', ['id' => 8]));
    }

    /**
     * `toArray()` of a real model object with no model loaded returns `null`. `extract()` declared
     * `array` as its return type, so the test got a `TypeError` from the package's internals
     * instead of the package's own exception with an explanation (NFR-3).
     */
    public function testRefusesDoubleWhoseToArrayIsNotAnArray(): void
    {
        $store = new ObjectStore();
        $store->put('Broken', new class () {
            public function toArray(): mixed
            {
                return null;
            }
        });

        $this->expectException(UnsupportedStubOperationException::class);
        $this->expectExceptionMessageMatches('/toArray/');

        $store->all('Broken', ['name' => 'anything']);
    }

    /**
     * A loose comparison breaks on exactly `null`: `null == 0`, `null == ''` and `null == false`
     * are all true in PHP, so the criterion `['field' => null]` ("the field is not filled in")
     * matched a stored zero. A strict `===` would make things worse: the values come from the
     * database as strings, and `['id' => 1]` would stop finding `'1'`.
     */
    public function testNullCriterionMatchesOnlyNull(): void
    {
        $store = new ObjectStore();
        $store->put('Nullable', new class () {
            public int $quantity = 0;

            public ?string $comment = null;
        });

        self::assertCount(0, $store->all('Nullable', ['quantity' => null]));
        self::assertCount(1, $store->all('Nullable', ['comment' => null]));
        self::assertCount(0, $store->all('Nullable', ['comment' => 0]));
        self::assertCount(1, $store->all('Nullable', ['quantity' => '0']));
    }

    public function testLogRecorderCapturesCalls(): void
    {
        $recorder = new LogRecorder();
        $recorder->log(1, 'broken');
        $recorder->event('OnDocFormSave', ['id' => 5]);
        $recorder->lexicon('sampleextra.title');

        self::assertSame([['level' => 1, 'message' => 'broken']], $recorder->logs());
        self::assertSame('OnDocFormSave', $recorder->events()[0]['name']);
        self::assertSame(['sampleextra.title'], $recorder->lexiconKeys());
    }
}

/**
 * The parent for the double from `testFindsObjectByInheritedNonPublicProperties()`: an anonymous
 * class cannot declare a `private` field that another anonymous class would inherit.
 */
abstract class ObjectStoreTestParentDouble
{
    private int $id = 7;

    public function id(): int
    {
        return $this->id;
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Stubs;

use ModxKit\Testbench\Exception\UnsupportedStubOperationException;
use ReflectionClass;
use ReflectionObject;

/**
 * @internal
 */
final class ObjectStore
{
    /** @var array<string, array<int, object>> */
    private array $objects = [];

    public function put(string $class, object $object): void
    {
        $this->objects[$class][] = $object;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function first(string $class, array $criteria): ?object
    {
        return $this->all($class, $criteria)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<int, object>
     */
    public function all(string $class, array $criteria = []): array
    {
        $candidates = $this->objects[$class] ?? [];

        if ($criteria === []) {
            return array_values($candidates);
        }

        return array_values(array_filter(
            $candidates,
            fn (object $object): bool => $this->matches($object, $criteria)
        ));
    }

    public function clear(): void
    {
        $this->objects = [];
    }

    /**
     * The comparison is NON-strict: field values arrive from the database as strings, and
     * `['id' => 1]` must find a stored `'1'`. The only exception is `null`: in PHP `null == 0`,
     * `null == ''` and `null == false` are all true, so a criterion of "the field is not filled in"
     * would match a stored zero, and `['flag' => 0]` would match an unfilled field. `null` is
     * compared strictly and matches only `null`.
     *
     * @param array<string, mixed> $criteria
     */
    private function matches(object $object, array $criteria): bool
    {
        $data = $this->extract($object);

        foreach ($criteria as $field => $value) {
            if (!array_key_exists($field, $data)) {
                return false;
            }

            $stored = $data[$field];

            if ($value === null || $stored === null) {
                if ($value !== $stored) {
                    return false;
                }

                continue;
            }

            if ($stored != $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(object $object): array
    {
        if (method_exists($object, 'toArray')) {
            $data = $object->toArray();

            // Without a loaded model, `toArray()` of a real model object returns `null`
            // (`xPDOObject::toArray()` works from the field map). The `array` declared here turned
            // that into a `TypeError` from the package's internals — an exception of a non-package
            // type, outside the contract "every exception carries a cause and a next action".
            if (!is_array($data)) {
                throw UnsupportedStubOperationException::forSeededObject(
                    $object::class,
                    get_debug_type($data)
                );
            }

            /** @var array<array-key, mixed> $data */
            return array_combine(array_map(strval(...), array_keys($data)), $data);
        }

        return $this->properties($object);
    }

    /**
     * The fields of an object WITHOUT `toArray()` — all of them, not only the public ones.
     *
     * `get_object_vars()` called from here sees only public properties: a double with private
     * fields and accessors (the typical imitation of an `xPDOObject`) was empty as far as the store
     * was concerned — with no exception and no warning, simply no criterion ever matched. The walk
     * goes from the object itself up through its parents, because `ReflectionClass::getProperties()`
     * returns the private properties of its own class only; the one nearest the object wins, just as
     * with an ordinary property read.
     *
     * @return array<string, mixed>
     */
    private function properties(object $object): array
    {
        $data = [];

        for (
            $class = new ReflectionObject($object);
            $class instanceof ReflectionClass;
            $class = $class->getParentClass() ?: null
        ) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || array_key_exists($property->getName(), $data)) {
                    continue;
                }

                // A typed property without a value must not be read — accessing it would give an
                // `Error`, and for the criteria it is as good as absent.
                if (!$property->isInitialized($object)) {
                    continue;
                }

                $data[$property->getName()] = $property->getValue($object);
            }
        }

        return $data;
    }
}

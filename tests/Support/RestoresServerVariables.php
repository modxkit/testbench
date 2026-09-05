<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Support;

/**
 * A snapshot and exact restoration of a set of `$_SERVER` entries.
 *
 * "Exact" here means one thing only: a missing key is restored as missing, not as a sentinel
 * value. A sentinel inside the array itself inevitably coincides with some legitimate value of an
 * entry (`false`, `null`), so absence is recorded structurally — by the set of keys that made it
 * into the snapshot.
 *
 * The trait deliberately does NOT supply `setUp()`/`tearDown()`: its consumers take the snapshot
 * at different moments — {@see OwnsTestbenchEnvironment} takes it for the whole test, while
 * {@see \ModxKit\Testbench\Tests\Integration\Console\CommandsTest} takes it in the middle of a
 * test, onto a queue of deferred restorations. A lifecycle method in the trait would tie both to
 * one moment and would silently lose to a method of the consuming class itself
 * ({@see \ModxKit\Testbench\Tests\Unit\TraitMethodOverrideTest}).
 */
trait RestoresServerVariables
{
    /**
     * @param list<string> $keys
     *
     * @return callable(): void a restoration idempotent over the set of keys
     */
    protected function serverVariableRestorer(array $keys): callable
    {
        $saved = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $saved[$key] = $_SERVER[$key];
            }
        }

        return static function () use ($keys, $saved): void {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $saved)) {
                    unset($_SERVER[$key]);

                    continue;
                }

                $_SERVER[$key] = $saved[$key];
            }
        };
    }
}

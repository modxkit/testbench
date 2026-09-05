<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Isolation;

use MODX\Revolution\modX;

/**
 * A way of returning the database to its original state between tests.
 *
 * `begin()` is called in `setUp()` before the body of the test, `end()` in `tearDown()` after it.
 */
interface IsolationStrategy
{
    public function begin(modX $modx): void;

    public function end(modX $modx): void;
}

<?php

declare(strict_types=1);

/**
 * Asks for the environment in a SEPARATE process and reports what it got.
 *
 * Two runs of one environment cannot be staged inside one PHP: the guard tells a child of the
 * holder from a stranger by a token inherited through the process environment, and inheritance is
 * exactly what a single process cannot have. See
 * {@see \ModxKit\Testbench\Tests\Integration\Environment\ConcurrentRunTest}.
 *
 * The verdict is printed as one word so the caller asserts on an invariant rather than on the
 * wording of a message that is free to change.
 */

use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Exception\ConcurrentRunException;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    TestbenchKernel::instance()->prepare();

    echo "prepared\n";
} catch (ConcurrentRunException) {
    echo "refused\n";
}

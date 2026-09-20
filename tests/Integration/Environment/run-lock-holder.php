<?php

declare(strict_types=1);

/**
 * Takes the lock on the environment named by `MODX_TESTBENCH_DB_NAME`, says so, and stays alive
 * until it is killed.
 *
 * It exists for the one claim about this guard that cannot be checked without a corpse: the lock is
 * held by an open descriptor, so it is the operating system that releases it, and `kill -9` — which
 * runs no shutdown code whatsoever — must free the environment all the same.
 */

use ModxKit\Testbench\Environment\RunLock;
use ModxKit\Testbench\Environment\TestbenchConfig;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$lock = RunLock::acquire(TestbenchConfig::fromEnvironment());

if (!$lock instanceof RunLock) {
    echo "not-held\n";

    exit(1);
}

echo "held\n";

// Long enough for the caller to do its checking, short enough not to outlive a suite that died
// before it could kill this process.
sleep(120);

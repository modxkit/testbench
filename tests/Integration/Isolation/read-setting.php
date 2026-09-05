<?php

declare(strict_types=1);

/**
 * Loads the core of the test environment in a FRESH PHP process and prints the value of a setting.
 * Used by {@see \ModxKit\Testbench\Tests\Integration\Isolation\CoreCacheLeakScenario}: only a
 * separate process reads the file cache in `core/cache/` rather than the `$modx->config` left in
 * memory.
 *
 * The core is brought up directly through `KernelBootstrapper`, bypassing
 * `TestbenchKernel::prepare()`: preparing the environment is neither needed here nor allowed to fix
 * anything — the question is exactly what is in the cache right now.
 */

use ModxKit\Testbench\Bootstrap\KernelBootstrapper;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\Workspace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$key = $argv[1] ?? '';

$modx = (new KernelBootstrapper())->boot(Workspace::forConfig(TestbenchConfig::fromEnvironment()));

$value = $modx->getOption($key);

echo is_scalar($value) ? (string) $value : '', "\n";

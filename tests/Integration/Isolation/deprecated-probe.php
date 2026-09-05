<?php

declare(strict_types=1);

/**
 * Calls `modX::deprecated()` in a separate process and lets it exit normally.
 *
 * The core writes its deprecated-API marks from `register_shutdown_function()`, that is, AFTER the
 * last `tearDown()` — past any isolation. That no rows appear can only be checked in a process that
 * reached its own shutdown; see
 * {@see \ModxKit\Testbench\Tests\Integration\Isolation\DeprecationLogTest}.
 *
 * The call is wrapped in two functions deliberately: `modX::deprecated()` takes both the deprecated
 * method itself (frame 1) and its caller (frame 2) from `debug_backtrace()`, and from the global
 * scope there are not enough frames and the core fails with a `TypeError`.
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

function testbench_deprecated_probe_inner(MODX\Revolution\modX $modx): void
{
    $modx->deprecated('3.0.0', 'called for the sake of the isolation check');
}

function testbench_deprecated_probe_outer(MODX\Revolution\modX $modx): void
{
    testbench_deprecated_probe_inner($modx);
}

$modx = ModxKit\Testbench\Environment\TestbenchKernel::instance()->modx();

testbench_deprecated_probe_outer($modx);

// The INVARIANT is printed rather than the value: '' and '0' both mean "the log is off", and
// a comparison with a literal would go red on a disabled log saying "it is not disabled".
echo $modx->getOption('log_deprecated') ? "on\n" : "off\n";

<?php

declare(strict_types=1);

use ModxKit\Testbench\Environment\TestbenchKernel;

foreach ([__DIR__ . '/../../../../vendor/autoload.php', __DIR__ . '/../../../../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

return TestbenchKernel::instance()->modx();

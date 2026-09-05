<?php

declare(strict_types=1);

namespace SampleExtra\Service;

use MODX\Revolution\modX;

final class SampleService
{
    public function __construct(private readonly modX $modx)
    {
    }

    public function limit(): int
    {
        $value = $this->modx->getOption('sampleextra_limit', null, 5);

        return is_numeric($value) ? (int) $value : 5;
    }
}

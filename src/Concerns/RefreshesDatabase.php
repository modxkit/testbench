<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Concerns;

use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Isolation\IsolationStrategy;
use ModxKit\Testbench\Isolation\SnapshotIsolation;

/**
 * Restores the database from the baseline snapshot after every test.
 * Needed where a transaction is no rescue: DDL, installing a transport package, MyISAM tables.
 */
trait RefreshesDatabase
{
    protected function isolationStrategy(): IsolationStrategy
    {
        return new SnapshotIsolation(TestbenchKernel::instance()->snapshots());
    }
}

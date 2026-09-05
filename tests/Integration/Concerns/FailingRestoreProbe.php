<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Concerns;

use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Isolation\IsolationStrategy;
use ModxKit\Testbench\TestCase;

/**
 * A stand-in for the base `TestCase` whose restoration of the core state always fails.
 *
 * It is needed to check that `tearDown()` closes the isolation even when the restoration fails —
 * otherwise an unclosed transaction would be handed to the next test, and its `begin()` would fail
 * with "There is already an active transaction", hiding the real cause.
 */
final class FailingRestoreProbe extends TestCase
{
    private IsolationStrategy $strategy;

    public static function withStrategy(IsolationStrategy $strategy): self
    {
        $probe = new self('failing-restore-probe');
        $probe->strategy = $strategy;

        return $probe;
    }

    public function callSetUp(): void
    {
        $this->setUp();
    }

    public function callTearDown(): void
    {
        $this->tearDown();
    }

    protected function isolationStrategy(): IsolationStrategy
    {
        return $this->strategy;
    }

    protected function restoreModxRuntimeState(): void
    {
        throw new TestbenchException('The restoration of the core state failed deliberately.');
    }
}

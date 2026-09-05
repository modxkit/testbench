<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use MODX\Revolution\modX;
use ModxKit\Testbench\Isolation\IsolationStrategy;

/**
 * A wrapper strategy for the tests: it records the order of the calls and delegates to the real
 * isolation, so substituting the strategy does not cancel the rollback of the changes.
 */
final class RecordingIsolation implements IsolationStrategy
{
    /** @var list<string> */
    private array $calls = [];

    public function __construct(private readonly IsolationStrategy $inner)
    {
    }

    public function begin(modX $modx): void
    {
        $this->calls[] = 'begin';

        $this->inner->begin($modx);
    }

    public function end(modX $modx): void
    {
        $this->calls[] = 'end';

        $this->inner->end($modx);
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}

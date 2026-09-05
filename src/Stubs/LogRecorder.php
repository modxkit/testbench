<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Stubs;

final class LogRecorder
{
    /** @var array<int, array{level: int, message: string}> */
    private array $logs = [];

    /** @var array<int, array{name: string, params: array<string, mixed>}> */
    private array $events = [];

    /** @var array<int, string> */
    private array $lexiconKeys = [];

    public function log(int $level, string $message): void
    {
        $this->logs[] = ['level' => $level, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function event(string $name, array $params): void
    {
        $this->events[] = ['name' => $name, 'params' => $params];
    }

    public function lexicon(string $key): void
    {
        $this->lexiconKeys[] = $key;
    }

    /**
     * @return array<int, array{level: int, message: string}>
     */
    public function logs(): array
    {
        return $this->logs;
    }

    /**
     * @return array<int, array{name: string, params: array<string, mixed>}>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return array<int, string>
     */
    public function lexiconKeys(): array
    {
        return $this->lexiconKeys;
    }
}

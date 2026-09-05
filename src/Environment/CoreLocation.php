<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

final readonly class CoreLocation
{
    public function __construct(
        public string $rootPath,
        public string $version,
    ) {
    }

    public function corePath(): string
    {
        return $this->rootPath . 'core/';
    }

    public function setupPath(): string
    {
        return $this->rootPath . 'setup/';
    }

    public function managerPath(): string
    {
        return $this->rootPath . 'manager/';
    }

    public function connectorsPath(): string
    {
        return $this->rootPath . 'connectors/';
    }

    public function indexFile(): string
    {
        return $this->rootPath . 'index.php';
    }

    public function packagesPath(): string
    {
        return $this->corePath() . 'packages/';
    }
}

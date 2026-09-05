<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment\Provider;

use ModxKit\Testbench\Environment\CoreLocation;

interface CoreProvider
{
    /**
     * The fingerprint takes part in the environment hash: changing the version or the branch gives
     * a new workspace.
     */
    public function fingerprint(): string;

    public function provide(string $targetDir): CoreLocation;
}

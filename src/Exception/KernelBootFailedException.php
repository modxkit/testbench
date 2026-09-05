<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

final class KernelBootFailedException extends TestbenchException
{
    public static function because(string $reason, string $workspacePath): self
    {
        return new self(sprintf(
            "Failed to boot the MODX core: %s\nWorkspace: %s\n"
            . 'Try recreating the environment: MODX_TESTBENCH_FORCE_INSTALL=1 or `bin/modx-testbench destroy`.',
            $reason,
            $workspacePath
        ));
    }
}

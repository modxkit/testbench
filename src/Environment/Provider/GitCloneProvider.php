<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment\Provider;

use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\ProcessRunner;

/**
 * @internal
 */
final readonly class GitCloneProvider implements CoreProvider
{
    private const REPOSITORY = 'https://github.com/modxcms/revolution.git';

    public function __construct(
        private string $ref,
        private ProcessRunner $runner = new ProcessRunner(),
        private ?string $repository = null,
    ) {
    }

    public function fingerprint(): string
    {
        return 'git:' . $this->ref;
    }

    public function provide(string $targetDir): CoreLocation
    {
        $target = rtrim($targetDir, '/');

        $clone = $this->runner->run([
            'git', 'clone', '--depth=1', '--branch', $this->ref, $this->repository ?? self::REPOSITORY, $target,
        ], null, 900);

        if (!$clone->isSuccessful()) {
            throw new TestbenchException(
                "Failed to clone MODX (branch {$this->ref}).\n" . $clone->output()
                . "\nCheck the value of MODX_TESTBENCH_GIT_REF and access to github.com."
            );
        }

        $composer = $this->runner->run(
            ['composer', 'install', '--no-dev', '--no-interaction', '--no-progress'],
            $target . '/core',
            1800
        );

        if (!$composer->isSuccessful()) {
            throw new TestbenchException(
                "Failed to install core dependencies into {$target}/core.\n" . $composer->output()
            );
        }

        return new CoreLocation($target . '/', $this->ref);
    }
}

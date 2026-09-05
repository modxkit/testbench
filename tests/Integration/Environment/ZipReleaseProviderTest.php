<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Environment;

use ModxKit\Testbench\Environment\Provider\ZipReleaseProvider;
use ModxKit\Testbench\Environment\TestbenchConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ZipReleaseProviderTest extends TestCase
{
    private ?string $target = null;

    private ?string $previousCacheDir = null;

    protected function setUp(): void
    {
        // Point the release cache at a dedicated temp directory instead of the real
        // ~/.cache/modx-testbench so this test never touches the user's real environment.
        // The directory is intentionally stable (not randomised) so repeated local runs
        // still benefit from the download cache, matching the provider's own behaviour.
        $previousCacheDir = $_SERVER['MODX_TESTBENCH_CACHE_DIR'] ?? null;
        $this->previousCacheDir = is_string($previousCacheDir) ? $previousCacheDir : null;
        $_SERVER['MODX_TESTBENCH_CACHE_DIR'] = sys_get_temp_dir() . '/modx-testbench-test-cache';
    }

    protected function tearDown(): void
    {
        if ($this->target !== null) {
            exec('rm -rf ' . escapeshellarg($this->target));
        }

        if ($this->previousCacheDir === null) {
            unset($_SERVER['MODX_TESTBENCH_CACHE_DIR']);
        } else {
            $_SERVER['MODX_TESTBENCH_CACHE_DIR'] = $this->previousCacheDir;
        }
    }

    public function testProvidesUsableDistribution(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $this->target = sys_get_temp_dir() . '/modx-testbench-zip-' . bin2hex(random_bytes(4));

        $location = (new ZipReleaseProvider($config->version, $config->cacheDir))->provide($this->target);

        self::assertFileExists($location->indexFile());
        self::assertDirectoryExists($location->setupPath());
        self::assertFileExists($location->packagesPath() . 'core.transport.zip');
    }
}

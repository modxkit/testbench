<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Environment\Provider;

use ModxKit\Testbench\Environment\Provider\GitCloneProvider;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\ProcessRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class GitCloneProviderTest extends TestCase
{
    private string $repository;
    private string $target;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->repository = sys_get_temp_dir() . '/modx-repo-' . $suffix;
        $this->target = sys_get_temp_dir() . '/modx-git-target-' . $suffix;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repository) . ' ' . escapeshellarg($this->target));
    }

    public function testFingerprintIncludesRef(): void
    {
        self::assertSame('git:3.2.x', (new GitCloneProvider('3.2.x', new ProcessRunner()))->fingerprint());
    }

    public function testClonesLocalRepositoryAtGivenRefAndInstallsCoreDependencies(): void
    {
        $this->initRepository('testref', ['core/composer.json' => '{}']);

        $provider = new GitCloneProvider('testref', new ProcessRunner(), $this->repository);
        $location = $provider->provide($this->target);

        self::assertFileExists($location->indexFile());
        self::assertSame('testref', $location->version);
    }

    public function testThrowsWithStderrWhenRefDoesNotExist(): void
    {
        $this->initRepository('main');

        $provider = new GitCloneProvider('does-not-exist', new ProcessRunner(), $this->repository);

        try {
            $provider->provide($this->target);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString('does-not-exist', $exception->getMessage());
            self::assertStringContainsString('not found', $exception->getMessage());
        }
    }

    /**
     * @param array<string, string> $extraFiles relative path => content
     */
    private function initRepository(string $ref, array $extraFiles = []): void
    {
        mkdir($this->repository, 0o775, true);

        foreach (['index.php' => '<?php // modx', 'setup/index.php' => '<?php'] + $extraFiles as $relativePath => $content) {
            $path = $this->repository . '/' . $relativePath;
            @mkdir(dirname($path), 0o775, true);
            file_put_contents($path, $content);
        }

        $this->runGit(['git', 'init', '-q']);
        $this->runGit(['git', 'checkout', '-q', '-b', $ref]);
        $this->runGit(['git', 'config', 'user.email', 'test@example.com']);
        $this->runGit(['git', 'config', 'user.name', 'Test']);
        $this->runGit(['git', 'add', '-A']);
        $this->runGit(['git', 'commit', '-q', '-m', 'init']);
    }

    /**
     * @param array<int, string> $command
     */
    private function runGit(array $command): void
    {
        $result = (new ProcessRunner())->run($command, $this->repository);

        if (!$result->isSuccessful()) {
            self::fail('Failed to prepare test git fixture: ' . $result->output());
        }
    }
}

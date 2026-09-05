<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Environment\Provider;

use FilesystemIterator;
use ModxKit\Testbench\Environment\Provider\LocalPathProvider;
use ModxKit\Testbench\Exception\TestbenchException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[Group('unit')]
final class LocalPathProviderTest extends TestCase
{
    private string $source;
    private string $target;

    /** @var array<int, string> */
    private array $extraDirs = [];

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->source = sys_get_temp_dir() . '/modx-src-' . $suffix;
        $this->target = sys_get_temp_dir() . '/modx-dst-' . $suffix;

        mkdir($this->source . '/core/config', 0o775, true);
        mkdir($this->source . '/core/docs', 0o775, true);
        mkdir($this->source . '/setup', 0o775, true);
        file_put_contents($this->source . '/index.php', '<?php // modx');
        file_put_contents($this->source . '/core/docs/version.inc.php', '');
    }

    protected function tearDown(): void
    {
        $paths = array_map(escapeshellarg(...), [$this->source, $this->target, ...$this->extraDirs]);

        exec('rm -rf ' . implode(' ', $paths));
    }

    public function testCopiesDistributionWithoutTouchingSource(): void
    {
        $sourceFilesBefore = $this->listFilesRecursively($this->source);

        $location = (new LocalPathProvider($this->source))->provide($this->target);

        self::assertFileExists($location->indexFile());
        self::assertDirectoryExists($location->setupPath());
        self::assertFileExists($this->source . '/index.php');
        self::assertSame($sourceFilesBefore, $this->listFilesRecursively($this->source));
    }

    public function testRejectsPathThatIsNotAModxDistribution(): void
    {
        $empty = sys_get_temp_dir() . '/modx-empty-' . bin2hex(random_bytes(4));
        mkdir($empty, 0o775, true);
        $this->extraDirs[] = $empty;

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('does not look like a MODX 3 distribution');

        (new LocalPathProvider($empty))->provide($this->target);
    }

    /**
     * A missing directory and a directory that is not a distribution are different errors with
     * different actions: in the first case the path has a typo or has not been created yet, in the
     * second it points at the wrong directory. The earlier shared text, "does not look like a MODX
     * 3 distribution: no index.php or setup/", sent someone chasing files that do not exist when
     * `MODX_TESTBENCH_LOCAL_CORE` had a typo in it.
     */
    public function testRejectsSourceDirectoryThatDoesNotExist(): void
    {
        $missing = sys_get_temp_dir() . '/modx-missing-' . bin2hex(random_bytes(4));

        try {
            (new LocalPathProvider($missing))->provide($this->target);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString($missing, $exception->getMessage());
            self::assertStringContainsString('does not exist', $exception->getMessage());
            self::assertStringNotContainsString('does not look like a MODX 3 distribution', $exception->getMessage());
        }
    }

    public function testThrowsNamingThePathWhenADirectoryCannotBeCreatedInTheTarget(): void
    {
        mkdir($this->target, 0o775, true);
        // Pre-create a *file* where the provider needs to create the "core" directory.
        file_put_contents($this->target . '/core', 'not a directory');

        try {
            (new LocalPathProvider($this->source))->provide($this->target);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString($this->target . '/core', $exception->getMessage());
        }
    }

    public function testThrowsNamingThePathWhenAFileCannotBeCopiedIntoTheTarget(): void
    {
        mkdir($this->target, 0o775, true);
        // Pre-create a *directory* where the provider needs to copy the "index.php" file.
        mkdir($this->target . '/index.php', 0o775, true);

        try {
            (new LocalPathProvider($this->source))->provide($this->target);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString($this->source . '/index.php', $exception->getMessage());
        }
    }

    public function testRejectsSymlinkedDirectoryInsteadOfSilentlyCopyingItEmpty(): void
    {
        mkdir($this->source . '/vendor-real/pkg', 0o775, true);
        file_put_contents($this->source . '/vendor-real/pkg/file.txt', 'payload');
        symlink($this->source . '/vendor-real', $this->source . '/vendor-link');

        try {
            (new LocalPathProvider($this->source))->provide($this->target);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString($this->source . '/vendor-link', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->target . '/vendor-link');
    }

    /**
     * This test and the one above share ONE line of production code: the mutation
     * `isLink()` → `false` reddens exactly the two of them and nobody else (measured). They are
     * nevertheless not to be merged: the property under test above is "the contents are not lost
     * silently" (the assertion about the absence of an empty `vendor-link` at the target), and
     * here it is "the traversal finishes at all"; in a single source only the first symlink
     * encountered would fire, and there would be nothing left to prove the second property with.
     */
    public function testRejectsCircularSymlinkInsteadOfHangingOrCopyingItEmpty(): void
    {
        symlink($this->source, $this->source . '/self-link');

        try {
            (new LocalPathProvider($this->source))->provide($this->target);
            self::fail('Expected TestbenchException was not thrown.');
        } catch (TestbenchException $exception) {
            self::assertStringContainsString($this->source . '/self-link', $exception->getMessage());
        }
    }

    /**
     * @return array<int, string>
     */
    private function listFilesRecursively(string $directory): array
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $paths = [];

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $paths[] = substr($item->getPathname(), strlen($directory));
        }

        sort($paths);

        return $paths;
    }
}

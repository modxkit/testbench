<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment\Provider;

use FilesystemIterator;
use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Exception\TestbenchException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @internal
 */
final readonly class LocalPathProvider implements CoreProvider
{
    public function __construct(private string $sourcePath)
    {
    }

    public function fingerprint(): string
    {
        return 'local:' . rtrim($this->sourcePath, '/');
    }

    public function provide(string $targetDir): CoreLocation
    {
        $source = rtrim($this->sourcePath, '/');

        // A non-existent directory and a directory that is not a distribution are different errors
        // with different actions: in the first case the path has a typo or has not been created yet,
        // in the second it points at the wrong directory. A shared "no index.php or setup/" text
        // sent people looking for files on a typo where the directory itself does not exist.
        if (!is_dir($source)) {
            throw new TestbenchException(
                "Directory {$source} does not exist. MODX_TESTBENCH_CORE_PATH must point at the "
                . 'root of an already unpacked MODX 3 distribution — check the path (a typo, a '
                . 'relative path, a directory not created yet).'
            );
        }

        if (!is_file($source . '/index.php') || !is_dir($source . '/setup')) {
            throw new TestbenchException(
                "Directory {$source} does not look like a MODX 3 distribution: there is no "
                . 'index.php or setup/ in it. Point MODX_TESTBENCH_CORE_PATH at the root of a '
                . 'MODX installation.'
            );
        }

        $this->copyTree($source, rtrim($targetDir, '/'));

        return new CoreLocation(rtrim($targetDir, '/') . '/', $this->detectVersion($targetDir));
    }

    private function copyTree(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
            throw new TestbenchException("Failed to create the environment directory: {$target}");
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $destination = $target . '/' . substr($item->getPathname(), strlen($source) + 1);

            if ($item->isDir()) {
                if ($item->isLink()) {
                    // RecursiveIteratorIterator does not descend into symlinks to directories
                    // (including cyclic ones — by default hasChildren() does not count them as
                    // children), so such a node would be copied as an empty directory, silently
                    // losing the source's contents. We refuse explicitly.
                    throw new TestbenchException(
                        "The source directory {$this->sourcePath} contains a symbolic link to a "
                        . "directory ({$item->getPathname()}) that would be copied as an empty "
                        . 'directory. Replace the link with a real directory or remove it from the source.'
                    );
                }

                if (!is_dir($destination) && !@mkdir($destination, 0o775, true)) {
                    throw new TestbenchException("Failed to create directory: {$destination}");
                }

                continue;
            }

            if (!@copy($item->getPathname(), $destination)) {
                throw new TestbenchException(
                    "Failed to copy file {$item->getPathname()} to {$destination}"
                );
            }
        }
    }

    private function detectVersion(string $targetDir): string
    {
        $file = rtrim($targetDir, '/') . '/core/docs/version.inc.php';

        if (!is_file($file)) {
            return 'unknown';
        }

        $version = include $file;

        if (!is_array($version) || !isset($version['full_version']) || !is_string($version['full_version'])) {
            return 'unknown';
        }

        return $version['full_version'];
    }
}

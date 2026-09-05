<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Installer;

use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Exception\CoreTransportUnpackException;
use ModxKit\Testbench\Installer\CorePreparer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[Group('unit')]
final class CorePreparerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/modx-prep-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->root . 'core/packages', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(rtrim($this->root, '/')));
    }

    public function testUnpacksCoreTransportArchive(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->root . 'core/packages/core.transport.zip', ZipArchive::CREATE);
        $zip->addFromString('core/manifest.php', '<?php return [];');
        $zip->close();

        $unpacked = (new CorePreparer())->unpackCoreTransport(new CoreLocation($this->root, '3.2.3-pl'));

        self::assertTrue($unpacked);
        self::assertFileExists($this->root . 'core/packages/core/manifest.php');
    }

    public function testReturnsFalseWhenArchiveIsMissing(): void
    {
        $unpacked = (new CorePreparer())->unpackCoreTransport(new CoreLocation($this->root, '3.2.3-pl'));

        self::assertFalse($unpacked);
    }

    public function testReturnsFalseWhenArchiveIsCorrupt(): void
    {
        file_put_contents($this->root . 'core/packages/core.transport.zip', 'this is not a zip archive');

        $unpacked = (new CorePreparer())->unpackCoreTransport(new CoreLocation($this->root, '3.2.3-pl'));

        self::assertFalse($unpacked);
        self::assertDirectoryDoesNotExist($this->root . 'core/packages/core');
    }

    public function testRejectsArchiveWithPathTraversalEntry(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->root . 'core/packages/core.transport.zip', ZipArchive::CREATE);
        $zip->addFromString('../evil.txt', 'pwned');
        $zip->close();

        try {
            (new CorePreparer())->unpackCoreTransport(new CoreLocation($this->root, '3.2.3-pl'));
            self::fail('Expected CoreTransportUnpackException was not thrown.');
        } catch (CoreTransportUnpackException $exception) {
            self::assertStringContainsString('evil.txt', $exception->getMessage());
        }

        self::assertFileDoesNotExist(dirname(rtrim($this->root, '/')) . '/evil.txt');
        self::assertFileDoesNotExist($this->root . 'evil.txt');
    }
}

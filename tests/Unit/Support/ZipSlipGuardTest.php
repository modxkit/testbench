<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Support;

use ModxKit\Testbench\Support\ZipSlipGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[Group('unit')]
final class ZipSlipGuardTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/modx-zipslip-' . bin2hex(random_bytes(4));

        mkdir($this->tempDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    public function testReturnsNullForSafeArchive(): void
    {
        $zip = $this->openFixture(['core/manifest.php' => '<?php return [];']);

        self::assertNull(ZipSlipGuard::findEscapingEntry($zip));

        $zip->close();
    }

    public function testDetectsDotDotTraversalEntry(): void
    {
        $zip = $this->openFixture(['../evil.txt' => 'pwned']);

        self::assertSame('../evil.txt', ZipSlipGuard::findEscapingEntry($zip));

        $zip->close();
    }

    public function testDetectsAbsolutePathEntry(): void
    {
        $zip = $this->openFixture(['/etc/passwd' => 'pwned']);

        self::assertSame('/etc/passwd', ZipSlipGuard::findEscapingEntry($zip));

        $zip->close();
    }

    public function testDetectsBackslashDriveLetterEntry(): void
    {
        $zip = $this->openFixture(['C:\\Windows\\evil.txt' => 'pwned']);

        self::assertSame('C:\\Windows\\evil.txt', ZipSlipGuard::findEscapingEntry($zip));

        $zip->close();
    }

    /**
     * @param array<string, string> $entries
     */
    private function openFixture(array $entries): ZipArchive
    {
        $path = $this->tempDir . '/fixture-' . bin2hex(random_bytes(4)) . '.zip';

        $writer = new ZipArchive();

        if ($writer->open($path, ZipArchive::CREATE) !== true) {
            self::fail('Unable to create fixture archive at ' . $path);
        }

        foreach ($entries as $name => $content) {
            $writer->addFromString($name, $content);
        }

        $writer->close();

        $reader = new ZipArchive();

        if ($reader->open($path) !== true) {
            self::fail('Unable to open fixture archive at ' . $path);
        }

        return $reader;
    }
}

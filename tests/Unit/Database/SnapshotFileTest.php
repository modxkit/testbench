<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Database;

use ModxKit\Testbench\Database\SnapshotFile;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The only sign that a snapshot is complete is the marker at the tail of the file. All three
 * guards check for it (`SnapshotManager::exists()`, `TestbenchKernel`, both strategies), so the
 * check itself lives in one place and is covered separately.
 */
#[Group('unit')]
final class SnapshotFileTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/tb-snapshot-file-' . bin2hex(random_bytes(4)) . '.sql';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testMissingFileIsNotComplete(): void
    {
        self::assertFalse(SnapshotFile::isComplete($this->file));
    }

    public function testEmptyFileIsNotComplete(): void
    {
        touch($this->file);

        self::assertFalse(SnapshotFile::isComplete($this->file));
    }

    /**
     * A break at a statement boundary — roughly half of the possible break positions — leaves a
     * file that the earlier guard (`filesize() > 0`) considered sound.
     */
    public function testFileTruncatedAtStatementBoundaryIsNotComplete(): void
    {
        file_put_contents(
            $this->file,
            "SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS `modx_probe`;\nCREATE TABLE `modx_probe` (id INT);\n"
        );

        self::assertFalse(SnapshotFile::isComplete($this->file));
    }

    public function testFileWithTheCompletionMarkerIsComplete(): void
    {
        file_put_contents(
            $this->file,
            "SET FOREIGN_KEY_CHECKS=0;\nSET FOREIGN_KEY_CHECKS=1;\n" . SnapshotFile::completionLine(70)
        );

        self::assertTrue(SnapshotFile::isComplete($this->file));
    }

    /**
     * The marker only counts at the tail: a file broken off AFTER it with junk appended is not a
     * snapshot — otherwise `str_contains()` would have been enough.
     */
    public function testMarkerInTheMiddleDoesNotCountAsComplete(): void
    {
        file_put_contents(
            $this->file,
            SnapshotFile::completionLine(70) . "INSERT INTO `modx_probe` VALUES (1);\n"
        );

        self::assertFalse(SnapshotFile::isComplete($this->file));
    }

    public function testCompletionLineIsAnSqlCommentSoRestoreNeverExecutesIt(): void
    {
        self::assertStringStartsWith('-- ', SnapshotFile::completionLine(70));
        self::assertStringEndsWith("\n", SnapshotFile::completionLine(70));
        self::assertStringContainsString('tables=70', SnapshotFile::completionLine(70));
    }

    /**
     * The defect reproduced without a tie to the PHP version: `isComplete()` must answer about the
     * BYTES of the file, not about what `stat` says of it.
     *
     * On PHP 8.2 this divergent pair is produced by the `stat` cache (after `touch()` and an
     * append, `filesize()` goes on answering zero — measured on 8.2.33 against 8.3.33 and 8.4.8),
     * and the `unit (PHP 8.2)` job was the only net against the defect. The wrapper gives the same
     * pair on any version: `stat` says `size = 0`, reading returns the real contents.
     *
     * The test is deliberately STRICTER than the defect, and that is a property of the chosen
     * implementation rather than an oversight: a fix through `clearstatcache()` would NOT pass it —
     * there is nothing to flush here, the wrapper does not lie out of a cache. The invariant pinned
     * is "the check does not go to `stat` for the size at all"; weakening the implementation to
     * "flush the cache before `filesize()`" must redden this test, and that is correct.
     */
    public function testCompletenessComesFromTheBytesAndNotFromTheReportedSize(): void
    {
        $short = LyingSizeStreamWrapper::SCHEME . '://short.sql';
        $long = LyingSizeStreamWrapper::SCHEME . '://long.sql';
        $truncated = LyingSizeStreamWrapper::SCHEME . '://truncated.sql';
        $empty = LyingSizeStreamWrapper::SCHEME . '://empty.sql';

        LyingSizeStreamWrapper::install([
            // Shorter than the tail the check looks for: an offset from the end will not fit, everything is read.
            $short => "CREATE TABLE `modx_probe` (id INT);\n" . SnapshotFile::completionLine(1),
            // Longer than the tail: here the offset from the end does the work.
            $long => str_repeat("INSERT INTO `modx_probe` VALUES (1);\n", 40)
                . SnapshotFile::completionLine(1),
            $truncated => str_repeat("INSERT INTO `modx_probe` VALUES (1);\n", 40),
            $empty => '',
        ]);

        try {
            self::assertSame(0, filesize($short), 'The wrapper must lie about the size — otherwise the test checks nothing.');

            self::assertTrue(SnapshotFile::isComplete($short));
            self::assertTrue(SnapshotFile::isComplete($long));
            self::assertFalse(SnapshotFile::isComplete($truncated));
            self::assertFalse(SnapshotFile::isComplete($empty));
        } finally {
            LyingSizeStreamWrapper::uninstall();
        }
    }

    public function testAssertCompleteNamesTheStrategyAndTheNextStep(): void
    {
        file_put_contents($this->file, "SET FOREIGN_KEY_CHECKS=0;\n");

        try {
            SnapshotFile::assertComplete($this->file, 'php');
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('strategy php', $exception->getMessage());
            self::assertStringContainsString($this->file, $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_FORCE_INSTALL=1', $exception->getMessage());
        }
    }

    public function testAssertCompleteReportsAMissingFileSeparately(): void
    {
        try {
            SnapshotFile::assertComplete($this->file, 'mysql');
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('snapshot file not found: ' . $this->file, $exception->getMessage());
        }
    }
}

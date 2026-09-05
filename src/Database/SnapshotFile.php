<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Database;

use ModxKit\Testbench\Exception\SnapshotFailedException;

/**
 * The completeness marker of a snapshot file.
 *
 * While the only check was `filesize() > 0`, a capture cut short by SIGKILL, a fatal error, OOM or a
 * CI timeout left a file every guard considered sound. A cut on a statement boundary (`;\n`) —
 * roughly half of the positions — gave a restore WITHOUT a single error: the cleanup wiped every
 * table and the snapshot created only some. So a capture that finished appends a marker to the tail,
 * and no restore begins without it.
 *
 * The marker is an SQL comment: both the {@see PhpDumper::statements()} parser and the `mysql`
 * client skip it, and it never becomes an executable statement.
 *
 * @internal
 */
final class SnapshotFile
{
    public const COMPLETION_PREFIX = '-- testbench:complete tables=';

    /**
     * How many bytes from the end of the file to read while looking for the marker. The marker is
     * shorter than fifty bytes; the margin is needed only for a possible trailing newline.
     */
    private const TAIL_BYTES = 256;

    /**
     * @return non-empty-string
     */
    public static function completionLine(int $tables): string
    {
        return self::COMPLETION_PREFIX . $tables . "\n";
    }

    public static function isComplete(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            // The tail is counted from the END of the file, not from `filesize()`. The size comes
            // from the `stat` cache, and on PHP 8.2 that cache survives an append to an already
            // stat'ed file. Measured on php:8.2.33 and on php 8.4.8 with one script: after
            // `touch($f); filesize($f); file_put_contents($f, …, FILE_APPEND);` the next
            // `filesize($f)` returns the previous zero on 8.2 and the new size on 8.4. A completed
            // snapshot answered `exists() === false` because of that — and that is how the
            // `unit (PHP 8.2)` job failed while 8.3 and 8.4 were green.
            //
            // The package's production paths were not harmed by it: a snapshot is put into place
            // only by `rename()`, and `rename()` flushes the cache on both versions (measured with
            // the same script). But `isComplete()` is a public check, and it is obliged to answer
            // about the file, not about how the process remembers it.
            //
            // A negative offset from `SEEK_END` is never shorter than the file: on a file smaller
            // than the tail `fseek()` returns -1 (measured on both versions), and then the whole
            // file is read. An empty file gives an empty tail, that is, the absence of the marker —
            // the former `filesize() === 0` check answered it the same way.
            if (fseek($handle, -self::TAIL_BYTES, SEEK_END) !== 0) {
                rewind($handle);
            }

            $tail = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($tail === false) {
            return false;
        }

        // The marker counts only as the last line of the file: anything appended after it means the
        // snapshot was recaptured and cut short again.
        $lines = preg_split('/\R/', rtrim($tail, "\r\n"));
        $last = $lines === false || $lines === [] ? '' : (string) $lines[count($lines) - 1];

        return str_starts_with($last, self::COMPLETION_PREFIX);
    }

    /**
     * @param string $strategy the strategy name for the failure message ('php' or 'mysql')
     */
    public static function assertComplete(string $file, string $strategy): void
    {
        if (!is_file($file)) {
            throw SnapshotFailedException::because($strategy, "snapshot file not found: {$file}");
        }

        if (self::isComplete($file)) {
            return;
        }

        throw SnapshotFailedException::because($strategy, sprintf(
            'snapshot %s is not read to the end: the tail of the file carries no "%sN" marker, '
            . 'which a finished capture writes. The file is left over from an interrupted capture() '
            . '(SIGKILL, timeout, no free space), and restoring from it would destroy the database, '
            . 'bringing it back only in part. Nothing was touched — capture the snapshot again '
            . '(vendor/bin/modx-testbench snapshot capture) or recreate the environment '
            . '(MODX_TESTBENCH_FORCE_INSTALL=1)',
            $file,
            self::COMPLETION_PREFIX
        ));
    }
}

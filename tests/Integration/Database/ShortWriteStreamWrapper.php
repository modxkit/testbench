<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Database;

/**
 * A stream that accepts exactly {@see self::$capacity} bytes and answers with a short write after
 * that — the way a file system behaves when it has run out of space.
 *
 * It is needed to check the short-write branch in `PhpDumper::write()`: a genuinely full disk cannot
 * be reproduced in a test, and without checking the number `fwrite()` returned the snapshot would go
 * on being appended "successfully" and would break off right in the middle of a statement.
 */
final class ShortWriteStreamWrapper
{
    public const SCHEME = 'tbshort';

    /** How many bytes the stream accepts before it starts answering with a short write. */
    public static int $capacity = 0;

    /** What the stream managed to accept — for the assertions of the test. */
    public static string $written = '';

    /** @var resource|null */
    public $context;

    public static function install(int $capacity): void
    {
        self::$capacity = $capacity;
        self::$written = '';

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function uninstall(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $room = self::$capacity - strlen(self::$written);

        if ($room <= 0) {
            return 0;
        }

        $accepted = substr($data, 0, $room);
        self::$written .= $accepted;

        return strlen($accepted);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100666, 'size' => strlen(self::$written)];
    }

    /**
     * The directory answers "exists and is writable", the file answers "does not exist":
     * `PhpDumper::dump()` checks the directory before opening the file, and the `unlink()` in the
     * failure branch must not stumble over a path that does not exist.
     *
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return str_ends_with($path, '.sql.part') || str_ends_with($path, '.sql')
            ? false
            : ['mode' => 0040777, 'size' => 0];
    }

    public function unlink(string $path): bool
    {
        return true;
    }

    public function rename(string $from, string $to): bool
    {
        return true;
    }
}

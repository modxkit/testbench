<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Database;

/**
 * A stream whose `stat` LIES about the size: `stream_stat()` and `url_stat()` always answer
 * `size = 0`, while reading returns the real bytes.
 *
 * On PHP 8.2 the `stat` cache produces exactly this divergence: after `touch()` plus an append
 * `filesize()` goes on answering zero, and a finished snapshot counted as unfinished. The cache is
 * version-dependent (8.3 and 8.4 answer with the fresh size), the wrapper is not: it gives the same
 * divergent pair — "what stat says" against "what is in the file" — on any version of PHP.
 *
 * Models of the technique in this repository: `tests/Integration/Database/ShortWriteStreamWrapper.php`
 * and `tests/Unit/Installer/UnchmodableStreamWrapper.php`.
 */
final class LyingSizeStreamWrapper
{
    public const SCHEME = 'tblyingsize';

    /**
     * The contents held at each stream address.
     *
     * @var array<string, string>
     */
    public static array $files = [];

    /** @var resource|null */
    public $context;

    private string $contents = '';

    private int $position = 0;

    /**
     * @param array<string, string> $files stream address => the real contents
     */
    public static function install(array $files): void
    {
        self::$files = $files;

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function uninstall(): void
    {
        self::$files = [];

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!array_key_exists($path, self::$files)) {
            return false;
        }

        $this->contents = self::$files[$path];
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->contents, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * An offset past the beginning of the file is a refusal, exactly as with an ordinary file:
     * `fseek()` returns -1, and the code under test must survive that case (the file being shorter
     * than the tail it is looking for).
     */
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->contents) + $offset,
            default => -1,
        };

        if ($target < 0) {
            return false;
        }

        $this->position = $target;

        return true;
    }

    /**
     * The size is the wrapper's only lie.
     *
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100666, 'size' => 0];
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return array_key_exists($path, self::$files) ? ['mode' => 0100666, 'size' => 0] : false;
    }
}

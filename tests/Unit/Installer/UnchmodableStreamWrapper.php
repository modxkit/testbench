<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Installer;

/**
 * A path on which writing SUCCEEDS while `chmod()` refuses: `stream_write()` returns the length,
 * and `stream_metadata()` answers `false` to `chmod()` (`STREAM_META_ACCESS`) and yes to everything
 * else. The wrapper models exactly this shape, because both callers call
 * `FilePermissions::restrict()` right after successfully writing a file.
 *
 * By general description, this is how some network and container mounts behave. That was NOT
 * confirmed by measurement; the wrapper asserts only the shape of the refusal it was written for.
 *
 * The measured `chmod()` refusals (`chflags uchg`, a read-only volume) do NOT produce this shape
 * and cannot serve as an example of it: on a file with the immutable flag `file_put_contents()`,
 * `fopen()` for writing and `unlink()` all refuse as well, and a read-only volume does not allow
 * writing by definition — it never gets as far as `chmod()`.
 *
 * There is nothing to reproduce the shape with using a real directory in a test (one's own file on
 * an ordinary file system always chmods), and the branch matters: tightening the permissions is a
 * protective measure rather than a criterion of success, and the install must not fail because of
 * it. The model for this wrapper is `tests/Integration/Database/ShortWriteStreamWrapper.php`.
 */
final class UnchmodableStreamWrapper
{
    public const SCHEME = 'tbnochmod';

    /** What was written — for the assertions of the test. */
    public static string $written = '';

    /** @var resource|null */
    public $context;

    public static function install(): void
    {
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
        self::$written .= $data;

        return strlen($data);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100666, 'size' => strlen(self::$written)];
    }

    /**
     * A path without an extension is a writable directory (`ConfigXmlWriter::write()` checks
     * `is_dir()`/`is_writable()` before writing); everything else is an ordinary file.
     *
     * @return array<int|string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return str_contains(basename($path), '.')
            ? ['mode' => 0100666, 'size' => strlen(self::$written)]
            : ['mode' => 0040777, 'size' => 0];
    }

    /**
     * Exactly the reason the wrapper exists: `chmod()` (`STREAM_META_ACCESS`) answers with a
     * refusal, everything else with consent.
     *
     * @param mixed $value
     */
    public function stream_metadata(string $path, int $option, $value): bool
    {
        return $option !== STREAM_META_ACCESS;
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment\Provider;

use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Exception\CoreDownloadFailedException;
use ModxKit\Testbench\Support\ZipSlipGuard;
use ZipArchive;

/**
 * @internal
 */
final readonly class ZipReleaseProvider implements CoreProvider
{
    /**
     * SHA-256 of the release archives of the supported lines.
     *
     * The archive is not data: `index.php` out of it is `require_once`d by
     * {@see \ModxKit\Testbench\Bootstrap\KernelBootstrapper::requireGateway()} in the PHPUnit
     * process, so whoever writes into the release cache runs code in the consumer's test run. The
     * previous edition asked only "over a megabyte?" and "does ZipArchive open it?", and a
     * hand-assembled archive answered both — measured, `provide()` returned a workspace whose
     * `index.php` was the planted one.
     *
     * The values were taken from BOTH published sources at once
     * ({@see self::releaseUrls()} — the modx.com redirect and the S3 bucket behind it) and the two
     * agreed byte for byte:
     *
     *     3.1.2-pl  24 265 856 bytes  (both sources agreed)
     *     3.2.3-pl  24 751 983 bytes  (both sources agreed, and the local release cache with them)
     *
     * The digests themselves are the values below and are not repeated here in an abbreviated
     * form: an abbreviated hexadecimal string in prose is indistinguishable from an address of
     * something the reader cannot fetch.
     *
     * That is not a chain of trust — the two sources are one publisher — and it is not claimed to
     * be one. What it does buy is exactly what the threat here is: a cache file replaced AFTER the
     * download, by a restored CI cache or by anything else with write access to the directory.
     *
     * Only the two supported lines are listed, and a version outside them is not refused: an
     * unmeasured version is "we do not know", not "you may not". It is announced instead —
     * {@see self::acceptArchive()}.
     *
     * @var array<string, string>
     */
    private const RELEASE_DIGESTS = [
        '3.1.2-pl' => '9d1f6cb3bfb1dd1282d7087bb258ce7ef0e5e40da71070dc719d6fcdf917f2c8',
        '3.2.3-pl' => '49444c18e9bd97c09072c2c53fbd7477926eef52ca5139a3ddc54a0c7044e266',
    ];

    public function __construct(
        private string $version,
        private string $cacheDir,
        /** @var array<int, string>|null */
        private ?array $urls = null,
    ) {
    }

    public function fingerprint(): string
    {
        return 'zip:' . $this->version;
    }

    /**
     * @return array<int, string>
     */
    public static function releaseUrls(string $version): array
    {
        // modxcms/revolution publishes no GitHub Releases — only tags, so the only working sources
        // are the official modx.com redirect and the S3 behind it.
        $directory = (string) preg_replace('/-(pl|rc|beta|alpha)\d*$/', '', $version);

        return [
            sprintf('https://modx.com/download/direct/modx-%s.zip', $version),
            sprintf('https://modx.s3.amazonaws.com/releases/%s/modx-%s.zip', $directory, $version),
        ];
    }

    public function provide(string $targetDir): CoreLocation
    {
        $archive = $this->ensureArchive();
        $this->extract($archive, $targetDir);

        return new CoreLocation(rtrim($targetDir, '/') . '/', $this->version);
    }

    private function ensureArchive(): string
    {
        $file = rtrim($this->cacheDir, '/') . '/releases/modx-' . $this->version . '.zip';
        $lastError = 'no attempts';
        $discardedCache = null;

        if (is_file($file)) {
            if ($this->isReadableArchive($file) && $this->isTheRelease($file)) {
                return $this->acceptArchive($file);
            }

            // The cached file is discarded either way, but the two reasons are not the same news:
            // a corrupt download is an accident, a well-formed archive that is NOT the release is
            // somebody having written into the cache directory. That cause is carried to the throw
            // separately — otherwise the first unreachable URL would overwrite it, and the message
            // would report a network problem where the real event was a replaced cache file.
            if ($this->isReadableArchive($file)) {
                $discardedCache = $this->digestMismatch($file, 'the cached archive');
            }

            unlink($file);
        }

        $this->makeDirectory(dirname($file));
        $urls = $this->urls ?? self::releaseUrls($this->version);

        foreach ($urls as $url) {
            $stream = @fopen($url, 'rb');

            if ($stream === false) {
                $lastError = 'failed to open ' . $url;
                continue;
            }

            $written = @file_put_contents($file, $stream);
            fclose($stream);

            if ($written !== false && $this->isReadableArchive($file)) {
                if ($this->isTheRelease($file)) {
                    return $this->acceptArchive($file);
                }

                $lastError = $this->digestMismatch($file, 'the archive downloaded from ' . $url);
                @unlink($file);
                continue;
            }

            $lastError = 'the downloaded archive is corrupt: ' . $url;
            @unlink($file);
        }

        throw CoreDownloadFailedException::forVersion(
            $this->version,
            $urls,
            $this->cacheDir,
            $discardedCache === null ? $lastError : $discardedCache . '; and then ' . $lastError
        );
    }

    /**
     * Whether the file is the release archive of this version. A version with no digest of its own
     * has nothing to compare against, and there the answer is yes — with a warning
     * ({@see self::acceptArchive()}), not with a silence.
     */
    private function isTheRelease(string $file): bool
    {
        $expected = self::RELEASE_DIGESTS[$this->version] ?? null;

        return $expected === null || hash_file('sha256', $file) === $expected;
    }

    /**
     * The last gate before the archive is unpacked and its `index.php` becomes the gateway the
     * PHPUnit process loads. For a supported version the digest has already matched by now; for
     * any other version this is where the package says out loud that it verified nothing.
     *
     * The channel is the `E_USER_WARNING` the package uses for what it will not refuse but must
     * not hide ({@see \ModxKit\Testbench\Support\FilePermissions}). Refusing instead was rejected
     * for the reason stated at {@see self::RELEASE_DIGESTS}: an unlisted version means the
     * measurement is missing, not that the version is forbidden.
     */
    private function acceptArchive(string $file): string
    {
        if (isset(self::RELEASE_DIGESTS[$this->version])) {
            return $file;
        }

        trigger_error(
            sprintf(
                'modx-testbench: the integrity of the MODX %s archive was not verified — a known '
                . 'SHA-256 is held only for the supported versions (%s), and %s is not one of them. '
                . 'The archive %s is unpacked as it is, and its index.php is loaded into the test '
                . 'process. Set MODX_TESTBENCH_VERSION to a supported version, or make sure the '
                . 'release cache is a directory only you can write to.',
                $this->version,
                implode(', ', array_keys(self::RELEASE_DIGESTS)),
                $this->version,
                $file
            ),
            E_USER_WARNING
        );

        return $file;
    }

    private function digestMismatch(string $file, string $what): string
    {
        return sprintf(
            '%s is not the %s release: its SHA-256 is %s instead of %s',
            $what,
            $this->version,
            (string) hash_file('sha256', $file),
            self::RELEASE_DIGESTS[$this->version] ?? '(unknown)'
        );
    }

    private function extract(string $archive, string $targetDir): void
    {
        $this->makeDirectory($targetDir);

        $zip = new ZipArchive();

        if ($zip->open($archive) !== true) {
            throw CoreDownloadFailedException::forVersion(
                $this->version,
                [$archive],
                $this->cacheDir,
                'the archive does not open'
            );
        }

        $this->guardAgainstZipSlip($zip, $archive);

        $zip->extractTo($targetDir);
        $zip->close();

        $this->flattenIfNested($targetDir);
    }

    /**
     * Zip slip protection: no entry of the archive may escape the target directory by an absolute
     * path or a ".." segment.
     */
    private function guardAgainstZipSlip(ZipArchive $zip, string $archive): void
    {
        $escapingEntry = ZipSlipGuard::findEscapingEntry($zip);

        if ($escapingEntry !== null) {
            $zip->close();

            throw CoreDownloadFailedException::forVersion(
                $this->version,
                [$archive],
                $this->cacheDir,
                sprintf('the archive contains an unsafe path: %s', $escapingEntry)
            );
        }
    }

    /**
     * A release archive unpacks into a modx-<version> subdirectory; we lift the contents one level
     * up.
     */
    private function flattenIfNested(string $targetDir): void
    {
        $nested = rtrim($targetDir, '/') . '/modx-' . $this->version;

        if (!is_dir($nested) || is_file(rtrim($targetDir, '/') . '/index.php')) {
            return;
        }

        foreach (scandir($nested) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            rename($nested . '/' . $entry, rtrim($targetDir, '/') . '/' . $entry);
        }

        rmdir($nested);
    }

    private function isReadableArchive(string $file): bool
    {
        if (filesize($file) === false || filesize($file) < 1_000_000) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($file) !== true) {
            return false;
        }

        $zip->close();

        return true;
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw CoreDownloadFailedException::forVersion(
                $this->version,
                [],
                $this->cacheDir,
                "failed to create directory {$path}"
            );
        }
    }
}

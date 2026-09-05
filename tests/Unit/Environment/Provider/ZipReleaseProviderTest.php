<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Environment\Provider;

use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Environment\Provider\ZipReleaseProvider;
use ModxKit\Testbench\Exception\CoreDownloadFailedException;
use ModxKit\Testbench\Tests\Support\CapturesWarnings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[Group('unit')]
final class ZipReleaseProviderTest extends TestCase
{
    use CapturesWarnings;

    /**
     * A version outside the two supported lines on purpose: a fixture archive is not the release,
     * so under a supported version the integrity check would refuse it. The price is that every
     * `provide()` in this class announces that the integrity of the archive was not verified —
     * which is exactly the announcement {@see self::testAnUnsupportedVersionIsAcceptedButSaidAloud()}
     * pins down, and the reason the calls below are wrapped in `captureWarnings()`.
     */
    private const FIXTURE_VERSION = '9.9.9-pl';

    /**
     * The release of a supported line. Used with FIXTURE archives, which are not that release —
     * that is the point of the two tests below.
     */
    private const SUPPORTED_VERSION = '3.2.3-pl';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/modx-testbench-unit-' . bin2hex(random_bytes(4));

        mkdir($this->tempDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    public function testFingerprintIncludesVersion(): void
    {
        $provider = new ZipReleaseProvider('3.2.3-pl', sys_get_temp_dir());

        self::assertSame('zip:3.2.3-pl', $provider->fingerprint());
    }

    public function testReleaseUrlsPointAtTheRequestedVersion(): void
    {
        $urls = ZipReleaseProvider::releaseUrls('3.2.3-pl');

        self::assertNotEmpty($urls);

        foreach ($urls as $url) {
            self::assertStringContainsString('3.2.3-pl', $url);
            self::assertStringStartsWith('https://', $url);
        }
    }

    public function testCoreLocationDerivesPathsFromRoot(): void
    {
        $location = new CoreLocation('/tmp/env/', '3.2.3-pl');

        self::assertSame('/tmp/env/core/', $location->corePath());
        self::assertSame('/tmp/env/setup/', $location->setupPath());
        self::assertSame('/tmp/env/index.php', $location->indexFile());
        self::assertSame('/tmp/env/core/packages/', $location->packagesPath());
    }

    public function testCorruptCacheIsInvalidatedAndRefetchedOnce(): void
    {
        $cacheDir = $this->tempDir . '/cache';
        $cacheFile = $cacheDir . '/releases/modx-' . self::FIXTURE_VERSION . '.zip';

        mkdir(dirname($cacheFile), 0o775, true);
        file_put_contents($cacheFile, 'this is not a zip archive');

        $fixture = $this->tempDir . '/fixture-valid.zip';
        $this->buildFixtureZip($fixture);

        $target = $this->tempDir . '/target-corrupt-cache';
        $provider = new ZipReleaseProvider(self::FIXTURE_VERSION, $cacheDir, ['file://' . $fixture]);

        $location = null;
        $warnings = $this->captureWarnings(function () use ($provider, $target, &$location): void {
            $location = $provider->provide($target);
        });

        self::assertInstanceOf(CoreLocation::class, $location);
        self::assertFileExists($location->indexFile());
        self::assertGreaterThan(1_000_000, filesize($cacheFile));
        self::assertSame(md5_file($fixture), md5_file($cacheFile));
        self::assertCount(1, $warnings, 'the unverified integrity of an unsupported version is announced once');
    }

    public function testProvidesViaSecondUrlWhenFirstUrlFails(): void
    {
        $cacheDir = $this->tempDir . '/cache';
        $fixture = $this->tempDir . '/fixture-valid.zip';
        $this->buildFixtureZip($fixture);

        $badUrl = 'file://' . $this->tempDir . '/does-not-exist.zip';
        $goodUrl = 'file://' . $fixture;

        $target = $this->tempDir . '/target-fallback';
        $provider = new ZipReleaseProvider(self::FIXTURE_VERSION, $cacheDir, [$badUrl, $goodUrl]);

        $location = null;
        $this->captureWarnings(function () use ($provider, $target, &$location): void {
            $location = $provider->provide($target);
        });

        self::assertInstanceOf(CoreLocation::class, $location);
        self::assertFileExists($location->indexFile());
    }

    public function testThrowsWhenAllUrlsFail(): void
    {
        $cacheDir = $this->tempDir . '/cache';
        $firstUrl = 'file://' . $this->tempDir . '/missing-one.zip';
        $secondUrl = 'file://' . $this->tempDir . '/missing-two.zip';

        $provider = new ZipReleaseProvider(self::FIXTURE_VERSION, $cacheDir, [$firstUrl, $secondUrl]);

        try {
            $provider->provide($this->tempDir . '/target-all-fail');
            self::fail('Expected CoreDownloadFailedException was not thrown.');
        } catch (CoreDownloadFailedException $exception) {
            self::assertStringContainsString(self::FIXTURE_VERSION, $exception->getMessage());
            self::assertStringContainsString($firstUrl, $exception->getMessage());
            self::assertStringContainsString($secondUrl, $exception->getMessage());
            self::assertStringContainsString('MODX_TESTBENCH_VERSION', $exception->getMessage());
        }
    }

    public function testRejectsArchiveWithPathTraversalEntry(): void
    {
        $cacheDir = $this->tempDir . '/cache';
        $cacheFile = $cacheDir . '/releases/modx-' . self::FIXTURE_VERSION . '.zip';

        mkdir(dirname($cacheFile), 0o775, true);
        $this->buildFixtureZip($cacheFile, ['../evil.txt' => 'pwned']);

        $target = $this->tempDir . '/target-zip-slip';
        $provider = new ZipReleaseProvider(self::FIXTURE_VERSION, $cacheDir);

        $this->captureWarnings(function () use ($provider, $target): void {
            try {
                $provider->provide($target);
                self::fail('Expected CoreDownloadFailedException was not thrown.');
            } catch (CoreDownloadFailedException $exception) {
                self::assertStringContainsString('evil.txt', $exception->getMessage());
            }
        });

        self::assertFileDoesNotExist($this->tempDir . '/evil.txt');
    }

    /**
     * A cached archive is a file anybody who can write into the cache directory has put there, and
     * `index.php` out of it is `require_once`d by {@see \ModxKit\Testbench\Bootstrap\KernelBootstrapper}
     * in the PHPUnit process. The previous edition of the check asked two questions — "is it over a
     * megabyte" and "does ZipArchive open it" — and an archive assembled by hand answered both:
     * measured, `provide()` returned a workspace whose `index.php` was the planted one.
     *
     * `MODX_TESTBENCH_CACHE_DIR` is documented as a directory a CI cache action may restore
     * ({@see \ModxKit\Testbench\Environment\Workspace}), that is, as an artifact rebuilt from
     * outside the run — which is what makes the cache a place worth checking rather than a private
     * scratch directory.
     */
    public function testACachedArchiveThatIsNotTheReleaseIsRefused(): void
    {
        $cacheDir = $this->tempDir . '/cache';
        $cacheFile = $cacheDir . '/releases/modx-' . self::SUPPORTED_VERSION . '.zip';

        mkdir(dirname($cacheFile), 0o775, true);
        $this->buildFixtureZip($cacheFile, ['index.php' => '<?php // planted' . PHP_EOL]);

        // The archive clears both of the old checks, so nothing but the digest can refuse it.
        self::assertGreaterThan(1_000_000, filesize($cacheFile));

        $provider = new ZipReleaseProvider(
            self::SUPPORTED_VERSION,
            $cacheDir,
            ['file://' . $this->tempDir . '/there-is-no-such-file.zip']
        );

        try {
            $provider->provide($this->tempDir . '/target-planted-cache');
            self::fail('A cache file that is not the release was accepted.');
        } catch (CoreDownloadFailedException $exception) {
            self::assertStringContainsString('SHA-256', $exception->getMessage());
            self::assertStringContainsString(self::SUPPORTED_VERSION, $exception->getMessage());
        }

        self::assertFileDoesNotExist($cacheFile, 'A rejected archive must not stay in the cache.');
    }

    /**
     * The same check on the download path: `@file_put_contents($file, $stream)` writes whatever
     * answered at the URL, and the answer of a hijacked mirror or of a captive portal is a file
     * like any other.
     */
    public function testADownloadedArchiveThatIsNotTheReleaseIsRefused(): void
    {
        $cacheDir = $this->tempDir . '/cache';
        $fixture = $this->tempDir . '/fixture-not-the-release.zip';
        $this->buildFixtureZip($fixture);

        $provider = new ZipReleaseProvider(self::SUPPORTED_VERSION, $cacheDir, ['file://' . $fixture]);

        try {
            $provider->provide($this->tempDir . '/target-planted-download');
            self::fail('A downloaded file that is not the release was accepted.');
        } catch (CoreDownloadFailedException $exception) {
            self::assertStringContainsString('SHA-256', $exception->getMessage());
        }

        self::assertFileDoesNotExist(
            $cacheDir . '/releases/modx-' . self::SUPPORTED_VERSION . '.zip',
            'A refused download must not be left in the cache for the next run to reuse.'
        );
    }

    /**
     * A version outside the supported lines has no digest to compare against, and refusing it would
     * turn "we have not measured this" into "you may not". So it is accepted — and the fact that
     * nothing about it was verified is said out loud, through the same `E_USER_WARNING` the package
     * uses for the exposures it cannot fix itself.
     */
    public function testAnUnsupportedVersionIsAcceptedButSaidAloud(): void
    {
        $cacheDir = $this->tempDir . '/cache';
        $fixture = $this->tempDir . '/fixture-unsupported.zip';
        $this->buildFixtureZip($fixture);

        $provider = new ZipReleaseProvider(self::FIXTURE_VERSION, $cacheDir, ['file://' . $fixture]);

        $location = null;
        $warnings = $this->captureWarnings(function () use ($provider, &$location): void {
            $location = $provider->provide($this->tempDir . '/target-unsupported');
        });

        self::assertInstanceOf(CoreLocation::class, $location);
        self::assertFileExists($location->indexFile());

        self::assertCount(1, $warnings);
        self::assertStringContainsString(self::FIXTURE_VERSION, $warnings[0]);
        self::assertStringContainsString('integrity', $warnings[0]);
    }

    /**
     * @param array<string, string> $entries
     */
    private function buildFixtureZip(string $path, array $entries = []): void
    {
        if ($entries === []) {
            $entries = ['index.php' => '<?php // fixture marker' . PHP_EOL];
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::fail('Unable to create fixture archive at ' . $path);
        }

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        // isReadableArchive() requires a size above 1 MB; pad with incompressible
        // filler so the fixture always clears that floor regardless of deflate ratio.
        $zip->addFromString('filler.bin', random_bytes(1_300_000));
        $zip->close();
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Installer;

use FilesystemIterator;
use ModxKit\Testbench\Environment\AdminConfig;
use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Exception\InstallationFailedException;
use ModxKit\Testbench\Installer\HeadlessInstaller;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The real `install()` against a fake `setup/index.php`: the manifest is written, the process is
 * launched, the output is parsed — only the MODX core itself is substituted. No database, no
 * network.
 *
 * The fake installer behaves like the real one on a failed install: it prints the lexicon text of
 * the refusal, leaves a WORKING `core/config/config.inc.php` (MODX writes it well before the schema
 * reaches its end) and exits with code 0 — `modInstallCLIRequest::end()` ends both success and
 * failure through `die($message)`, and `die()` with a string always yields 0. The only signal of
 * failure is the text in the output.
 */
#[Group('unit')]
final class HeadlessInstallerFakeSetupTest extends TestCase
{
    private const FAILURE_LINE = 'Installation Failed! Errors: could not create table modx_site_content';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeDirectory($root);
        }

        $this->roots = [];
    }

    /**
     * `Secret::mask()` is a plain `str_replace`, and while it was applied to the output BEFORE the
     * search for `FAILURE_MARKERS`, a password that was a substring of a marker erased the marker
     * from the text: "Installation Failed!" turned into "Installation ***!", a failed install
     * passed as a successful one, and the tests then ran against a half-installed environment. The
     * third gate (`config.inc.php`) does not catch such a failure — on an install killed in the
     * middle of the schema the file is already real.
     */
    public function testDetectsAFailureMarkerWhenTheDatabasePasswordIsASubstringOfIt(): void
    {
        $core = $this->fakeCore(self::FAILURE_LINE);

        try {
            (new HeadlessInstaller())->install($core, $this->config('Failed'));
            self::fail('A failed install passed as a successful one.');
        } catch (InstallationFailedException $exception) {
            self::assertStringContainsString(
                'failure marker "Installation Failed!"',
                $exception->getMessage()
            );
        }
    }

    /**
     * A control: with an ordinary password the same failure is caught whatever the order of masking
     * and searching — and at the same time it exercises the second half of the fix, the masking of
     * the EXCEPTION TEXT: the password the fake installer prints, having read it from the manifest,
     * must not end up in the diagnostics.
     */
    public function testDetectsAFailureMarkerWithAnOrdinaryPasswordAndMasksItInTheDiagnostics(): void
    {
        $password = 'ordinary-db-password';
        $core = $this->fakeCore(self::FAILURE_LINE);

        try {
            (new HeadlessInstaller())->install($core, $this->config($password));
            self::fail('A failed install passed as a successful one.');
        } catch (InstallationFailedException $exception) {
            self::assertStringContainsString(
                'failure marker "Installation Failed!"',
                $exception->getMessage()
            );
            self::assertStringNotContainsString($password, $exception->getMessage());
            self::assertStringContainsString('connecting as user tester with password ***', $exception->getMessage());
        }
    }

    /**
     * The rig is not rigged: the same fake installer without the marker in its output lets the
     * install through. Without this check both tests above would go green against an `install()`
     * that always refuses.
     */
    public function testAcceptsACleanRunOfTheSameHarness(): void
    {
        $core = $this->fakeCore('Installation finished in 2.5 s');

        (new HeadlessInstaller())->install($core, $this->config('Failed'));

        self::assertFileExists($core->corePath() . 'config/config.inc.php');
    }

    /**
     * The `setup/config.xml` manifest carries the database password and the administrator password
     * in clear text and used to survive the whole run: it stayed in the working directory until the
     * directory itself was removed. The core reads it EXACTLY ONCE, at the very beginning of the
     * CLI request (`setup/includes/request/modinstallclirequest.class.php:86` → `getConfig()` →
     * `loadConfigFile()`), so after a successful install nobody needs the file.
     *
     * The same database password is duplicated in `core/config/config.inc.php`, and that file is
     * always needed by the core — it cannot be deleted, so its permissions are narrowed to 0600.
     */
    public function testSuccessfulInstallRemovesTheManifestAndLocksDownTheCoreConfig(): void
    {
        $core = $this->fakeCore('Installation finished in 2.5 s');

        (new HeadlessInstaller())->install($core, $this->config('db-password'));

        self::assertFileDoesNotExist($core->setupPath() . 'config.xml');
        self::assertSame('0600', $this->permissions($core->corePath() . 'config/config.inc.php'));
    }

    /**
     * The other side: on a FAILURE the manifest stays. It is the principal diagnostic artefact —
     * the exception text advises looking at it outright — and the environment will be reinstalled
     * or removed wholesale anyway.
     */
    public function testFailedInstallKeepsTheManifestForDiagnostics(): void
    {
        $core = $this->fakeCore(self::FAILURE_LINE);

        try {
            (new HeadlessInstaller())->install($core, $this->config('db-password'));
            self::fail('A failed install passed as a successful one.');
        } catch (InstallationFailedException) {
            // What matters is not the failure itself (checked above) but the fate of the manifest.
        }

        self::assertFileExists($core->setupPath() . 'config.xml');
        self::assertSame('0600', $this->permissions($core->setupPath() . 'config.xml'));
    }

    private function permissions(string $file): string
    {
        $mode = fileperms($file);

        self::assertNotFalse($mode, "Could not read the permissions of {$file}");

        return substr(sprintf('%o', $mode), -4);
    }

    private function config(string $databasePassword): TestbenchConfig
    {
        return new TestbenchConfig(
            provider: 'zip',
            version: TestbenchConfig::DEFAULT_VERSION,
            gitRef: '3.x',
            localCorePath: null,
            database: new DatabaseConfig(
                host: '127.0.0.1',
                port: 3306,
                name: 'modx_testbench_fake',
                user: 'tester',
                password: $databasePassword,
                prefix: 'modx_',
                charset: 'utf8mb4',
                collation: 'utf8mb4_general_ci',
            ),
            admin: new AdminConfig('testbench', 'TestbenchPass123!', 'testbench@example.com'),
            cacheDir: sys_get_temp_dir() . '/tb-fake-cache',
            workspaceDir: null,
            forceInstall: false,
        );
    }

    /**
     * A directory indistinguishable from an unpacked distribution in everything `install()` needs:
     * `setup/` for the manifest and `core/config/` for the result.
     */
    private function fakeCore(string $lastLine): CoreLocation
    {
        $root = sys_get_temp_dir() . '/tb-fake-core-' . bin2hex(random_bytes(4)) . '/';
        $this->roots[] = $root;

        self::assertTrue(mkdir($root . 'setup', 0o775, true));
        self::assertTrue(mkdir($root . 'core/config', 0o775, true));

        $script = <<<'PHP'
            <?php

            $options = getopt('', ['installmode:', 'core_path:', 'config:']);
            $manifest = simplexml_load_file((string) $options['config']);
            $corePath = (string) $options['core_path'];

            // The real installer writes a working config.inc.php long before the end of the
            // schema, so on a failed install the file is no longer a stub.
            file_put_contents(
                $corePath . 'config/config.inc.php',
                "<?php\ndefine('MODX_CORE_PATH', '{$corePath}');\n"
            );

            echo 'connecting as user ' . $manifest->database_user
                . ' with password ' . $manifest->database_password . "\n";
            echo LAST_LINE . "\n";
            PHP;

        file_put_contents(
            $root . 'setup/index.php',
            str_replace('LAST_LINE', var_export($lastLine, true), $script)
        );

        return new CoreLocation($root, TestbenchConfig::DEFAULT_VERSION);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}

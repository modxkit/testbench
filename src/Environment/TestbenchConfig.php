<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

use ModxKit\Testbench\Environment\Provider\CoreProvider;
use ModxKit\Testbench\Environment\Provider\GitCloneProvider;
use ModxKit\Testbench\Environment\Provider\LocalPathProvider;
use ModxKit\Testbench\Environment\Provider\ZipReleaseProvider;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\Env;

final readonly class TestbenchConfig
{
    public const DEFAULT_VERSION = '3.2.3-pl';

    public function __construct(
        public string $provider,
        public string $version,
        public string $gitRef,
        public ?string $localCorePath,
        public DatabaseConfig $database,
        public AdminConfig $admin,
        public string $cacheDir,
        public ?string $workspaceDir,
        public bool $forceInstall,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            provider: Env::get('MODX_TESTBENCH_PROVIDER', 'zip') ?? 'zip',
            version: Env::get('MODX_TESTBENCH_VERSION', self::DEFAULT_VERSION) ?? self::DEFAULT_VERSION,
            gitRef: Env::get('MODX_TESTBENCH_GIT_REF', '3.x') ?? '3.x',
            localCorePath: Env::get('MODX_TESTBENCH_CORE_PATH'),
            database: DatabaseConfig::fromEnvironment(),
            admin: AdminConfig::fromEnvironment(),
            cacheDir: Env::get('MODX_TESTBENCH_CACHE_DIR') ?? self::defaultCacheDir(),
            workspaceDir: Env::get('MODX_TESTBENCH_WORKSPACE'),
            forceInstall: Env::bool('MODX_TESTBENCH_FORCE_INSTALL'),
        );
    }

    /**
     * The core provider chosen by the configuration. It lives here rather than in
     * {@see TestbenchKernel} because the environment fingerprint depends on the provider: assembling
     * its ingredients by hand next to the provider itself would mean diverging from it at the very
     * first edit.
     */
    public function coreProvider(): CoreProvider
    {
        return match ($this->provider) {
            'zip' => new ZipReleaseProvider($this->version, $this->cacheDir),
            'git' => new GitCloneProvider($this->gitRef),
            'local' => new LocalPathProvider(
                $this->localCorePath
                    ?? throw new TestbenchException(
                        'Provider "local" requires MODX_TESTBENCH_CORE_PATH with a path to a MODX 3 distribution.'
                    )
            ),
            default => throw new TestbenchException(
                "Unknown core provider \"{$this->provider}\". Allowed values: zip, git, local."
            ),
        };
    }

    /**
     * The environment fingerprint (FR-ENV-1, FR-ENV-4): those and only those inputs the outcome of
     * the install depends on. An input that changes the install without changing the fingerprint
     * leaves the tests running against an environment installed WITH DIFFERENT parameters; a
     * superfluous input in the fingerprint forces pointless reinstalls.
     *
     * The DBMS and admin credentials belong here because all of them go into `setup/config.xml` and
     * determine the outcome of the install. Without them an environment installed by one DBMS user
     * was reused under another: the core took the credentials from the installed `config.inc.php`
     * and ignored the environment forever, while the snapshot layer read them from the environment —
     * "isolation by swapping the password" looked as if it worked while being nothing of the kind.
     *
     * The provider part comes from the provider itself (`CoreProvider::fingerprint()`) rather than
     * being assembled here out of every field at once: the release version matters only for `zip`,
     * the branch only for `git`, the path to the distribution only for `local`.
     *
     * Twelve hexadecimal characters are part of the interface: the fingerprint is used as a
     * component of the directory name and is suitable as part of a MySQL database name.
     */
    public function fingerprint(): string
    {
        $parts = [
            $this->providerFingerprint(),
            $this->database->host,
            (string) $this->database->port,
            $this->database->name,
            $this->database->user,
            $this->database->password,
            $this->database->prefix,
            $this->database->charset,
            $this->database->collation,
            $this->admin->user,
            $this->admin->password,
            $this->admin->email,
        ];

        return substr(hash('sha256', implode('|', $parts)), 0, 12);
    }

    /**
     * A configuration for which no provider can be built (an unknown name, `local` without a path)
     * must yield a fingerprint all the same: it is computed BEFORE any diagnostics —
     * {@see Workspace::forConfig()} builds the directory path from it, and the `status` command
     * simply prints the state. The real cause of the failure is named by preparing the environment,
     * where the provider is genuinely needed.
     */
    private function providerFingerprint(): string
    {
        try {
            return $this->coreProvider()->fingerprint();
        } catch (TestbenchException) {
            return 'invalid:' . $this->provider;
        }
    }

    private static function defaultCacheDir(): string
    {
        $base = Env::get('XDG_CACHE_HOME') ?? (Env::get('HOME') ?? sys_get_temp_dir()) . '/.cache';

        return rtrim($base, '/') . '/modx-testbench';
    }
}

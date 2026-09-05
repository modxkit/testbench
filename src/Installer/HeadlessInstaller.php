<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Installer;

use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Exception\InstallationFailedException;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\FilePermissions;
use ModxKit\Testbench\Support\ProcessResult;
use ModxKit\Testbench\Support\ProcessRunner;
use ModxKit\Testbench\Support\Secret;

/**
 * @internal
 */
final readonly class HeadlessInstaller
{
    /**
     * modInstallCLIRequest::end() (setup/includes/request/modinstallclirequest.class.php:344-347) terminates
     * every CLI run — success and failure alike — via `die($message)`. Passing a string to die()/exit() always
     * yields exit code 0, so the process exit code cannot distinguish a real failure. The actual failure signal
     * is the rendered lexicon text passed to end(), e.g. lexicon key `cli_no_config_file` at line 88
     * ("MODX could not find a configuration file ..."), `cli_tests_failed` at line 104 ("Pre-Install Tests
     * Failed! ..."), `cli_install_failed` at line 137 ("Installation Failed! ..."), `xpdo_err_ins` at lines
     * 281 and 306 ("Could not instantiate xPDO."), `db_err_create_database` at line 302 ("MODX could not
     * create your database. ..."), `db_err_connect_server` at line 310 and `db_err_connect` at line 314
     * (both "Could not connect to the database..."), and `test_table_prefix_inuse`/`test_table_prefix_nf`
     * at lines 331/333 (rendered strings taken from setup/lang/en/default.inc.php, since config.xml pins
     * `language=en`). xPDO's own error/fatal log lines (xPDO.php:2094 and :2076) are formatted as
     * `(ERROR)`/`(FATAL)`, not `[ERROR]`.
     */
    private const FAILURE_MARKERS = [
        '(FATAL)',
        '(ERROR)',
        'Fatal error',
        'MODX could not create your database',
        'MODX could not find a configuration file',
        'Could not connect to the database',
        'Could not instantiate xPDO',
        'Pre-Install Tests Failed!',
        'Installation Failed!',
        'Table prefix is already in use',
        'Table prefix does not exist',
        // Driver failures (`setup/includes/drivers/modinstalldriver_mysql.class.php:165,170`, the
        // `mysql_version_fail` and `mysql_version_5051` lexicon entries): the substrings are taken
        // without the version placeholder.
        'requires MySQL 4.1.20 or later',
        'because of the many bugs related to the PDO drivers',
    ];

    public function __construct(
        private ProcessRunner $runner = new ProcessRunner(),
        private ConfigXmlWriter $writer = new ConfigXmlWriter(),
        private CorePreparer $preparer = new CorePreparer(),
    ) {
    }

    public function install(CoreLocation $core, TestbenchConfig $config): void
    {
        $unpacked = $this->preparer->unpackCoreTransport($core);
        $configFile = $this->writer->write(
            InstallConfig::forCore($core, $config, $unpacked),
            $core->setupPath() . 'config.xml'
        );

        $command = [
            PHP_BINARY,
            $core->setupPath() . 'index.php',
            '--installmode=new',
            '--core_path=' . $core->corePath(),
            '--config=' . $configFile,
        ];

        $result = $this->runner->run($command, $core->rootPath, 900);

        // The output is handed over RAW, and the passwords as a separate list. `Secret::mask()` is
        // a plain `str_replace`, and while it was applied to the output BEFORE the search for
        // FAILURE_MARKERS, a password that happened to be a substring of a marker erased the marker
        // itself: `DB_PASS=Failed` turned "Installation Failed!" into "Installation ***!", and a
        // failed install passed as a successful one. Only the exception text is masked now.
        $this->evaluateOutcome(
            $result,
            $result->output(),
            $core->corePath() . 'config/config.inc.php',
            $command,
            $configFile,
            $config->database->password,
            $config->admin->password
        );

        // The install succeeded — the manifest is needed by NOBODY any more: the core reads it
        // exactly once, at the very beginning of the CLI run
        // (`setup/includes/request/modinstallclirequest.class.php:86` → `getConfig()` →
        // `loadConfigFile()`), and it lies there with the database and admin passwords in clear
        // text. On a FAILURE the file stays: it is the main diagnostic artefact, referred to
        // directly by the exception text (see `InstallationFailedException`), and the environment
        // will be reinstalled or wiped in full anyway.
        $this->removeManifest($configFile);

        // `core/config/config.inc.php` duplicates the database password but is needed by the core
        // at all times — it cannot be deleted, so the permissions are narrowed instead. MODX creates
        // the file with the default permissions (0644).
        $this->restrictCoreConfig($core->corePath() . 'config/config.inc.php');
    }

    private function removeManifest(string $configFile): void
    {
        if (is_file($configFile) && !unlink($configFile)) {
            throw new TestbenchException(
                "Failed to delete the installation manifest {$configFile} after a successful "
                . 'installation. The file holds the database and admin passwords in clear text — '
                . 'delete it by hand and check the permissions on the setup/ directory of the '
                . 'working environment.'
            );
        }
    }

    /**
     * A failure does not undo a successful install (see {@see FilePermissions}). There is nothing
     * to report it to the caller with from here, and no need: the permissions are checked by the
     * facts rather than by a memory of the install —
     * {@see \ModxKit\Testbench\Environment\Workspace::exposedSecretFiles()}.
     */
    private function restrictCoreConfig(string $configIncFile): void
    {
        FilePermissions::restrict(
            $configIncFile,
            0o600,
            'core/config/config.inc.php holds the database password in clear text.'
        );
    }

    /**
     * Pure decision logic for the three success gates documented in FAILURE_MARKERS above and in
     * MODX_HEADLESS_INSTALL.md §6: exit code, output markers, and a genuine (non-stub) config.inc.php.
     * Kept separate from install() so it can be unit-tested with hand-built ProcessResult instances and
     * temp files, without running a real installer or a database.
     *
     * `$rawOutput` is the installer's output AS IS: the decision is made from it, while masking is
     * applied only to the exception text. `$secrets` is what to hide in that text.
     *
     * @param array<int, string> $command
     */
    private function evaluateOutcome(
        ProcessResult $result,
        string $rawOutput,
        string $configIncFile,
        array $command,
        string $configFile,
        string ...$secrets,
    ): void {
        if (!$result->isSuccessful()) {
            throw $this->failure(
                $command,
                $configFile,
                $rawOutput,
                $secrets,
                'the installer exited with code ' . $result->exitCode
            );
        }

        foreach (self::FAILURE_MARKERS as $marker) {
            if (str_contains($rawOutput, $marker)) {
                // The marker is a constant of the package, not material from the output, so it is
                // quoted in the reason as is: masking it would mean hiding the name of the detector
                // that fired exactly where it is needed.
                throw $this->failure(
                    $command,
                    $configFile,
                    $rawOutput,
                    $secrets,
                    "the output carries the failure marker \"{$marker}\""
                );
            }
        }

        if (!is_file($configIncFile)) {
            throw $this->failure(
                $command,
                $configFile,
                $rawOutput,
                $secrets,
                'the file core/config/config.inc.php was not created'
            );
        }

        // On an unsuccessful run MODX creates core/config/config.inc.php all the same, but it is an
        // empty "MODX configuration file" comment stub without the MODX_CORE_PATH constant — a check
        // for the mere existence of the file does not catch such a failure.
        $configIncContents = file_get_contents($configIncFile);

        if ($configIncContents === false || !str_contains($configIncContents, 'MODX_CORE_PATH')) {
            throw $this->failure(
                $command,
                $configFile,
                $rawOutput,
                $secrets,
                'the file core/config/config.inc.php was created but holds no working configuration (looks like an empty template)'
            );
        }
    }

    /**
     * The only place where the installer's output turns into text for a human — and the only place
     * where it is masked.
     *
     * @param array<int, string>       $command
     * @param array<array-key, string> $secrets
     */
    private function failure(
        array $command,
        string $configFile,
        string $rawOutput,
        array $secrets,
        string $reason,
    ): InstallationFailedException {
        return InstallationFailedException::forCommand(
            $command,
            $configFile,
            Secret::mask($rawOutput, ...$secrets),
            $reason
        );
    }
}

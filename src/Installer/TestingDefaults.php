<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Installer;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\TestbenchException;
use PDO;
use PDOException;

/**
 * The core settings the test environment must have from the very start.
 *
 * They are applied right after the install and BEFORE the baseline is captured, so they make it into
 * the snapshot and survive any restore. An in-memory change (`modX::setOption()`) is no good for
 * that: `modX::reloadConfig()` — and it is called by at least 20 regular core processors — re-reads
 * `$modx->config` from the database and brings the original value back.
 *
 * @internal
 */
final class TestingDefaults
{
    /**
     * `log_deprecated`: `modX::deprecated()` accumulates marks about deprecated API use and saves
     * them from a `register_shutdown_function()`, that is, AFTER the last `tearDown()`, past the
     * transaction, past the snapshot and past any other isolation. A value of `0` extinguishes the
     * write on the very first line of the method (`core/src/Revolution/modX.php:2482`).
     *
     * `cache_db` (FR-ISO-6) is DELIBERATELY not added here: a fresh MODX install leaves it empty,
     * that is, false, anyway, and writing `0` would change nothing observable — no test would be
     * able to kill it. The protection against an environment where the cache is switched on after
     * all stands where it works independently of the database: `KernelBootstrapper` switches it off
     * on every boot of the core.
     *
     * @var array<string, string>
     */
    private const SETTINGS = ['log_deprecated' => '0'];

    public function apply(DatabaseConfig $database): void
    {
        try {
            $pdo = new PDO(
                $database->dsn(),
                $database->user,
                $database->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $table = '`' . str_replace('`', '``', $database->prefix . 'system_settings') . '`';
            $update = $pdo->prepare("UPDATE {$table} SET value = ? WHERE `key` = ?");
            $read = $pdo->prepare("SELECT value FROM {$table} WHERE `key` = ?");

            foreach (self::SETTINGS as $key => $value) {
                $update->execute([$value, $key]);

                // `rowCount()` proves nothing here: MySQL counts changed rows, and zero means both
                // "the key is absent" and "the value is already that". So we read back: that tells a
                // silent no-op on a missing key apart from a successful write.
                $read->execute([$key]);
                $stored = $read->fetchColumn();

                if ($stored === false) {
                    throw new TestbenchException(sprintf(
                        'System setting "%s" is absent from the installed core, so the test '
                        . "environment cannot switch it off.\n"
                        . 'The setting is part of the MODX 3 core transport package, and its absence '
                        . 'means the installed core version diverges from what the package expects. '
                        . 'Reinstalling will not help here — it would lead to exactly the same '
                        . 'result; check MODX_TESTBENCH_VERSION and, if the core version is '
                        . 'supported, open an issue: the list of settings in '
                        . 'ModxKit\\Testbench\\Installer\\TestingDefaults needs fixing.',
                        $key
                    ));
                }

                if ((string) $stored !== $value) {
                    throw new TestbenchException(sprintf(
                        'Failed to switch off system setting "%s": after the write the database '
                        . 'still holds "%s" instead of "%s". Check the privileges of user "%s" to '
                        . 'write to table %ssystem_settings.',
                        $key,
                        (string) $stored,
                        $value,
                        $database->user,
                        $database->prefix
                    ));
                }
            }
        } catch (PDOException $exception) {
            throw new TestbenchException(
                sprintf(
                    'Failed to apply the test core settings in database "%s": %s. '
                    . 'Check the privileges of user "%s" to write to table %ssystem_settings.',
                    $database->name,
                    $exception->getMessage(),
                    $database->user,
                    $database->prefix
                ),
                0,
                $exception
            );
        }
    }
}

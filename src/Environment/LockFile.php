<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

/**
 * @internal
 */
final readonly class LockFile
{
    /**
     * The revision of the package's install behaviour.
     *
     * It is incremented whenever WHAT the install puts into the database and into the snapshot file
     * changes. A lock assembled by an earlier revision makes the environment not-installed
     * (`Workspace::isInstalledWith()`), that is, leads to an honest reinstall — FR-ENV-4 exists for
     * exactly this.
     *
     * There is nothing to migrate such an environment "in place" with: there is nothing to check
     * integrity against (revision 0 recorded no table counts), and it cannot be restored from its
     * own snapshot — revision 0 snapshots carry no completion marker. And recapturing on an
     * unverified database cements any corruption and destroys the only serviceable baseline.
     *
     * Revision 0 is everything installed before the revision key existed (there is no key in the
     * lock at all).
     * Revision 1 is the snapshot completion marker, the table count in the lock (FR-ENV-6) and
     * `log_deprecated = 0` in the install itself.
     * Revision 2 is the snapshot format in the lock. It is itself the migration mechanism for
     * revision 1 environments: there is nothing to guess what the baseline on disk was captured with
     * — a mysqldump snapshot and a php snapshot are outwardly indistinguishable in exactly what
     * matters (`DELIMITER`, views and triggers appear only when the database has any), and a wrong
     * choice costs a wiped database. So such an environment is reinstalled honestly.
     */
    public const CURRENT_REVISION = 2;

    public function __construct(
        public string $fingerprint,
        public string $modxVersion,
        public string $provider,
        public string $tablePrefix,
        public string $installedAt,
        public bool $hasSnapshot,
        /**
         * How many tables with the configured prefix the install produced. Without this number
         * "installed" meant merely the presence of files, and a wiped core table went unnoticed.
         * Zero means "the environment predates this check" — it will be filled in on the first
         * preparation.
         */
        public int $tableCount = 0,
        /**
         * What the baseline was captured with: {@see \ModxKit\Testbench\Database\PhpDumper::FORMAT}
         * or {@see \ModxKit\Testbench\Database\MysqlDumper::FORMAT}. The restore strategy is chosen
         * by this field rather than by what turned up in PATH. An empty string means the lock was
         * assembled by a revision that knew no format.
         */
        public string $snapshotFormat = '',
        public int $installRevision = self::CURRENT_REVISION,
    ) {
    }

    /**
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'modx_version' => $this->modxVersion,
            'provider' => $this->provider,
            'table_prefix' => $this->tablePrefix,
            'installed_at' => $this->installedAt,
            'has_snapshot' => $this->hasSnapshot,
            'table_count' => $this->tableCount,
            'snapshot_format' => $this->snapshotFormat,
            'install_revision' => $this->installRevision,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fingerprint: self::stringValue($data['fingerprint'] ?? null),
            modxVersion: self::stringValue($data['modx_version'] ?? null),
            provider: self::stringValue($data['provider'] ?? null),
            tablePrefix: self::stringValue($data['table_prefix'] ?? null),
            installedAt: self::stringValue($data['installed_at'] ?? null),
            hasSnapshot: (bool) ($data['has_snapshot'] ?? false),
            tableCount: self::intValue($data['table_count'] ?? null),
            snapshotFormat: self::stringValue($data['snapshot_format'] ?? null),
            // A missing key means a lock assembled before revisions existed.
            installRevision: self::intValue($data['install_revision'] ?? null),
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The same lock but with a freshly captured baseline: the format is recorded together with the
     * flag, because the snapshot must be read by the same strategy that captured it.
     */
    public function withSnapshot(string $format): self
    {
        return new self(
            fingerprint: $this->fingerprint,
            modxVersion: $this->modxVersion,
            provider: $this->provider,
            tablePrefix: $this->tablePrefix,
            installedAt: $this->installedAt,
            hasSnapshot: true,
            tableCount: $this->tableCount,
            snapshotFormat: $format,
            installRevision: $this->installRevision,
        );
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Database;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use ModxKit\Testbench\Support\CommandRunner;
use ModxKit\Testbench\Support\ProcessResult;
use ModxKit\Testbench\Support\ProcessRunner;
use ModxKit\Testbench\Support\Secret;

/**
 * A database snapshot taken with the external `mysqldump`/`mysql` clients. Unlike {@see PhpDumper}
 * it carries over views and triggers as well, but it is available only where the clients are
 * installed — see {@see self::isAvailable()}.
 *
 * The credentials are passed not as command-line arguments but through a temporary options file
 * (`--defaults-extra-file`): arguments are visible in the process list to any user of the system,
 * and the MySQL client itself answers a `--password=` in the arguments with the warning
 * "Using a password on the command line interface can be insecure".
 *
 * @internal
 */
final readonly class MysqlDumper implements Dumper
{
    /** Extension of the temporary file before the `rename()` — the same as in {@see PhpDumper}. */
    private const PART_SUFFIX = '.part';

    /** Name of the snapshot format in `testbench.lock.json`. */
    public const FORMAT = 'mysql';

    /**
     * The client names are a parameter, not a constant. MariaDB 12 has no `mysqldump` and `mysql`
     * files at all: only `mariadb-dump` and `mariadb` are left (measured on the `mariadb:latest`
     * image, 12.3.2; 11.8.6 still has symlinks under the old names). A runner with such clients
     * looked to the package like a runner WITHOUT clients, and the snapshot silently went to
     * {@see PhpDumper} along with the loss of views and triggers.
     *
     * The pair is taken as a whole rather than assembled from two independent lookups: both files
     * come from one distribution package, and the package has never verified taking a snapshot with
     * one client family and restoring it with another. Which of the pairs is available is decided
     * by {@see SnapshotManager} — here there is only the execution of the chosen one.
     *
     * @param string $dumpBinary   the client that takes the dump
     * @param string $clientBinary the client that executes SQL: cleaning the database and reading
     *                             the snapshot
     */
    public function __construct(
        private CommandRunner $runner = new ProcessRunner(),
        private string $dumpBinary = 'mysqldump',
        private string $clientBinary = 'mysql',
    ) {
    }

    public function format(): string
    {
        return self::FORMAT;
    }

    /**
     * The strategy is available if BOTH clients start: one takes the snapshot, the other restores
     * it.
     *
     * We check by starting them (`--version`), not by the presence of a file (`which`): a file that
     * was found does not yet mean a working client — a library did not load, a symlink is broken, a
     * wrapper refuses. That differs only in the exit code, and the price of a mistake is high: the
     * snapshot would be taken by one strategy with nothing able to read it.
     *
     * A check by STARTING is what it stays, and that decision is measured, not deduced. It does not
     * catch the case where a client starts but does not understand an option from the options file;
     * the obvious attempt to catch it — a trial `--version` WITH the options file — would catch it
     * unreliably on exactly those builds it would be introduced for. Measured with an options file
     * where `init-command` sits in the `[client]` group; no DBMS is needed for this — the file is
     * parsed before connecting:
     *   mysqldump 8.0.46 — exit code 7, message on stderr;
     *   mariadb-dump 10.6.28, 10.11.18, 12.3.2 — exit code 0, message on stderr;
     *   mysqldump 8.4.11 — exit code 0, no message.
     * That is, by exit code the refusal is visible only on Oracle 8.0; on MariaDB clients one would
     * have to parse stderr and tell "unknown option" apart from any other warning.
     */
    public function isAvailable(): bool
    {
        return $this->runner->run([$this->dumpBinary, '--version'])->isSuccessful()
            && $this->runner->run([$this->clientBinary, '--version'])->isSuccessful();
    }

    /**
     * Like {@see PhpDumper::dump()}, it writes to a temporary file and moves it into place with a
     * single `rename()` — after the completion marker has been appended to the tail.
     * `--result-file` writes in place, so a `mysqldump` killed mid-run would otherwise leave a
     * truncated file exactly where the guards look for the snapshot.
     */
    public function dump(DatabaseConfig $database, string $file): void
    {
        $temporary = $file . self::PART_SUFFIX;

        $this->withDefaultsFile($database, function (string $defaults) use ($database, $temporary): void {
            $result = $this->runner->run([
                $this->dumpBinary,
                '--defaults-extra-file=' . $defaults,
                '--single-transaction',
                '--add-drop-table',
                // Without this, mysqldump 8 reads INFORMATION_SCHEMA.FILES and requires the
                // PROCESS privilege on the whole server — for an account with privileges only on
                // the test database, taking a snapshot would fail outright.
                '--no-tablespaces',
                '--result-file=' . $temporary,
                $database->name,
            ]);

            if (!$result->isSuccessful()) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }

                throw SnapshotFailedException::because(
                    'mysqldump',
                    Secret::mask($result->output(), $database->password)
                );
            }

            $tables = $this->listTables($database, $defaults);

            if (file_put_contents($temporary, SnapshotFile::completionLine(count($tables)), FILE_APPEND) === false) {
                unlink($temporary);

                throw SnapshotFailedException::because(
                    'mysqldump',
                    "failed to append the completion marker to {$temporary} — check free disk space"
                );
            }
        });

        if (!rename($temporary, $file)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw SnapshotFailedException::because(
                'mysqldump',
                "failed to move {$temporary} to {$file}. Check permissions on the snapshot directory."
            );
        }
    }

    public function load(DatabaseConfig $database, string $file): void
    {
        // The check comes BEFORE dropExistingTables(): a snapshot without a completion marker is
        // left over from an interrupted capture(), and cleaning the database for it would mean
        // wiping the database and restoring it only in part.
        SnapshotFile::assertComplete($file, 'mysql');

        $this->withDefaultsFile($database, function (string $defaults) use ($database, $file): void {
            $this->dropExistingObjects($database, $defaults);

            // The mysql client reads a dump only from standard input, so the redirection has to
            // be done by a shell. There is not a single secret in the command line: the password
            // lies in the options file, the remaining arguments are escaped — including the client
            // name itself, which is now a parameter of the strategy rather than a literal.
            $command = sprintf(
                '%s --defaults-extra-file=%s %s < %s',
                escapeshellarg($this->clientBinary),
                escapeshellarg($defaults),
                escapeshellarg($database->name),
                escapeshellarg($file)
            );

            $this->expectSuccess($this->runner->run(['sh', '-c', $command]), $database);
        });
    }

    /**
     * Removes everything currently in the database: a mysqldump dump recreates its own objects
     * itself (`--add-drop-table`), but objects created AFTER the snapshot would survive the
     * restore — a transaction does not undo `CREATE TABLE`, which is what snapshots exist for.
     *
     * Views are dropped on a par with tables. A listing filtered by `Table_type = 'BASE TABLE'`
     * left them alive — and `DROP TABLE` does not touch a view: afterwards it either kept answering
     * with data absent from the baseline or, having a name matching a table of the snapshot, broke
     * `CREATE TABLE` in the middle of the restore. Triggers need no separate step: a trigger lives
     * with its table and goes away together with it.
     */
    private function dropExistingObjects(DatabaseConfig $database, string $defaults): void
    {
        $objects = $this->listObjects($database, $defaults);
        $statements = [];

        // Views are dropped first: otherwise a view over a table of the snapshot would get in the
        // way of recreating it.
        foreach (['VIEW' => 'DROP VIEW IF EXISTS ', 'BASE TABLE' => 'DROP TABLE IF EXISTS '] as $type => $drop) {
            $names = array_map(
                static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`',
                $objects[$type] ?? []
            );

            if ($names !== []) {
                $statements[] = $drop . implode(', ', $names) . ';';
            }
        }

        if ($statements === []) {
            return;
        }

        $this->expectSuccess($this->runner->run([
            $this->clientBinary,
            '--defaults-extra-file=' . $defaults,
            '--execute=SET FOREIGN_KEY_CHECKS=0; ' . implode(' ', $statements) . ' SET FOREIGN_KEY_CHECKS=1;',
            $database->name,
        ]), $database);
    }

    /**
     * What lies in the database right now, split by type (`BASE TABLE`, `VIEW`).
     *
     * @return array<string, list<string>>
     */
    private function listObjects(DatabaseConfig $database, string $defaults): array
    {
        $listing = $this->runner->run([
            $this->clientBinary,
            '--defaults-extra-file=' . $defaults,
            '--batch',
            '--skip-column-names',
            '--execute=SHOW FULL TABLES',
            $database->name,
        ]);

        $this->expectSuccess($listing, $database);

        $objects = [];

        foreach (explode("\n", $listing->stdout) as $line) {
            // The --batch format: object name, tab, type.
            $parts = explode("\t", $line);
            $name = trim($parts[0]);

            if ($name !== '') {
                $objects[trim($parts[1] ?? '')][] = $name;
            }
        }

        return $objects;
    }

    /**
     * Base tables only — their count goes into the completion marker of the snapshot.
     *
     * @return list<string>
     */
    private function listTables(DatabaseConfig $database, string $defaults): array
    {
        return $this->listObjects($database, $defaults)['BASE TABLE'] ?? [];
    }

    private function expectSuccess(ProcessResult $result, DatabaseConfig $database): void
    {
        if (!$result->isSuccessful()) {
            throw SnapshotFailedException::because(
                'mysql',
                Secret::mask($result->output(), $database->password)
            );
        }
    }

    /**
     * Creates an options file with mode 0600, hands its path to the callback and removes the file
     * on any outcome.
     *
     * @param callable(string): void $run
     */
    private function withDefaultsFile(DatabaseConfig $database, callable $run): void
    {
        $defaults = tempnam(sys_get_temp_dir(), 'modx-testbench-my');

        if ($defaults === false) {
            throw SnapshotFailedException::because('mysql', 'failed to create the temporary MySQL options file');
        }

        // tempnam() creates the file with mode 0600, but that cannot be relied upon: the umask and
        // the implementation differ across operating systems, and the file holds a password.
        if (!chmod($defaults, 0600)) {
            unlink($defaults);

            throw SnapshotFailedException::because(
                'mysql',
                "failed to restrict permissions on the temporary options file {$defaults}"
            );
        }

        // Two groups, deliberately, and the boundary between them is measured rather than deduced
        // from the documentation.
        //
        // `default-character-set` stays in [client]: the connection charset is the consumer setting
        // MODX_TESTBENCH_DB_CHARSET, BOTH clients are obliged to honour it, and both know the
        // option. Without it the snapshot would be taken and restored in the client's default
        // charset rather than in the configured one (the PDO strategy takes it from the DSN).
        //
        // `init-command` moved to [mysql]: that group is read by the `mysql` client, while
        // `mysqldump` reads [client] and [mysqldump] and never reaches it. An earlier comment here
        // claimed that init-command is "read by both mysql and mysqldump (a client library
        // option)"; that is untrue, and it cost consumers the install in full. Measured against a
        // MySQL 8.4.11 server with an options file where init-command sits in [client]:
        //   taking a snapshot — mariadb-dump 10.6.28, 10.11.18, 12.3.2 and mysqldump 8.0.46 answer
        //     "unknown variable 'init-command=…'" with exit code 7, before even connecting to the
        //     DBMS; mysqldump 8.4.11 accepts and executes it (verified by a side effect: an
        //     init-command with CREATE TABLE creates the table). Four builds out of five;
        //   restoring — mariadb 10.6.28, 10.11.18, 12.3.2 and mysql 8.0.46, 8.4.11 accept and
        //     execute it (SELECT @@SESSION.lock_wait_timeout returns 30). Five builds out of five.
        // That is, the option in [client] killed snapshot taking on FOUR client builds out of the
        // five measured, and that is both lines at once — MariaDB and Oracle 8.0. About unmeasured
        // builds of those lines there is nothing to claim. Both lines occur under the names
        // `mysql`/`mysqldump`: Debian's `default-mysql-client` installs MariaDB clients (measured on
        // debian:stable — 11.8.6-MariaDB, `/usr/bin/mysqldump` a symlink to `mariadb-dump`),
        // Ubuntu's `mysql-client` an Oracle client (measured on the `ubuntu:24.04` image —
        // `apt-get install mysql-client` followed by `mysqldump --version` prints
        // `mysqldump Ver 8.0.46-0ubuntu0.24.04.4`). Re-checkable in CI in the step "the
        // mysql/mysqldump clients must be present on the runner" of any `integration` job; no run
        // id is quoted on purpose — the public history is restarted at release and the pointer
        // would dangle. The runner has Oracle installed, and that is exactly where taking a
        // snapshot failed.
        //
        // The guarantee is not lost by the move: it is about CLEANING the database — a `DROP TABLE`
        // that runs into the metadata lock of somebody else's transaction waits for
        // lock_wait_timeout, by default 31,536,000 seconds, that is, a year. The database is cleaned
        // and the snapshot restored by the `mysql` client, and it does get the cap: see the test
        // MysqlDumperMariadbClientTest::testRestoringMariadbClientCapsTheMetadataLockWait, which
        // reads the value from the client's own connection.
        //
        // Taking a snapshot (`mysqldump`) runs with the server default from this commit on: there is
        // no way to give it a lock_wait_timeout that would not break four builds out of the five
        // measured. The scenario the cap was introduced for does not concern taking a snapshot —
        // measured: `mysqldump --single-transaction` under somebody else's UNCLOSED transaction that
        // had read the same table finished in 0.5 seconds. What remains is concurrent DDL (an
        // exclusive metadata lock) — in that case taking a snapshot waits for the server default;
        // this is NOT MEASURED and is recorded in docs/SPEC.md as a known boundary.
        $contents = sprintf(
            "[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\ndefault-character-set=%s\n"
            . "\n[mysql]\ninit-command=%s\n",
            $this->quoteOption($database->host),
            $database->port,
            $this->quoteOption($database->user),
            $this->quoteOption($database->password),
            $this->quoteOption($database->charset),
            $this->quoteOption('SET SESSION lock_wait_timeout = ' . PhpDumper::LOCK_WAIT_TIMEOUT_SECONDS)
        );

        if (file_put_contents($defaults, $contents) === false) {
            unlink($defaults);

            throw SnapshotFailedException::because('mysql', "failed to write the options file {$defaults}");
        }

        try {
            $run($defaults);
        } finally {
            unlink($defaults);
        }
    }

    /**
     * An option value in a MySQL options file: quotes and backslashes are escaped, a newline is
     * written as an escape sequence — otherwise the value would be cut off halfway.
     */
    private function quoteOption(string $value): string
    {
        return '"' . str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        ) . '"';
    }
}

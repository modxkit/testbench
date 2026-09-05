<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Database;

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\SnapshotFailedException;

/**
 * The second line of test isolation: a snapshot of the database into a file and a restore from it.
 *
 * A transaction with a rollback is powerless where MySQL performs an implicit commit — on DDL
 * (`CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`) and on MyISAM tables. A snapshot undoes such
 * changes, but costs an order of magnitude more, so it is captured once per run.
 *
 * A restore returns the database to the state of the snapshot in full: the snapshot's tables are
 * recreated, and those created after the capture are dropped, see {@see Dumper}.
 *
 * @internal
 */
final readonly class SnapshotManager
{
    private Dumper $dumper;

    /**
     * @param string $recordedFormat the snapshot format recorded in `testbench.lock.json`; an empty
     *                               string means the format has not been chosen yet
     */
    public function __construct(
        private DatabaseConfig $database,
        private string $snapshotFile,
        ?Dumper $dumper = null,
        string $recordedFormat = '',
    ) {
        $this->dumper = $dumper ?? $this->pickDumper($recordedFormat);
    }

    public function path(): string
    {
        return $this->snapshotFile;
    }

    /**
     * The format the chosen strategy works in. The install writes it into the lock so that the next
     * process reads the snapshot with the same thing that captured it.
     */
    public function format(): string
    {
        return $this->dumper->format();
    }

    /**
     * Only a file with a completion marker in its tail counts as a snapshot: restoring from the
     * truncated remains of an interrupted `capture()` would destroy the database — see
     * {@see SnapshotFile}.
     *
     * The check is static because `TestbenchKernel` uses it too, and it must not construct the
     * manager for the sake of a single `is_file()`: through `pickDumper()` the constructor spawns a
     * `mysqldump` availability subprocess.
     */
    public function exists(): bool
    {
        return SnapshotFile::isComplete($this->snapshotFile);
    }

    public function capture(): void
    {
        $this->dumper->dump($this->database, $this->snapshotFile);
    }

    public function restore(): void
    {
        $this->dumper->load($this->database, $this->snapshotFile);
    }

    /**
     * The strategy is chosen by the ORIGIN of the snapshot, not by what turned up in PATH.
     *
     * PATH differs between a terminal, an IDE, `make` and CI, and the formats are not
     * interchangeable: a `mysqldump` dump with a trigger handed to {@see PhpDumper} stumbles over
     * the client-side `DELIMITER` command already AFTER the database has been cleaned — the
     * environment is left half dismantled, and an exception from `tearDown()` fails every following
     * test of the run.
     */
    private function pickDumper(string $recordedFormat): Dumper
    {
        // The format is not recorded yet: the environment is either being installed right now or
        // was deployed by a revision that knew no format. We take the best of what is available —
        // that is what the snapshot will be captured with, and that format will be recorded.
        if ($recordedFormat === '') {
            return $this->availableClientDumper() ?? new PhpDumper();
        }

        if ($recordedFormat === PhpDumper::FORMAT) {
            return new PhpDumper();
        }

        if ($recordedFormat !== MysqlDumper::FORMAT) {
            throw SnapshotFailedException::because('mysql', sprintf(
                'testbench.lock.json records an unknown snapshot format "%s" — there is nothing to '
                . 'read the snapshot with. Recreate the environment (MODX_TESTBENCH_FORCE_INSTALL=1)',
                $recordedFormat
            ));
        }

        $mysqldump = $this->availableClientDumper();

        if (!$mysqldump instanceof MysqlDumper) {
            // The path to the snapshot is deliberately absent from the message: it goes through
            // `Secret::mask()`, and the package's regular password ("testbench") is a substring of
            // both the environment directory and the snapshot file name, so the path would reach the
            // reader mutilated. Where the environment lies is told by the `status` command.
            throw SnapshotFailedException::because(
                'mysql',
                'the baseline was captured with the mysqldump client, and there are no '
                . 'mysqldump/mysql (or mariadb-dump/mariadb) clients in PATH right now. Such a '
                . 'snapshot cannot be read with the php strategy: it understands neither the '
                . 'client-side DELIMITER command nor views with triggers, and would have broken off '
                . 'AFTER the database had been cleaned. Nothing was touched — put the clients back '
                . 'into PATH or recreate the environment (MODX_TESTBENCH_FORCE_INSTALL=1)'
            );
        }

        return $mysqldump;
    }

    /**
     * The first working pair of clients, or `null` if there is none.
     *
     * There are two pairs, and the order in the list is a priority: first the historical Oracle
     * names (`mysqldump`/`mysql`), then the MariaDB names (`mariadb-dump`/`mariadb`). MariaDB 12 has
     * none of the former at all — only the latter (measured on the `mariadb:latest` image, 12.3.2;
     * 11.8.6 still has symlinks under the old names), and a runner with such clients looked to the
     * package like a runner WITHOUT clients: the snapshot silently went to the php strategy along
     * with the loss of views and triggers — exactly the kind of silent fallback that is forbidden.
     *
     * The pair is checked as a whole: the package has never verified mixing `mysqldump` with
     * `mariadb`, and in distributions both files come from one package.
     *
     * The price is one extra subprocess where the first pair is absent: `mysqldump --version` fails,
     * and only then is the second tried. Where the first pair is present (today that is every CI
     * runner) there are exactly as many subprocesses as there were.
     */
    private function availableClientDumper(): ?MysqlDumper
    {
        foreach ([['mysqldump', 'mysql'], ['mariadb-dump', 'mariadb']] as [$dump, $client]) {
            $dumper = new MysqlDumper(dumpBinary: $dump, clientBinary: $client);

            if ($dumper->isAvailable()) {
                return $dumper;
            }
        }

        return null;
    }
}

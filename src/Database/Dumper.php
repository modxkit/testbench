<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Database;

use ModxKit\Testbench\Environment\DatabaseConfig;

/**
 * The strategy for capturing and restoring a snapshot of the test database.
 *
 * `load()` must return the database to exactly the state of the snapshot: recreate the snapshot's
 * contents and remove everything that appeared AFTER the capture. Executing the dump does not give
 * that by itself (both `mysqldump --add-drop-table` and {@see PhpDumper} write a DROP only for what
 * was alive at the moment of the capture), so both strategies clean the database before loading —
 * and both remove not only tables but views as well: `DROP TABLE` does not touch a view, and a view
 * that survived a restore is state the snapshot does not have.
 *
 * Where the strategies differ is in the SCOPE of the snapshot: {@see MysqlDumper} carries views and
 * triggers over, {@see PhpDumper} only base tables. So after a restore the database repeats its own
 * snapshot equally precisely, but the strategies' snapshots differ — and a snapshot must be read by
 * the strategy that captured it (see {@see self::format()}).
 */
interface Dumper
{
    /**
     * The name of the format the strategy writes its snapshot in. It is recorded in
     * `testbench.lock.json` and decides which strategy reads the snapshot later: the formats are NOT
     * interchangeable. A `mysqldump` dump carries the client-side `DELIMITER` command, views and
     * triggers, which the {@see PhpDumper::statements()} parser does not know — and it stumbles over
     * them already AFTER the database has been cleaned.
     */
    public function format(): string;

    /**
     * Whether the strategy is workable here and now.
     */
    public function isAvailable(): bool;

    public function dump(DatabaseConfig $database, string $file): void;

    public function load(DatabaseConfig $database, string $file): void;
}

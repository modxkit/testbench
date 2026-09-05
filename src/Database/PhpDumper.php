<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Database;

use Generator;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use ModxKit\Testbench\Support\Secret;
use PDO;
use PDOException;

/**
 * A database snapshot taken through PDO — it works everywhere the package itself works, requiring
 * no external MySQL clients.
 *
 * Limitations of the strategy: only base tables (`BASE TABLE`) are captured — views, triggers,
 * procedures and events do not make it into the snapshot; for those {@see MysqlDumper} is needed.
 *
 * The behaviour of `load()` follows from this: it brings the database to what the snapshot
 * contains, that is, to base tables alone. A view created after the capture is dropped (otherwise
 * it would survive the restore, which the {@see Dumper} contract does not allow); one created
 * BEFORE the capture is dropped too and does not come back, because the snapshot does not carry
 * it. A database where views must survive a restore requires {@see MysqlDumper}.
 *
 * @internal
 */
final class PhpDumper implements Dumper
{
    private const ROWS_PER_INSERT = 200;

    /**
     * The threshold after which the accumulated rows are flushed into a separate INSERT even when
     * there are fewer of them than ROWS_PER_INSERT. A single statement must not run into
     * max_allowed_packet (64 MiB by default), and MODX rows can be large: the content of resources
     * and chunks.
     */
    private const BYTES_PER_INSERT = 4194304;

    /**
     * Extension of the temporary file the writing goes into before the `rename()`. A separate name
     * is needed so that an interrupted capture leaves nothing snapshot-like where the snapshot
     * belongs.
     */
    private const PART_SUFFIX = '.part';

    /**
     * How many seconds the dumper's connection waits for a metadata lock before refusing. See
     * `connect()`.
     */
    public const LOCK_WAIT_TIMEOUT_SECONDS = 30;

    /** Name of the snapshot format in `testbench.lock.json`. */
    public const FORMAT = 'php';

    public function format(): string
    {
        return self::FORMAT;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * The snapshot is written into a temporary `<path>.part` file and moved into place with a
     * single `rename()` only after the completion marker has reached the tail. The former
     * protection — an `unlink()` in `finally` — does not work where `finally` is not executed:
     * SIGKILL, a fatal error, OOM, a CI timeout. The comment in this very place used to name that
     * exact risk itself.
     */
    public function dump(DatabaseConfig $database, string $file): void
    {
        $directory = dirname($file);

        // Without this check, an fopen() on a non-existent directory would emit a PHP warning
        // instead of a diagnosable exception.
        if (!is_dir($directory) || !is_writable($directory)) {
            throw SnapshotFailedException::because(
                'php',
                "the snapshot directory is not writable: {$directory}"
            );
        }

        $pdo = $this->connect($database);
        $temporary = $file . self::PART_SUFFIX;
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw SnapshotFailedException::because('php', "failed to open {$temporary} for writing");
        }

        $failure = $this->writeSnapshot($pdo, $database, $handle, $temporary);

        // fwrite() hands the data to the stream, and it reaches the disk on a buffer flush: a
        // failure (out of space, a network volume dropped) is visible only here.
        if (!$failure instanceof SnapshotFailedException && !fflush($handle)) {
            $failure = SnapshotFailedException::because(
                'php',
                "failed to flush {$temporary} to disk — check free disk space"
            );
        }

        if (!fclose($handle) && !$failure instanceof SnapshotFailedException) {
            $failure = SnapshotFailedException::because(
                'php',
                "failed to close {$temporary} — part of the data may not have reached the disk"
            );
        }

        if ($failure instanceof SnapshotFailedException) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $failure;
        }

        if (!rename($temporary, $file)) {
            unlink($temporary);

            throw SnapshotFailedException::because(
                'php',
                "failed to move {$temporary} to {$file}. Check permissions on the snapshot directory."
            );
        }
    }

    /**
     * The body of the dump. It returns a failure instead of throwing it: the file has to be closed
     * and removed on any outcome, and `finally` is no good for that — a failure of the closing
     * itself would substitute for the real cause there.
     *
     * @param resource $handle
     */
    private function writeSnapshot(PDO $pdo, DatabaseConfig $database, $handle, string $file): ?SnapshotFailedException
    {
        try {
            $this->write($handle, $file, "SET FOREIGN_KEY_CHECKS=0;\n");

            $tables = $this->tables($pdo);

            foreach ($tables as $table) {
                $quoted = $this->quoteIdentifier($table);
                $create = $pdo->query("SHOW CREATE TABLE {$quoted}");
                $row = $create === false ? false : $create->fetch(PDO::FETCH_NUM);

                if (!is_array($row) || !isset($row[1]) || !is_string($row[1])) {
                    throw SnapshotFailedException::because(
                        'php',
                        "failed to read the structure of table {$table}"
                    );
                }

                $this->write($handle, $file, "DROP TABLE IF EXISTS {$quoted};\n" . $row[1] . ";\n");
                $this->writeRows($pdo, $handle, $file, $table);
            }

            $this->write($handle, $file, "SET FOREIGN_KEY_CHECKS=1;\n");
            $this->write($handle, $file, SnapshotFile::completionLine(count($tables)));

            return null;
        } catch (PDOException $exception) {
            // A DBMS failure in the middle of the dump (for example insufficient privileges on a
            // table) must not escape outwards as somebody else's exception type.
            return SnapshotFailedException::because(
                'php',
                Secret::mask($exception->getMessage(), $database->password),
                $exception
            );
        } catch (SnapshotFailedException $exception) {
            return $exception;
        }
    }

    public function load(DatabaseConfig $database, string $file): void
    {
        // The check comes BEFORE opening the connection and, more importantly, before
        // dropExistingTables(): a snapshot cut off on a statement boundary would otherwise wipe the
        // database and restore it in part without saying a word about it.
        SnapshotFile::assertComplete($file, 'php');

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw SnapshotFailedException::because('php', "failed to read {$file}");
        }

        $pdo = $this->connect($database);
        $number = 0;

        try {
            // Statements are executed one by one rather than as the whole file at once: this way
            // the size of a single packet stays predictable, and the diagnostics show the specific
            // statement the restore stumbled on.
            foreach ($this->statements($handle, $file) as $statement) {
                ++$number;

                if ($number === 1) {
                    // The cleanup happens exactly here rather than before parsing the file: a
                    // snapshot without a single statement must not leave the database empty.
                    $this->dropExistingObjects($pdo, $database);
                }

                try {
                    $pdo->exec($statement);
                } catch (PDOException $exception) {
                    throw SnapshotFailedException::because(
                        'php',
                        sprintf(
                            'statement #%d (%s) failed: %s. The database is left restored only in '
                            . 'part — capture the snapshot again or recreate the environment '
                            . '(MODX_TESTBENCH_FORCE_INSTALL=1)',
                            $number,
                            $this->summarize($statement),
                            Secret::mask($exception->getMessage(), $database->password)
                        ),
                        $exception
                    );
                }
            }
        } finally {
            fclose($handle);
        }

        if ($number === 0) {
            throw SnapshotFailedException::because('php', "snapshot {$file} contains no SQL statements at all");
        }
    }

    /**
     * Removes everything currently in the database. The snapshot recreates each of its tables in
     * full, and objects absent from it would otherwise survive the restore: `CREATE TABLE` and
     * `CREATE VIEW` are just as much an implicit commit as `DROP TABLE`, and a transaction does not
     * undo them.
     *
     * Views are dropped with their own statement (`DROP TABLE` does not touch them) and first: a
     * view over a table of the snapshot would otherwise get in the way of recreating it. They do
     * not make it into the snapshot, so they will not come back — that is a limitation of the
     * strategy, described in the class docblock, not an exception to the {@see Dumper} contract:
     * after a restore the database contains exactly what the snapshot contains.
     */
    private function dropExistingObjects(PDO $pdo, DatabaseConfig $database): void
    {
        try {
            $statements = [];

            foreach (['VIEW' => 'DROP VIEW IF EXISTS ', 'BASE TABLE' => 'DROP TABLE IF EXISTS '] as $type => $drop) {
                $names = array_map($this->quoteIdentifier(...), $this->objectsOfType($pdo, $type));

                if ($names !== []) {
                    $statements[] = $drop . implode(', ', $names);
                }
            }

            if ($statements === []) {
                return;
            }

            // The order of removal must not depend on the foreign keys extensions add between
            // their own tables.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            try {
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        } catch (PDOException $exception) {
            // A DBMS failure during the cleanup (insufficient DROP privileges, a locked table, a
            // foreign key conflict) must not escape outwards as somebody else's exception type.
            throw SnapshotFailedException::because(
                'php',
                sprintf(
                    'failed to clean database "%s" before restoring: %s. The snapshot was not '
                    . 'loaded, but some objects may already be dropped — make sure DROP TABLE and '
                    . 'DROP VIEW are allowed to user "%s", and recreate the environment '
                    . '(MODX_TESTBENCH_FORCE_INSTALL=1)',
                    $database->name,
                    Secret::mask($exception->getMessage(), $database->password),
                    $database->user
                ),
                $exception
            );
        }
    }

    /**
     * The tables that make it into the snapshot.
     *
     * SHOW FULL TABLES instead of SHOW TABLES: views must not get in here — for them SHOW CREATE
     * TABLE would return a CREATE VIEW, which DROP TABLE does not undo.
     *
     * @return array<int, string>
     */
    private function tables(PDO $pdo): array
    {
        return $this->objectsOfType($pdo, 'BASE TABLE');
    }

    /**
     * @param string $type `BASE TABLE` or `VIEW`
     *
     * @return array<int, string>
     */
    private function objectsOfType(PDO $pdo, string $type): array
    {
        // The type is interpolated into the SQL rather than bound as a parameter: it is a literal
        // from the call site, not a value from outside — both callers pass a constant.
        $statement = $pdo->query("SHOW FULL TABLES WHERE Table_type = '{$type}'");

        if ($statement === false) {
            throw SnapshotFailedException::because('php', "failed to list objects of type {$type}");
        }

        $names = [];

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            if (is_array($row) && isset($row[0]) && is_string($row[0])) {
                $names[] = $row[0];
            }
        }

        return $names;
    }

    /**
     * The columns of a table that can be inserted back.
     *
     * Generated columns (`STORED`/`VIRTUAL`) are not supplied in an `INSERT` — MySQL answers with
     * error 3105. So they must not be in the snapshot either: they recompute themselves from the
     * expression recorded in `CREATE TABLE`. The requirement to list the columns of an `INSERT`
     * explicitly comes from the same place: without a list, all of them are supplied.
     *
     * @return array<int, string>
     */
    private function insertableColumns(PDO $pdo, string $table): array
    {
        $statement = $pdo->query('SHOW FULL COLUMNS FROM ' . $this->quoteIdentifier($table));

        if ($statement === false) {
            throw SnapshotFailedException::because('php', "failed to read the columns of table {$table}");
        }

        $columns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !isset($row['Field']) || !is_string($row['Field'])) {
                continue;
            }

            $extra = isset($row['Extra']) && is_string($row['Extra']) ? $row['Extra'] : '';

            // For such columns `Extra` contains "STORED GENERATED" or "VIRTUAL GENERATED".
            if (str_contains($extra, 'GENERATED')) {
                continue;
            }

            $columns[] = $row['Field'];
        }

        return $columns;
    }

    /**
     * @param resource $handle
     */
    private function writeRows(PDO $pdo, $handle, string $file, string $table): void
    {
        $quoted = $this->quoteIdentifier($table);
        $columns = $this->insertableColumns($pdo, $table);

        // A table made of computed columns alone has nothing to insert and requires nothing: the
        // values will be restored by the expression from CREATE TABLE itself.
        if ($columns === []) {
            return;
        }

        $selectList = implode(',', array_map($this->quoteIdentifier(...), $columns));
        // The list for the INSERT is the same set, but in parentheses. In a SELECT the parentheses
        // would mean a row constructor rather than an enumeration of columns.
        $columnList = ' (' . $selectList . ')';
        $statement = $pdo->query("SELECT {$selectList} FROM {$quoted}");

        if ($statement === false) {
            throw SnapshotFailedException::because('php', "failed to read the data of table {$table}");
        }

        $buffer = [];
        $bytes = 0;

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                break;
            }

            $values = array_map(
                // PDO::quote() relies on mysql_real_escape_string, so binary values (null bytes
                // included) survive the dump without loss.
                static fn (mixed $value): string => $value === null
                    ? 'NULL'
                    : (string) $pdo->quote(self::stringify($value)),
                $row
            );

            $tuple = '(' . implode(',', $values) . ')';
            $buffer[] = $tuple;
            $bytes += strlen($tuple);

            if (count($buffer) >= self::ROWS_PER_INSERT || $bytes >= self::BYTES_PER_INSERT) {
                $this->write($handle, $file, "INSERT INTO {$quoted}{$columnList} VALUES " . implode(',', $buffer) . ";\n");
                $buffer = [];
                $bytes = 0;
            }
        }

        if ($buffer !== []) {
            $this->write($handle, $file, "INSERT INTO {$quoted}{$columnList} VALUES " . implode(',', $buffer) . ";\n");
        }
    }

    private static function stringify(mixed $value): string
    {
        if (is_float($value)) {
            // For a float, (string) truncates the value to the `precision` ini setting (14
            // significant digits by default); var_export() writes a representation that reads back
            // without loss.
            return var_export($value, true);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        // The MySQL driver hands back values as scalars; anything else (for example a BLOB stream
        // under PDO::ATTR_STRINGIFY_FETCHES with large objects) is better refused explicitly than
        // silently written into the snapshot as "Resource id #5".
        throw SnapshotFailedException::because(
            'php',
            'a value of type ' . get_debug_type($value) . ' is not supported by the php strategy'
        );
    }

    /**
     * Splits the file into individual SQL statements. A semicolon inside a string literal, a
     * backtick-quoted identifier or a comment does not count as a separator. The comments
     * themselves are not stripped: a MySQL conditional comment (opened by the "slash-asterisk" pair
     * followed by an exclamation mark) is an executable part of the statement.
     *
     * @param resource $handle
     *
     * @return Generator<int, string>
     */
    private function statements($handle, string $file): Generator
    {
        $current = '';
        $quote = null;
        $escaped = false;
        $blockComment = false;
        // A comment by itself is not a statement: without this flag a tail of the file such as
        // "-- captured on such-and-such a date" would go off for execution as a separate
        // statement.
        $executable = false;

        while (($line = fgets($handle)) !== false) {
            $length = strlen($line);
            $lineComment = false;

            for ($index = 0; $index < $length; ++$index) {
                $char = $line[$index];
                $next = $line[$index + 1] ?? '';
                $current .= $char;

                if ($lineComment) {
                    continue;
                }

                if ($blockComment) {
                    if ($char === '*' && $next === '/') {
                        $current .= $next;
                        ++$index;
                        $blockComment = false;
                    }

                    continue;
                }

                if ($quote !== null) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\' && $quote !== '`') {
                        $escaped = true;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if (in_array($char, ["'", '"', '`'], true)) {
                    $quote = $char;
                    $executable = true;
                } elseif ($char === '#' || ($char === '-' && $next === '-')) {
                    $lineComment = true;
                } elseif ($char === '/' && $next === '*') {
                    $current .= $next;
                    ++$index;
                    $blockComment = true;
                    // MySQL executes a conditional `/*!…*/` comment, so a statement consisting of
                    // one such comment must not be disregarded.
                    $executable = $executable || ($line[$index + 1] ?? '') === '!';
                } elseif ($char === ';') {
                    $statement = trim(substr($current, 0, -1));
                    $current = '';

                    if ($executable && $statement !== '') {
                        yield $statement;
                    }

                    $executable = false;
                } elseif (trim($char) !== '') {
                    $executable = true;
                }
            }
        }

        if (!feof($handle)) {
            throw SnapshotFailedException::because('php', "reading snapshot {$file} broke off midway");
        }

        $tail = trim($current);

        if ($executable && $tail !== '') {
            yield $tail;
        }
    }

    /**
     * Shows the beginning of a statement — up to the first string literal. Row data must not reach
     * the diagnostics: in a MODX snapshot that includes user password hashes, and the exception
     * message goes into the test report.
     */
    private function summarize(string $statement): string
    {
        $normalized = (string) preg_replace('/\s+/', ' ', $statement);
        $parts = preg_split('/[\'"]/', $normalized, 2);
        $head = $parts === false ? $normalized : $parts[0];
        $truncated = $head !== $normalized;

        if (mb_strlen($head) > 80) {
            $head = mb_substr($head, 0, 80);
            $truncated = true;
        }

        return $truncated ? rtrim($head) . ' …' : $head;
    }

    /**
     * @param resource $handle
     */
    private function write($handle, string $file, string $data): void
    {
        $written = fwrite($handle, $data);
        $expected = strlen($data);

        // A short write (the disk filled up exactly in the middle of a statement) differs from a
        // full one only by the returned number: without this check the snapshot would be finished
        // "successfully".
        if ($written === false || $written < $expected) {
            throw SnapshotFailedException::because('php', sprintf(
                'writing to %s failed: %d bytes out of %d reached the disk. Check free space in '
                . 'the snapshot directory.',
                $file,
                $written === false ? 0 : $written,
                $expected
            ));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function connect(DatabaseConfig $database): PDO
    {
        try {
            $pdo = new PDO($database->dsn(), $database->user, $database->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // The dumper's connection cleans the database with DROP TABLE, and any unclosed
            // transaction on another connection holds a metadata lock. The default offer is to wait
            // 31,536,000 seconds for it — a year (lock_wait_timeout, not
            // innodb_lock_wait_timeout): a deadlock would look like a hang without a single line in
            // the log. With this setting it turns into an error message within half a minute.
            $pdo->exec('SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_TIMEOUT_SECONDS);

            return $pdo;
        } catch (PDOException $exception) {
            // The driver's message contains no password, but the safeguard costs one line.
            throw SnapshotFailedException::because(
                'php',
                Secret::mask($exception->getMessage(), $database->password),
                $exception
            );
        }
    }
}

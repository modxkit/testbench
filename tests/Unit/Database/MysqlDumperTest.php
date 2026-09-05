<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Database;

use ModxKit\Testbench\Database\MysqlDumper;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Exception\SnapshotFailedException;
use ModxKit\Testbench\Support\ProcessResult;
use ModxKit\Testbench\Tests\Support\ClientPathControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The `mysqldump`/`mysql` clients are not present on every machine (on the development machine they
 * are absent altogether), so the strategy is checked through a fake CommandRunner. Such tests cover
 * the assembly of the command, the handling of the password and the parsing of the result, but not
 * the behaviour of the MySQL clients themselves.
 */
#[Group('unit')]
final class MysqlDumperTest extends TestCase
{
    use ClientPathControl;

    private const PASSWORD = 'p@ss"w\\ord';

    /**
     * The contents of a file the strategy must accept as a snapshot: what matters is not the SQL
     * itself but the completion marker at the tail — without it `load()` refuses before the database
     * is cleaned.
     */
    private const COMPLETE_SNAPSHOT = "-- MySQL dump\n-- testbench:complete tables=1\n";

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/tb-mysqldumper-' . bin2hex(random_bytes(4)) . '.sql';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '.part'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->removeBinDirectories();
    }

    public function testIsAvailableRequiresBothClients(): void
    {
        $runner = new DefaultsFileRecordingCommandRunner(new ProcessResult(0, '', ''), new ProcessResult(0, '', ''));

        self::assertTrue((new MysqlDumper($runner))->isAvailable());
        self::assertSame([['mysqldump', '--version'], ['mysql', '--version']], $runner->commands);
    }

    /**
     * Availability is checked by LAUNCHING the client, not by the presence of a file. `which` finds
     * an executable file — and only that; the client may then fail to start at all (a library did
     * not load, a symlink broke, a wrapper refuses). It is checked against the real `ProcessRunner`:
     * a fake runner cannot show the difference between `which` and a launch by construction.
     */
    public function testIsAvailableIsFalseWhenTheClientIsFoundButDoesNotRun(): void
    {
        $bin = $this->binDirectoryWith([
            'mysqldump' => "#!/bin/sh\necho 'dyld: library not loaded' >&2\nexit 1\n",
            'mysql' => "#!/bin/sh\nexit 0\n",
            // `which` here is ours too and always succeeds: it is what impersonates a system where the
            // client file IS FOUND while refusing to run. Without it the check would not tell the fix
            // from the earlier `which mysqldump`.
            'which' => "#!/bin/sh\nexit 0\n",
        ]);

        $available = $this->withPath($bin, static fn (): bool => (new MysqlDumper())->isAvailable());

        self::assertFalse($available);
    }

    public function testIsAvailableIsTrueWhenBothClientsRun(): void
    {
        $bin = $this->binDirectoryWith([
            'mysqldump' => "#!/bin/sh\nexit 0\n",
            'mysql' => "#!/bin/sh\nexit 0\n",
        ]);

        $available = $this->withPath($bin, static fn (): bool => (new MysqlDumper())->isAvailable());

        self::assertTrue($available);
    }

    public function testIsAvailableIsFalseWhenTheClientForRestoringIsMissing(): void
    {
        $runner = new DefaultsFileRecordingCommandRunner(
            new ProcessResult(0, '', ''),
            new ProcessResult(1, '', 'mysql not found')
        );

        self::assertFalse((new MysqlDumper($runner))->isAvailable());
    }

    public function testIsAvailableIsFalseWhenMysqldumpIsMissing(): void
    {
        $runner = new DefaultsFileRecordingCommandRunner(new ProcessResult(1, '', 'mysqldump not found'));

        self::assertFalse((new MysqlDumper($runner))->isAvailable());
        self::assertSame([['mysqldump', '--version']], $runner->commands);
    }

    /**
     * The pair of client names is a parameter of the strategy rather than a constant. A MariaDB 12
     * runner carries only `mariadb-dump`/`mariadb`, and capture and restore have to go through them,
     * while the snapshot format stays the same `mysql`: it is decided by the family of clients, not
     * by the name of the file.
     *
     * BOTH ends are checked — capture and restore: a name that reached only `dump()` would produce a
     * snapshot that nothing can read.
     */
    public function testMariadbNamedClientsAreUsedForBothEndsWhenGiven(): void
    {
        $dumpRunner = new DefaultsFileRecordingCommandRunner(
            new ProcessResult(0, '', ''),
            new ProcessResult(0, "modx_probe\tBASE TABLE\n", '')
        );

        $dumper = new MysqlDumper($dumpRunner, 'mariadb-dump', 'mariadb');

        self::assertSame(MysqlDumper::FORMAT, $dumper->format());

        $dumper->dump($this->database(), $this->file);

        self::assertSame('mariadb-dump', $dumpRunner->commands[0][0]);
        // The objects are enumerated by the restoring client — the same one as in load() below.
        self::assertSame('mariadb', $dumpRunner->commands[1][0]);

        $loadRunner = new DefaultsFileRecordingCommandRunner(
            new ProcessResult(0, "modx_probe\tBASE TABLE\n", ''),
            new ProcessResult(0, '', ''),
            new ProcessResult(0, '', '')
        );

        (new MysqlDumper($loadRunner, 'mariadb-dump', 'mariadb'))->load($this->database(), $this->file);

        $commands = array_map(
            static fn (array $command): string => implode(' ', $command),
            $loadRunner->commands
        );

        self::assertStringStartsWith('mariadb ', $commands[0]);
        self::assertStringStartsWith('mariadb ', $commands[1]);
        // The restore feeds the client through a shell, because the dump is read from stdin.
        self::assertStringContainsString(escapeshellarg('mariadb') . ' --defaults-extra-file=', $commands[2]);
        // The needle is the escaped NAME of the client, not the substring "mysql": that one also
        // occurs in the name of the temporary snapshot file (`tb-mysqldumper-…`), and the check would
        self::assertStringNotContainsString(escapeshellarg('mysql'), $commands[2]);
    }

    /**
     * The default is the earlier Oracle pair of names. Without this check the mutation "always
     * mariadb-dump" would pass: the other tests of this class pass a runner, but not the names.
     */
    public function testDefaultClientNamesAreTheOracleOnes(): void
    {
        $runner = new DefaultsFileRecordingCommandRunner(new ProcessResult(0, '', ''), new ProcessResult(0, '', ''));

        self::assertTrue((new MysqlDumper($runner))->isAvailable());
        self::assertSame([['mysqldump', '--version'], ['mysql', '--version']], $runner->commands);
    }

    public function testFormatNamesTheStrategyThatWroteTheSnapshot(): void
    {
        self::assertSame('mysql', (new MysqlDumper(new DefaultsFileRecordingCommandRunner()))->format());
        self::assertSame(MysqlDumper::FORMAT, (new MysqlDumper(new DefaultsFileRecordingCommandRunner()))->format());
    }

    public function testDumpPassesCredentialsThroughAPrivateOptionsFileInsteadOfTheCommandLine(): void
    {
        $runner = new DefaultsFileRecordingCommandRunner(
            new ProcessResult(0, '', ''),
            new ProcessResult(0, "modx_probe\tBASE TABLE\n", '')
        );

        (new MysqlDumper($runner))->dump($this->database(), $this->file);

        $command = array_values($runner->commands[0]);
        $options = $runner->defaultsFile();

        self::assertSame('mysqldump', $command[0]);
        self::assertContains('--defaults-extra-file=' . $options['path'], $command);
        // Without --no-tablespaces, mysqldump 8 requires the PROCESS privilege on the whole server.
        self::assertContains('--no-tablespaces', $command);
        // The client writes into a temporary file, and the result reaches the snapshot's place
        // through a single rename() — already carrying the completion marker.
        self::assertContains('--result-file=' . $this->file . '.part', $command);
        self::assertNotContains('--result-file=' . $this->file, $command);
        self::assertContains('modx_testbench', $command);
        $this->assertNoArgumentLeaksThePassword($command);

        self::assertFileDoesNotExist($this->file . '.part');
        self::assertStringEndsWith("-- testbench:complete tables=1\n", (string) file_get_contents($this->file));

        // default-character-set: the clients work in the configured encoding rather than in their own
        // default, the way the PDO strategy does through the DSN. The option is in [client]: both
        // clients must see it, and both know it.
        //
        // init-command: a client cleaning the database waits for a metadata lock for 30 seconds, not
        // a year. The option is in [mysql], and that is not cosmetic: in [client] it would also be read
        // by `mysqldump`, and MariaDB builds (all of them) and Oracle builds before 8.4 fail on it with
        // "unknown variable" before connecting to the DBMS — the install at the consumer's would not
        // start at all. The same file against real clients is checked by MysqlDumperMariadbClientTest.
        self::assertSame(
            "[client]\nhost=\"127.0.0.1\"\nport=3307\nuser=\"tester\"\npassword=\"p@ss\\\"w\\\\ord\"\n"
            . "default-character-set=\"utf8mb4\"\n"
            . "\n[mysql]\ninit-command=\"SET SESSION lock_wait_timeout = 30\"\n",
            $options['contents']
        );
        self::assertSame('0600', $options['mode']);
        self::assertFileDoesNotExist($options['path']);
    }

    public function testDumpFailureIsReportedWithTheMaskedPassword(): void
    {
        $runner = new DefaultsFileRecordingCommandRunner(
            new ProcessResult(2, '', 'mysqldump: error using password ' . self::PASSWORD)
        );

        try {
            (new MysqlDumper($runner))->dump($this->database(), $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('strategy mysqldump', $exception->getMessage());
            self::assertStringContainsString('mysqldump: error using password ***', $exception->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $exception->getMessage());
            self::assertStringContainsString('Check that the DBMS is reachable', $exception->getMessage());
        }

        self::assertFileDoesNotExist($runner->defaultsFile()['path']);
    }

    /**
     * A failed capture must not dare touch a previous snapshot lying in its place — the client
     * writes into `.part`, and the truncated remainder is removed together with it.
     */
    public function testFailedDumpLeavesNoHalfWrittenSnapshotAndKeepsThePreviousOne(): void
    {
        file_put_contents($this->file, self::COMPLETE_SNAPSHOT);
        // mysqldump manages to create --result-file before it runs into the error.
        file_put_contents($this->file . '.part', "-- MySQL dump\n");
        $runner = new DefaultsFileRecordingCommandRunner(new ProcessResult(2, '', 'mysqldump: Got error 1146'));

        try {
            (new MysqlDumper($runner))->dump($this->database(), $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException) {
            // What is checked is not the message but the state of the file system.
        }

        self::assertFileDoesNotExist($this->file . '.part');
        self::assertStringEqualsFile($this->file, self::COMPLETE_SNAPSHOT);
    }

    /**
     * The enumeration BEFORE the cleanup goes without a filter by type — a view created after the
     * capture must be dropped on the same footing as the tables, and it is dropped by `DROP VIEW`,
     * because `DROP TABLE` does not touch a view at all.
     */
    public function testLoadDropsTablesAndViewsLeftBehindBeforeFeedingTheSnapshot(): void
    {
        file_put_contents($this->file, self::COMPLETE_SNAPSHOT);

        $runner = new DefaultsFileRecordingCommandRunner(
            new ProcessResult(
                0,
                "modx_probe\tBASE TABLE\nmodx_created_later\tBASE TABLE\nmodx_view_created_later\tVIEW\n",
                ''
            ),
            new ProcessResult(0, '', ''),
            new ProcessResult(0, '', '')
        );

        (new MysqlDumper($runner))->load($this->database(), $this->file);

        $options = $runner->defaultsFile();

        self::assertSame([
            'mysql',
            '--defaults-extra-file=' . $options['path'],
            '--batch',
            '--skip-column-names',
            '--execute=SHOW FULL TABLES',
            'modx_testbench',
        ], $runner->commands[0]);

        self::assertSame([
            'mysql',
            '--defaults-extra-file=' . $options['path'],
            '--execute=SET FOREIGN_KEY_CHECKS=0; DROP VIEW IF EXISTS `modx_view_created_later`;'
                . ' DROP TABLE IF EXISTS `modx_probe`, `modx_created_later`; SET FOREIGN_KEY_CHECKS=1;',
            'modx_testbench',
        ], $runner->commands[1]);

        $feed = $runner->lastCommand();

        self::assertSame(['sh', '-c'], [$feed[0], $feed[1]]);
        // The client name is escaped on the same footing as the other arguments: the pair of names is
        // a parameter of the strategy, not a literal in the command string.
        self::assertStringStartsWith(
            escapeshellarg('mysql') . ' --defaults-extra-file=' . escapeshellarg($options['path']),
            $feed[2]
        );
        self::assertStringContainsString(
            escapeshellarg('modx_testbench') . ' < ' . escapeshellarg($this->file),
            $feed[2]
        );
        $this->assertNoArgumentLeaksThePassword($feed);
        self::assertStringContainsString('password="p@ss\\"w\\\\ord"', $options['contents']);
        self::assertSame('0600', $options['mode']);
        self::assertFileDoesNotExist($options['path']);
    }

    public function testLoadSkipsTheDropWhenTheDatabaseHasNoTables(): void
    {
        file_put_contents($this->file, self::COMPLETE_SNAPSHOT);

        $runner = new DefaultsFileRecordingCommandRunner(new ProcessResult(0, "\n", ''), new ProcessResult(0, '', ''));

        (new MysqlDumper($runner))->load($this->database(), $this->file);

        self::assertCount(2, $runner->commands);
        self::assertSame('sh', $runner->lastCommand()[0]);
    }

    public function testLoadReportsAFailedCleanupWithTheMaskedPassword(): void
    {
        file_put_contents($this->file, self::COMPLETE_SNAPSHOT);

        $runner = new DefaultsFileRecordingCommandRunner(
            new ProcessResult(1, '', 'ERROR 1045 (28000): Access denied, password was ' . self::PASSWORD)
        );

        try {
            (new MysqlDumper($runner))->load($this->database(), $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('password was ***', $exception->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $exception->getMessage());
        }

        // The snapshot is not fed to the client if the database could not be cleaned.
        self::assertCount(1, $runner->commands);
    }

    public function testLoadFailureIsReportedWithTheMaskedPassword(): void
    {
        file_put_contents($this->file, self::COMPLETE_SNAPSHOT);

        $runner = new DefaultsFileRecordingCommandRunner(
            new ProcessResult(0, '', ''),
            new ProcessResult(1, '', 'ERROR 1064 (42000) while dumping password ' . self::PASSWORD)
        );

        try {
            (new MysqlDumper($runner))->load($this->database(), $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('strategy mysql', $exception->getMessage());
            self::assertStringContainsString('dumping password ***', $exception->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $exception->getMessage());
        }
    }

    public function testLoadRefusesAMissingSnapshotWithoutRunningAnything(): void
    {
        $runner = new DefaultsFileRecordingCommandRunner();

        try {
            (new MysqlDumper($runner))->load($this->database(), $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('snapshot file not found: ' . $this->file, $exception->getMessage());
        }

        self::assertSame([], $runner->commands);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSnapshots(): iterable
    {
        yield 'an empty file' => [''];
        // A break exactly at a statement boundary: the earlier guard (`filesize() === 0`) let such a
        // file through, and cleaning the database for it destroyed the environment silently.
        yield 'a break at a statement boundary' => ["DROP TABLE IF EXISTS `modx_probe`;\nCREATE TABLE `modx_probe` (id INT);\n"];
    }

    #[DataProvider('unusableSnapshots')]
    public function testLoadRefusesSnapshotWithoutCompletionMarkerWithoutTouchingTheDatabase(string $contents): void
    {
        file_put_contents($this->file, $contents);
        $runner = new DefaultsFileRecordingCommandRunner();

        try {
            (new MysqlDumper($runner))->load($this->database(), $this->file);
            self::fail('Expected SnapshotFailedException was not thrown.');
        } catch (SnapshotFailedException $exception) {
            self::assertStringContainsString('is not read to the end', $exception->getMessage());
            self::assertStringContainsString('-- testbench:complete tables=', $exception->getMessage());
        }

        self::assertSame([], $runner->commands);
    }

    /**
     * @param list<string> $command
     */
    private function assertNoArgumentLeaksThePassword(array $command): void
    {
        foreach ($command as $argument) {
            self::assertStringNotContainsString(self::PASSWORD, $argument);
        }
    }

    private function database(): DatabaseConfig
    {
        return new DatabaseConfig(
            host: '127.0.0.1',
            port: 3307,
            name: 'modx_testbench',
            user: 'tester',
            password: self::PASSWORD,
            prefix: 'modx_',
            charset: 'utf8mb4',
            collation: 'utf8mb4_general_ci',
        );
    }
}

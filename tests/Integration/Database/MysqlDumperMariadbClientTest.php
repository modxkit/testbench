<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Database;

use ModxKit\Testbench\Database\MysqlDumper;
use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Database\SnapshotFile;
use ModxKit\Testbench\Database\SnapshotManager;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Support\ProcessRunner;
use ModxKit\Testbench\Tests\Support\ClientPathControl;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The `mysqldump` strategy must work with MariaDB clients, not only with Oracle MySQL clients. The
 * `init-command` option in the `[client]` group of the options file killed the snapshot capture on
 * four of the five client builds measured, and that is both lines at once: `mariadb-dump` 10.6.28,
 * 10.11.18, 12.3.2 and `mysqldump` 8.0.46 (only `mysqldump` 8.4.11 accepts it). Both are met under
 * the names `mysql`/`mysqldump`: Debian's `default-mysql-client` installs MariaDB clients (measured
 * on debian:stable — 11.8.6-MariaDB), Ubuntu's `mysql-client` an Oracle one (measured on the
 * `ubuntu:24.04` image: `mysqldump Ver 8.0.46-0ubuntu0.24.04.4`; re-checkable in CI in the step
 * "the mysql/mysqldump clients must be present on the runner" of any `integration` job — no run id
 * is quoted, the public history is restarted at release). The MariaDB line is held by this
 * test. There is no dedicated test that provides an Oracle 8.0 client itself; in CI that line is
 * held by the matrix — `.github/workflows/tests.yml` installs `mysql-client` on every integration
 * job, and it is that job which caught this regression. Locally it was verified by running
 * `SnapshotFormatTest` under a PATH shim from the `mysql:8.0` image: `Errors: 3` before the fix,
 * `OK (3 tests)` after.
 *
 * A fake `CommandRunner` missed this finding by construction: it proves that the code called the
 * command, but not that a real client will accept it. The refusal happens BEFORE the connection, on
 * parsing the options file — so the clients here are real, and only `PATH` is substituted.
 *
 * The clients are taken from a MariaDB image by starting a container: they are not present on a
 * developer's machine, and the package has no right to install them for the sake of a test. Hence
 * the `mariadb-client` group, excluded in `phpunit.xml` (a dependency on an external tool is
 * expressed as a group rather than as a conditional skip). To run it:
 *
 *     vendor/bin/phpunit --testsuite integration --group mariadb-client
 *
 * The test needs `docker`, the image {@see self::IMAGE} and a working `--network host` — through it
 * the client inside the container sees the DBMS at the same address as the host process does. Docker
 * builds where host networking is unavailable were not measured here; the test will refuse there.
 * The test checks none of this in advance: the group is excluded, and whoever requested it must see
 * a refusal rather than a skip.
 */
#[Group('integration')]
#[Group('mariadb-client')]
final class MysqlDumperMariadbClientTest extends TestCase
{
    use ClientPathControl;

    /**
     * The same image the branch's local PATH shim takes its clients from. The run was repeated on
     * `mariadb:10.6` (10.6.28) as well — green. The names inside this image are still the old ones:
     * `mysql` and `mysqldump` are symlinks to `mariadb` and `mariadb-dump`.
     */
    private const IMAGE = 'mariadb:10.11';

    /**
     * This image (12.3.2) has no `mysql`/`mysqldump` files at all — only `mariadb` and
     * `mariadb-dump`. Such a runner used to look to the package like a runner WITHOUT clients, and
     * the snapshot went silently to the php strategy together with the loss of views and triggers.
     */
    private const IMAGE_WITHOUT_LEGACY_NAMES = 'mariadb:latest';

    private DatabaseConfig $database;
    private string $file;
    private string $bin;

    protected function setUp(): void
    {
        $environment = DatabaseConfig::fromEnvironment();

        $this->database = new DatabaseConfig(
            host: $environment->host,
            port: $environment->port,
            // The whole naming scheme rather than `getmypid()` alone: against a pid the system has handed
            // out again in the same environment it gives no protection — see the docblock of {@see RunScopedDatabaseName}.
            name: RunScopedDatabaseName::forBase('modx_testbench_mariadb'),
            user: $environment->user,
            password: $environment->password,
            prefix: 'modx_',
            charset: $environment->charset,
            collation: $environment->collation,
        );

        $this->file = sys_get_temp_dir() . '/tb-mariadb-' . bin2hex(random_bytes(4)) . '.sql';
        $this->bin = $this->clientsFromImage(self::IMAGE, ['mysqldump', 'mysql']);

        $server = $this->serverConnection();
        $server->exec('DROP DATABASE IF EXISTS `' . $this->database->name . '`');
        $server->exec('CREATE DATABASE `' . $this->database->name . '`');

        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE `modx_probe` (`id` INT PRIMARY KEY, `note` VARCHAR(32))');
        $pdo->exec("INSERT INTO `modx_probe` (`id`, `note`) VALUES (1, 'снято до снимка')");
    }

    protected function tearDown(): void
    {
        $this->removeBinDirectories();

        foreach ([$this->file, $this->file . '.part'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        try {
            $this->serverConnection()->exec('DROP DATABASE IF EXISTS `' . $this->database->name . '`');
        } catch (Throwable) {
            // Cleanup "where possible": an unreachable DBMS must not break the test report.
        }
    }

    /**
     * Capture and restore by real MariaDB clients. The earlier options file killed the very first
     * call: `mysqldump: unknown variable 'init-command=…'`, return code 7 — before connecting to the
     * DBMS and therefore whatever the credentials.
     *
     * The Cyrillic in the probe rows below is deliberate and must NOT be replaced with ASCII: those
     * values are the only multibyte text in this class that makes the round trip out through
     * `mariadb-dump` and back in through `mariadb`, and the assertion reads it back by exact match.
     */
    public function testRealMariadbClientsTakeAndRestoreTheSnapshot(): void
    {
        self::assertTrue(
            $this->onPath(static fn (): bool => (new MysqlDumper())->isAvailable()),
            'The clients from the image ' . self::IMAGE . ' do not run — there is nothing to check with.'
        );

        $this->onPath(function (): void {
            (new MysqlDumper())->dump($this->database, $this->file);
        });

        $snapshot = (string) file_get_contents($this->file);
        self::assertStringContainsString(
            'MariaDB dump',
            $snapshot,
            'The snapshot was not captured by a MariaDB client — the PATH shim did not reach the strategy.'
        );
        self::assertTrue(SnapshotFile::isComplete($this->file));

        // Changes made AFTER the capture: the restore must undo them — both the data and the DDL.
        $pdo = $this->connection();
        $pdo->exec("UPDATE `modx_probe` SET `note` = 'после снимка' WHERE `id` = 1");
        $pdo->exec('CREATE TABLE `modx_created_later` (`id` INT)');

        $this->onPath(function (): void {
            (new MysqlDumper())->load($this->database, $this->file);
        });

        self::assertSame('снято до снимка', $this->text('SELECT `note` FROM `modx_probe` WHERE `id` = 1'));
        self::assertSame(0, $this->scalar(sprintf(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '%s' "
            . "AND table_name = 'modx_created_later'",
            $this->database->name
        )));
    }

    /**
     * This is NOT a duplicate of the test above. There the image carries the old names
     * (`mysql`/`mysqldump` as symlinks), and the strategy reached a MariaDB client knowing nothing
     * about MariaDB. Here the old names are absent altogether, which means what is checked is
     * exactly what was added: looking for the second pair of names and using it at both ends — on
     * capture and on restore.
     *
     * That the old names are absent from the image is checked right here: without that check the
     * test would go green against an image that has the symlinks, proving nothing.
     */
    public function testMariadb12ClientsWithoutLegacyNamesTakeAndRestoreTheSnapshot(): void
    {
        $modern = $this->clientsFromImage(self::IMAGE_WITHOUT_LEGACY_NAMES, ['mariadb-dump', 'mariadb']);

        // Premise: the image really has neither `mysqldump` nor `mysql`.
        foreach (['mysqldump', 'mysql'] as $legacy) {
            $probe = (new ProcessRunner())->run([
                'docker', 'run', '--rm', '--entrypoint', 'sh', self::IMAGE_WITHOUT_LEGACY_NAMES,
                '-c', 'command -v ' . $legacy,
            ]);

            self::assertFalse(
                $probe->isSuccessful(),
                'The image ' . self::IMAGE_WITHOUT_LEGACY_NAMES . ' has ' . $legacy
                . ' — the test no longer reproduces the case it was written for.'
            );
        }

        // The client directory goes IN FRONT of the existing PATH — for the same reason as in
        // {@see self::onPath()}: the strategies also need `sh`, and the shims need `docker`.
        $inherited = getenv('PATH');
        $modernPath = $modern . ($inherited === false ? '' : PATH_SEPARATOR . $inherited);

        $available = false;
        $this->withPath($modernPath, static function () use (&$available): void {
            $available = (new MysqlDumper(dumpBinary: 'mariadb-dump', clientBinary: 'mariadb'))->isAvailable();
        });

        self::assertTrue(
            $available,
            'The clients from the image ' . self::IMAGE_WITHOUT_LEGACY_NAMES . ' do not run — there is nothing to check with.'
        );

        // The strategy is chosen by the manager — and it is the manager that must find the second pair of names itself.
        $manager = null;
        $this->withPath($modernPath, function () use (&$manager): void {
            $manager = new SnapshotManager($this->database, $this->file);
        });

        self::assertInstanceOf(SnapshotManager::class, $manager);
        self::assertSame(MysqlDumper::FORMAT, $manager->format());

        $this->withPath($modernPath, static function () use ($manager): void {
            $manager->capture();
        });

        $snapshot = (string) file_get_contents($this->file);
        self::assertStringContainsString('MariaDB dump', $snapshot);
        self::assertTrue(SnapshotFile::isComplete($this->file));

        $pdo = $this->connection();
        $pdo->exec("UPDATE `modx_probe` SET `note` = 'после снимка' WHERE `id` = 1");
        $pdo->exec('CREATE TABLE `modx_created_later` (`id` INT)');

        $this->withPath($modernPath, static function () use ($manager): void {
            $manager->restore();
        });

        self::assertSame('снято до снимка', $this->text('SELECT `note` FROM `modx_probe` WHERE `id` = 1'));
        self::assertSame(0, $this->scalar(sprintf(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '%s' "
            . "AND table_name = 'modx_created_later'",
            $this->database->name
        )));
    }

    /**
     * Against a MariaDB client: the connection that cleans the database for the restore must wait on
     * a foreign transaction's metadata lock for 30 seconds rather than for the default —
     * 31,536,000 seconds, that is, a year (this is `lock_wait_timeout`, not
     * `innodb_lock_wait_timeout`).
     *
     * The value is taken from the client's OWN connection: the snapshot writes
     * `@@SESSION.lock_wait_timeout` into a table, and the test reads that table from its own
     * connection — the same technique as in
     * {@see PhpDumperTest::testDumperConnectionCapsTheMetadataLockWait()}.
     */
    public function testRestoringMariadbClientCapsTheMetadataLockWait(): void
    {
        self::assertSame(
            31536000,
            $this->scalar('SELECT @@SESSION.lock_wait_timeout'),
            'The wait has changed on the server side — the test below would stop proving anything.'
        );

        file_put_contents($this->file, implode('', [
            "CREATE TABLE `modx_lock_probe` (`waited` BIGINT);\n",
            "INSERT INTO `modx_lock_probe` SELECT @@SESSION.lock_wait_timeout;\n",
            SnapshotFile::completionLine(1),
        ]));

        $this->onPath(function (): void {
            (new MysqlDumper())->load($this->database, $this->file);
        });

        self::assertSame(
            PhpDumper::LOCK_WAIT_TIMEOUT_SECONDS,
            $this->scalar('SELECT `waited` FROM `modx_lock_probe`')
        );
    }

    /**
     * A directory of scripts proxying the same-named files of the MariaDB image. The names are given
     * by the caller, because they differ between images: up to and including 11.8 there are both
     * `mysql` and `mysqldump` — symlinks to `mariadb` and `mariadb-dump`; on 12.3.2 only the latter
     * remain. MariaDB clients reach a consumer the same way through Debian's `default-mysql-client`
     * package — measured on debian:stable: `/usr/bin/mysqldump` there is a symlink to
     * `mariadb-dump`, version 11.8.6-MariaDB.
     *
     * The temporary directory is mounted into the container as it is: both the options file
     * (`--defaults-extra-file`) and the snapshot itself (`--result-file`) travel through it.
     * `--user` is needed so that the snapshot belongs to the host process rather than to root:
     * otherwise `rename()` and `unlink()` in the strategy would be refused on Linux.
     *
     * @param list<string> $binaries the names under which the image's clients are placed in PATH;
     *                               they are also the names of the files invoked inside the container
     */
    private function clientsFromImage(string $image, array $binaries): string
    {
        $docker = (new ProcessRunner())->run(['sh', '-c', 'command -v docker']);

        self::assertTrue(
            $docker->isSuccessful(),
            'The mariadb-client group was requested, but docker is not in PATH — there is nowhere to get real MariaDB clients from.'
        );

        // The directory is mounted under both names: `tempnam()` on macOS returns a path through
        // `/private/var/folders/…`, while the snapshot file name is built from `sys_get_temp_dir()`,
        // that is, `/var/folders/…`. Both must exist inside the container.
        $temporary = sys_get_temp_dir();
        $real = realpath($temporary);
        $source = $real === false ? $temporary : $real;
        $mounts = array_unique([$source, $temporary]);
        $shim = sprintf(
            "#!/bin/sh\nexec %s run --rm -i --network host --user %d:%d",
            escapeshellarg(trim($docker->stdout)),
            posix_getuid(),
            posix_getgid()
        );

        foreach ($mounts as $mount) {
            $shim .= sprintf(' -v %s:%s', escapeshellarg($source), escapeshellarg($mount));
        }

        $shim .= ' ' . escapeshellarg($image);

        $scripts = [];

        foreach ($binaries as $binary) {
            $scripts[$binary] = $shim . ' ' . $binary . " \"$@\"\n";
        }

        return $this->binDirectoryWith($scripts);
    }

    /**
     * The client directory goes IN FRONT of the existing `PATH` rather than replacing it: the
     * strategy also calls `sh` (redirecting the snapshot into the client's input), and the shims call
     * `docker`. An empty `PATH` would leave the test without both.
     *
     * @template TResult
     *
     * @param callable(): TResult $run
     *
     * @return TResult
     */
    private function onPath(callable $run): mixed
    {
        $inherited = getenv('PATH');

        return $this->withPath(
            $this->bin . ($inherited === false ? '' : PATH_SEPARATOR . $inherited),
            $run
        );
    }

    private function text(string $query): string
    {
        $statement = $this->connection()->query($query);
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }

    private function scalar(string $query): int
    {
        $statement = $this->connection()->query($query);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function connection(): PDO
    {
        return new PDO(
            $this->database->dsn(),
            $this->database->user,
            $this->database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function serverConnection(): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', $this->database->host, $this->database->port, $this->database->charset),
            $this->database->user,
            $this->database->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}

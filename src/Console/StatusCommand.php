<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Console;

use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Database\SchemaInventory;
use ModxKit\Testbench\Environment\LockFile;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Environment\Workspace;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\Secret;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(name: 'status', description: 'Show the state of the test environment')]
final class StatusCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $kernel = TestbenchKernel::instance();
        $config = $kernel->config();
        $workspace = $kernel->workspace();
        $lock = $workspace->readLock();

        $io->definitionList(
            ['Workspace directory' => $workspace->path()],
            ['State' => $workspace->isInstalledWith($config->fingerprint()) ? 'installed' : 'not installed'],
            // PHPStan (nullsafe.neverNull): `??` already suppresses the warning about reading a
            // property with `null` on the left, so a `?->` would be redundant here — unlike
            // `$lock?->hasSnapshot === true` below, where there is no `??` and the warning is not
            // suppressed.
            ['MODX version' => $lock->modxVersion ?? $config->version],
            ['Provider' => $config->provider],
            ['Table prefix' => $config->database->prefix],
            ['DBMS' => sprintf(
                '%s@%s:%d/%s (password: %s)',
                $config->database->user,
                $config->database->host,
                $config->database->port,
                $config->database->name,
                $config->database->password === '' ? 'empty' : '***'
            )],
            ['Snapshot' => $lock?->hasSnapshot === true ? $workspace->snapshotPath() : 'none'],
            // The choice of snapshot strategy is a silent fork, and the consumer's main path
            // (`vendor/bin/phpunit`, with the bootstrap preparing the environment) never calls
            // `install` at all and never sees its warnings. `status` is the first thing called when
            // making sense of somebody else's environment — this line is needed here.
            ['Snapshot strategy' => $this->describeSnapshotStrategy($lock)],
            // Before this line "installed" said nothing about the database itself — status did not
            // notice a wiped core table at all.
            ['Database' => $this->describeTables($config, $lock)],
            // `install` has warned about this since it was written, and `bootstrap.php` argues at
            // length about why IT may not — but the consumer's main path never runs `install` at
            // all, and this command is the one called when making sense of somebody else's
            // environment. Printing the same fact here costs a line and needs no decision about
            // channels: it is ordinary command output.
            ['Password exposure' => $this->describeExposure($workspace)],
        );

        return Command::SUCCESS;
    }

    /**
     * Both exposures the package can see, asked of the FILES rather than of the lock: an
     * environment installed with tight permissions does not stay tight if somebody widens them
     * afterwards, and it is precisely then that the question is being asked.
     */
    private function describeExposure(Workspace $workspace): string
    {
        $files = $workspace->exposedSecretFiles();
        $directories = $workspace->exposedDirectories();

        if ($files === [] && $directories === []) {
            return 'none — the environment files and the directory name are private to you';
        }

        $lines = [];

        if ($files !== []) {
            $lines[] = 'files holding the database password are readable by more than their owner: '
                . implode(', ', $files);
        }

        if ($directories !== []) {
            $lines[] = 'the environment directory name (a fingerprint with the database password '
                . 'hashed into it) can be listed by outsiders through: ' . implode(', ', $directories);
        }

        return implode('; ', $lines);
    }

    /**
     * The format is taken from the lock — it is a fact of the install rather than a repeated probe
     * of PATH: a snapshot is read by the same strategy that captured it (FR-ISO-5a).
     */
    private function describeSnapshotStrategy(?LockFile $lock): string
    {
        $format = $lock instanceof LockFile ? $lock->snapshotFormat : '';

        if ($format === '') {
            // The phrase "not installed" must not be used here: it belongs to the "State" row, and
            // that is exactly what the tests tell the states apart by.
            return 'not recorded (the lock carries no format)';
        }

        return $format === PhpDumper::FORMAT
            ? 'php — no mysqldump/mysql (or mariadb-dump/mariadb) clients were found in PATH, '
                . 'views and triggers did NOT make it into the snapshot'
            : $format;
    }

    private function describeTables(TestbenchConfig $config, ?LockFile $lock): string
    {
        try {
            $actual = SchemaInventory::countTablesWithPrefix($config->database);
        } catch (TestbenchException $exception) {
            return 'could not be read: ' . Secret::maskMessage(
                $exception,
                $config->database->password,
                $config->admin->password,
            );
        }

        $expected = $lock instanceof LockFile ? $lock->tableCount : 0;

        if ($expected === 0) {
            return sprintf('%d tables with prefix "%s" (the lock carries no table count)', $actual, $config->database->prefix);
        }

        return $actual === $expected
            ? sprintf('%d/%d tables', $actual, $expected)
            : sprintf(
                '%d/%d tables — differs from the lock, the environment will be repaired from the '
                . 'baseline or reinstalled on the next preparation',
                $actual,
                $expected
            );
    }
}

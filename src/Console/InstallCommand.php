<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Console;

use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Environment\LockFile;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\Secret;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(name: 'install', description: 'Set up the MODX 3 test environment')]
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Destroy the existing environment and install it again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('force') === true) {
            $_SERVER['MODX_TESTBENCH_FORCE_INSTALL'] = '1';
            TestbenchKernel::reset();
        }

        $kernel = TestbenchKernel::instance();

        try {
            $workspace = $kernel->prepare();
        } catch (TestbenchException $exception) {
            // The exception message may contain the database password (for example from a failed
            // PDO connection) — we mask it right here rather than rely on the source of the
            // exception having done so already.
            //
            // Not masked are only the types declaring {@see SecretFreeMessage} — those whose
            // message is built from the package's own prose, where blind masking destroys the
            // very names the message exists to name and no password can appear.
            $io->error(Secret::maskMessage(
                $exception,
                $kernel->config()->database->password,
                $kernel->config()->admin->password,
            ));

            return Command::FAILURE;
        }

        // The name of the environment directory is a fingerprint with the database password hashed
        // into it; if an outsider can read it, the consumer must learn that from the command rather
        // than guess.
        //
        // The check is LIVE and by the facts (directory modes), not by the sign "the fallback to
        // sys_get_temp_dir() was taken": the fallback creates no exposure by itself — the package
        // creates the whole path segment with mode 0700 — and an earlier revision of this block
        // claimed a leak where measurement does not find one.
        $exposed = $workspace->exposedDirectories();

        if ($exposed !== []) {
            // The exit code stays successful: the environment is ready, and the location is a
            // protective measure rather than a success criterion, on the same grounds as the file
            // permissions below.
            $io->warning(array_merge(
                [
                    'The name of the test environment directory can be read by any user of the '
                    . 'machine: outsiders are allowed to list the contents of',
                ],
                $exposed,
                [
                    'The directory name (' . basename($workspace->path()) . ') has the database '
                    . 'password hashed into it. Tighten the permissions on the named directory '
                    . '(chmod 700) or set MODX_TESTBENCH_WORKSPACE to a directory only you can access.',
                ]
            ));
        }

        // The command needs a voice of its own because the `FilePermissions` warning goes through
        // the PHP error mechanism, whose visibility is decided by the consumer's ini: with
        // `display_errors=0` together with `log_errors=0` (a rare but reachable combination) it
        // reaches nowhere, and a quiet security failure would look like a clean "Environment ready"
        // with exit code 0.
        //
        // We ask the FILES, not our memory of the install: on an already prepared environment
        // `prepare()` installs nothing, and the password in `config.inc.php` does not become any
        // less accessible for that — staying silent on the second run of the command would be the
        // worst of all.
        $exposed = $workspace->exposedSecretFiles();

        if ($exposed !== []) {
            // The exit code stays successful: the environment really is ready, and the permissions
            // are a protective measure rather than a success criterion.
            $io->warning(array_merge(
                [
                    'Environment files holding the database password are readable by more than their '
                    . 'owner (usually mode 0644 — any user of the machine can read them):',
                ],
                $exposed,
                [
                    'Either the package failed to tighten them, or the permissions were changed '
                    . 'after installation. If the machine is not single-user, restore mode 0600 on '
                    . 'the files or move the working directory with MODX_TESTBENCH_WORKSPACE to '
                    . 'where permissions work, and reinstall the environment.',
                ]
            ));
        }

        // The choice of snapshot strategy is a silent fork: with no mysqldump/mysql (or
        // mariadb-dump/mariadb) clients in PATH the snapshot goes to `PhpDumper`, and views and
        // triggers leave the environment along with it. Nobody used to say anything about that, and
        // from the outside the run looked the same. The format is taken from the lock, that is, it
        // is a fact of the install rather than a repeated probe of PATH.
        $lock = $workspace->readLock();
        // PHPStan considers a `?->` next to a `??` redundant (nullsafe.neverNull), so a missing
        // lock is handled explicitly — as in `StatusCommand`.
        $format = $lock instanceof LockFile ? $lock->snapshotFormat : '';

        if ($format !== '') {
            $lines = ['Snapshot strategy: ' . $format];

            if ($format === PhpDumper::FORMAT) {
                $lines[] = 'No mysqldump/mysql (or mariadb-dump/mariadb) clients were found in '
                    . 'PATH, so views and triggers will NOT make it into the snapshot.';
            }

            $io->text($lines);
        }

        $io->success('Environment ready: ' . $workspace->path());

        return Command::SUCCESS;
    }
}

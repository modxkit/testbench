<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Console;

use ModxKit\Testbench\Environment\TestbenchConfig;
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
#[AsCommand(name: 'destroy', description: 'Delete the test environment directory')]
final class DestroyCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Delete without confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Obtaining the workspace is inside the `try` too: `Workspace::forConfig()` rejects an
        // invalid MODX_TESTBENCH_WORKSPACE (the filesystem root), and that refusal must arrive as a
        // message with exit code 1 rather than as a raw Symfony stack trace (NFR-3).
        try {
            $workspace = TestbenchKernel::instance()->workspace();
            $path = $workspace->path();

            // The removal is irreversible, so confirmation is required by default.
            // SymfonyStyle::confirm() with default=false in non-interactive mode (no TTY, a script
            // in CI) returns the default without asking a single question — a refusal rather than a
            // removal, unless --force was passed explicitly.
            if ($input->getOption('force') !== true
                && !$io->confirm("Delete the test environment directory \"{$path}\"?", false)
            ) {
                $io->warning('Deletion cancelled.');

                return Command::SUCCESS;
            }

            // `Workspace::destroy()` refuses to remove a directory the package did not create.
            $workspace->destroy();

            // An incomplete cleanup (no permissions on part of the tree, a file in use) does not
            // become an exception — the directory stays marked so that it can be finished off. But
            // the command must not dare report success without having done the work: that is what
            // the exit code is for in CI.
            if ($workspace->hasLeftovers()) {
                $io->warning(sprintf(
                    "Directory \"%s\" was not fully deleted: some files could not be removed.\n"
                    . 'Usually this is missing permissions on part of the tree or a file in use — '
                    . 'see the warnings above. The directory is marked as belonging to the package, '
                    . 'so a repeated run will try to finish removing it.',
                    $path
                ));

                return Command::FAILURE;
            }

            $io->success("Environment deleted: {$path}");
        } catch (TestbenchException $exception) {
            // The text may be built from anything at all, so it is masked.
            // Exempt are only the types that declare {@see SecretFreeMessage}: their messages
            // are built from the package's own prose and cannot carry a password, while blind
            // masking destroys exactly the names such a message exists to name.
            $io->error(Secret::maskMessage(
                $exception,
                TestbenchConfig::fromEnvironment()->database->password,
                TestbenchConfig::fromEnvironment()->admin->password,
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

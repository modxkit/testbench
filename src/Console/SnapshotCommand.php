<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Console;

use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\Secret;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(name: 'snapshot', description: 'Capture or restore the database baseline snapshot')]
final class SnapshotCommand extends Command
{
    /** @var list<string> */
    private const ACTIONS = ['capture', 'restore'];

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'capture or restore', 'capture');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $actionValue = $input->getArgument('action');
        $action = is_string($actionValue) ? $actionValue : 'capture';

        // The password is taken from the environment rather than from the kernel:
        // `TestbenchKernel::instance()` may itself fail (an invalid MODX_TESTBENCH_WORKSPACE), and
        // addressing it from the `catch` would throw a second exception on top of the first.
        $config = TestbenchConfig::fromEnvironment();

        try {
            // The action is checked FIRST and before any work with the environment:
            // `snapshot bogus` must neither prepare the environment nor go to the network for a
            // distribution only to say "unknown action" afterwards.
            if (!in_array($action, self::ACTIONS, true)) {
                throw new TestbenchException(sprintf(
                    'Unknown action "%s". Allowed: %s.',
                    $action,
                    implode(', ', self::ACTIONS)
                ));
            }

            // `snapshots()` is inside the `try`: it calls `prepare()`, and since the integrity gate
            // appeared that one addresses the database — any preparation failure used to leave here
            // as a raw Symfony stack trace, past `Secret::mask()`.
            $snapshots = TestbenchKernel::instance()->snapshots();

            match ($action) {
                'capture' => $snapshots->capture(),
                'restore' => $snapshots->restore(),
            };

            $io->success("Snapshot: {$action} → " . $snapshots->path());
        } catch (TestbenchException $exception) {
            // We mask regardless of which exception was caught — the error message may be built
            // from arbitrary text, including the DBMS driver's output. The admin password too: the
            // `prepare()` path became reachable from here along with the integrity gate, and
            // `HeadlessInstaller` prints the manifest.
            // Exempt are the types declaring {@see SecretFreeMessage} — see its docblock.
            $io->error(Secret::maskMessage(
                $exception,
                $config->database->password,
                $config->admin->password,
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

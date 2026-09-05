<?php

declare(strict_types=1);

namespace ModxKit\Testbench\PHPUnit;

use ModxKit\Testbench\Environment\TestbenchKernel;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Throwable;

/**
 * Says out loud, once per run, that the files of the test environment holding the database password
 * are readable by more than their owner.
 *
 * The same fact is available from `bin/modx-testbench install`, from `bin/modx-testbench status`
 * and from `Workspace::exposedSecretFiles()` — and none of the three covers the case that matters
 * most: the environment was prepared by `bootstrap.php`, and nobody ran a command afterwards.
 * `bootstrap.php` itself deliberately stays silent, and the measurement that forces it to is
 * written out there: a warning raised from the bootstrap is raised again inside every
 * `#[RunInSeparateProcess]` child, where PHPUnit treats a non-empty STDERR as a test error.
 *
 * An extension does not have that problem, and that is measured rather than assumed
 * ({@see \ModxKit\Testbench\Tests\Integration\PHPUnit\ExposureWarningExtensionTest}): `bootstrap()` is
 * called once, in the runner process, and the isolated-process template of PHPUnit
 * (`vendor/phpunit/phpunit/src/Framework/TestRunner/templates/method.tpl`) does not run extensions
 * at all — so a child cannot repeat the warning even in principle.
 *
 * It is opt-in, and that is the whole of its safety: a consumer who does not want a word out of
 * their test run does not add the line. Registering it costs one line of `phpunit.xml`:
 *
 *     <extensions>
 *         <bootstrap class="ModxKit\Testbench\PHPUnit\ExposureWarningExtension"/>
 *     </extensions>
 */
final class ExposureWarningExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        try {
            $exposed = TestbenchKernel::instance()->workspace()->exposedSecretFiles();
        } catch (Throwable) {
            // A configuration the package refuses (an empty workspace path, a non-numeric port) is
            // reported by `bootstrap.php` and by the commands, each with its own message. Repeating
            // it from here — before a single test has run, and out of an extension nobody asked for
            // diagnostics from — would only bury the real one.
            return;
        }

        if ($exposed === []) {
            return;
        }

        fwrite(
            STDERR,
            sprintf(
                'modx-testbench: %d file(s) of the test environment hold the database password and '
                . "are readable by more than their owner:\n  %s\nRun `chmod 600` on them, or let the "
                . "package recreate the environment (`bin/modx-testbench destroy --force`).\n",
                count($exposed),
                implode("\n  ", $exposed)
            )
        );
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Package;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Catches a processor action that the package passes to `modX::runProcessor()` and that resolves
 * ONLY on a case-insensitive file system.
 *
 * The mechanism (`core/src/Revolution/modX.php:1795-1807`): MODX turns a string action of the form
 * `workspace/packages/scanlocal` into a class name by applying `ucfirst()` to every segment — which
 * gives `MODX\Revolution\Processors\Workspace\Packages\Scanlocal`. The real class is called
 * `ScanLocal`, with a capital "L" INSIDE the segment, and `ucfirst()` does not restore it. PSR-4
 * (`MODX\` → `core/src`) then looks for the file `…/Packages/Scanlocal.php`: on macOS (APFS,
 * case-insensitive) it is found — it is `ScanLocal.php` — while on Linux it is not found at all.
 * MODX 3 has no legacy `*.class.php` path, so `runProcessor()` returns a `ProcessorResponse` with
 * the message "Requested processor not found", and the central function of the package — installing
 * the extra under test — does not work on Linux. The suite stays green locally while this happens:
 * the defect lived in the package from the moment `TransportInstaller` appeared and only became
 * visible with the first CI run.
 *
 * The check deliberately does not compare the behaviour against "how it worked out for us" — it asks
 * a question whose answer is the same on any file system: does a class file exist under exactly the
 * name MODX will derive from the action? That is why the test is red on macOS in exactly the way the
 * CI job would have been red.
 *
 * Only the package's own sources (`src/`) are traversed: it is the actions from there that are
 * executed at the consumer's. In the tests the actions are sometimes deliberately non-existent
 * (`mgr/job/create` against the level 1 stub — `InteractsWithModxTest`), and there is nothing to
 * demand of their resolution.
 */
#[Group('integration')]
final class ProcessorActionResolutionTest extends TestCase
{
    public function testEveryProcessorActionOfThePackageResolvesCaseSensitively(): void
    {
        $actions = $this->processorActionsOfSources();

        // Premise: the traversal found something. A broken scanner (a renamed method, a switch to a
        // variable instead of a literal) would otherwise go green having checked nothing.
        self::assertNotSame([], $actions, 'The traversal found no runProcessor() call with a string action in src/.');

        $loader = $this->coreClassLoader();
        $unresolved = [];

        foreach ($actions as $action => $where) {
            $class = $this->processorClassOf($action);
            $file = $loader->findFile($class);

            if ($file === false) {
                // This is what it looks like on a case-sensitive file system (Linux, the CI runner).
                $unresolved[] = sprintf('%s (%s) → the class %s was not found by the core autoloader', $action, $where, $class);

                continue;
            }

            if (!$this->existsCaseSensitively($file)) {
                // And this is what it looks like on a case-insensitive one (macOS): the file "was found", but it is named differently.
                $unresolved[] = sprintf(
                    '%s (%s) → the class %s resolves to %s only because of a case-insensitive file system; on disk it is %s',
                    $action,
                    $where,
                    $class,
                    basename($file),
                    $this->actualNameOf($file)
                );
            }
        }

        self::assertSame(
            [],
            $unresolved,
            "A processor action does not resolve on a case-sensitive file system — in CI the consumer\n"
            . "will get \u00abRequested processor not found\u00bb. Pass the action in the case in which the\n"
            . "MODX processor classes are named:\n"
            . implode("\n", $unresolved)
        );
    }

    /**
     * The core's autoloader rather than a PSR-4 implementation of our own: the base directories of
     * the `MODX\` namespace are declared by the core, and repeating them here would mean checking
     * our own copy of the rule instead of the real one. The core's `vendor/autoload.php` is already
     * included by the loaded core, so a repeated `require` returns the same `ClassLoader` instance
     * without re-initialising anything.
     */
    private function coreClassLoader(): ClassLoader
    {
        // The path is taken from the working directory rather than through `getOption('core_path')`.
        // The reason was a measured one: on MODX 3.0.5-pl that setting returns `null` (the core comes
        // up incompletely there), and the check would have failed on its premise instead of answering
        // on the merits. The 3.0.x line has since been declared unsupported, so the reason is gone;
        // the choice is left as it is — there are no arguments FOR switching to `getOption()`, and
        // replacing a working path for the sake of symmetry would add risk without a gain. `prepare()`
        // reinstalls nothing in a process that is already prepared.
        $corePath = TestbenchKernel::instance()->prepare()->corePath();
        $autoload = $corePath . 'vendor/autoload.php';

        self::assertFileExists($autoload);

        $loader = require $autoload;

        self::assertInstanceOf(ClassLoader::class, $loader);

        return $loader;
    }

    /**
     * Repeats the transformation of `modX::runProcessor()`
     * (`core/src/Revolution/modX.php:1795-1801`), the special case `Tv` → `TemplateVar` included. An
     * action already given as a class name (one containing a backslash) is taken as it is —
     * `runProcessor()` checks the class FIRST for such an action, before any transformation.
     */
    private function processorClassOf(string $action): string
    {
        if (str_contains($action, '\\')) {
            return $action;
        }

        $class = 'MODX\\Revolution\\Processors\\' . implode('\\', array_map(ucfirst(...), explode('/', $action)));

        return str_replace('\\Tv\\', '\\TemplateVar\\', $class);
    }

    /**
     * `file_exists()` is useless here: on macOS it answers "yes" for both `Scanlocal.php` and
     * `ScanLocal.php`. The only source of the real name is enumerating the directory, so the file
     * name is compared against the `scandir()` listing by strict comparison.
     */
    private function existsCaseSensitively(string $file): bool
    {
        $entries = scandir(dirname($file));

        return $entries !== false && in_array(basename($file), $entries, true);
    }

    /**
     * The name the file actually carries in the directory — for the error message.
     */
    private function actualNameOf(string $file): string
    {
        $entries = scandir(dirname($file));

        if ($entries === false) {
            return '(the directory is unreadable)';
        }

        foreach ($entries as $entry) {
            if (strcasecmp($entry, basename($file)) === 0) {
                return $entry;
            }
        }

        return '(there is no file)';
    }

    /**
     * The processor actions given in `src/` as string literals, together with the place of the call.
     *
     * @return array<string, string> action => `file:line` of the first occurrence
     */
    private function processorActionsOfSources(): array
    {
        $actions = [];
        $root = dirname(__DIR__, 3) . '/src';

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match_all("/runProcessor\(\s*+'([^']++)'/", $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[1] as [$action, $offset]) {
                $line = substr_count($contents, "\n", 0, $offset) + 1;
                $actions[$action] ??= sprintf(
                    '%s:%d',
                    substr($file->getPathname(), strlen(dirname($root)) + 1),
                    $line
                );
            }
        }

        return $actions;
    }
}

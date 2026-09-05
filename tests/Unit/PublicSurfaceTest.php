<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * What the package promises, and what it merely happens to expose.
 *
 * Publishing turns every public method into a semver contract, including the ones nobody meant as
 * an offer: `Workspace::destroy()`, `DatabaseCleaner::purgeInstallation()`, `PhpDumper` whole. This
 * test is where the difference is written down — the list below is the package's public surface,
 * and everything outside it carries `@internal`.
 *
 * The direction of the check is deliberate. It does NOT demand that the listed classes stay
 * unmarked: a class can be demoted at any time. It demands that a class OUTSIDE the list carry the
 * marker — so that a new class arrives with the decision made rather than made by default. After
 * the first tag the default costs a major version.
 *
 * The docblock is read through reflection rather than by grepping the file: an annotation attached
 * to the wrong thing (a docblock separated from its class by a stray line) is exactly the defect
 * {@see DocblockPlacementTest} exists for, and `getDocComment()` is what tells the two apart.
 */
#[Group('unit')]
final class PublicSurfaceTest extends TestCase
{
    /**
     * The classes a consumer is invited to use, each with what invites it.
     *
     * "Disputed, left public" is a decision too, and the reason for it is uniform: an annotation
     * that wrongly says "internal" about something the consumer may rely on is a promise broken in
     * the direction that costs the consumer, while one missing `@internal` costs a major version at
     * worst. Where the answer was not clear, the class stayed out of the marked set.
     *
     * @var array<class-string, string>
     */
    private const PUBLIC_SURFACE = [
        // Named in docs/DX_GUIDE.md and docs/SPEC.md as what a consumer writes tests against.
        \ModxKit\Testbench\TestCase::class => 'level 2 base class (DX guide §1)',
        \ModxKit\Testbench\Unit\UnitTestCase::class => 'level 1 base class (DX guide §1)',
        \ModxKit\Testbench\Concerns\InteractsWithModx::class => 'createResource/runProcessor etc. (DX guide §5)',
        \ModxKit\Testbench\Concerns\RefreshesDatabase::class => 'named by FQCN in the DX guide',
        \ModxKit\Testbench\Package\PackageDefinition::class => 'the consumer describes their extra with it (DX guide §3)',
        \ModxKit\Testbench\Package\TransportInstaller::class => 'named by FQCN in the DX guide (§ transport package)',
        \ModxKit\Testbench\Stubs\TestbenchModx::class => 'the `$this->modx` of level 1 (DX guide §6)',
        \ModxKit\Testbench\Stubs\LogRecorder::class => 'handed to the consumer by TestbenchModx; the log assertions of level 1',
        \ModxKit\Testbench\PHPUnit\ExposureWarningExtension::class => 'the consumer registers it in their own phpunit.xml',

        // Contracts printed with their signatures in docs/SPEC.md — an interface somebody else may
        // implement is the last thing to declare internal.
        \ModxKit\Testbench\Isolation\IsolationStrategy::class => 'interface, SPEC §Isolation\\IsolationStrategy',
        \ModxKit\Testbench\Environment\Provider\CoreProvider::class => 'interface, SPEC §Environment\\Provider\\CoreProvider',

        // The whole exception hierarchy: a consumer catches these by name, and the DX guide has a
        // table of them with what each one means.
        \ModxKit\Testbench\Exception\TestbenchException::class => 'the root of the hierarchy the consumer catches',
        \ModxKit\Testbench\Exception\CoreDownloadFailedException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\CoreTransportUnpackException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\InstallationFailedException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\KernelBootFailedException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\PackageRegistrationException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\SecretFreeMessage::class => 'marker interface of the hierarchy',
        \ModxKit\Testbench\Exception\SnapshotFailedException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\TransactionLostException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\TransactionNotStartedException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\UnsupportedStubOperationException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\WorkspaceLocationException::class => 'exception hierarchy',
        \ModxKit\Testbench\Exception\WorkspaceOwnershipException::class => 'exception hierarchy',

        // Disputed, left public.
        \ModxKit\Testbench\Environment\TestbenchKernel::class => 'disputed: the entry point the bootstrap and the commands go through, and the only way to ask where the environment is from PHP',
        \ModxKit\Testbench\Environment\Workspace::class => 'disputed: README offers exposedSecretFiles() to the consumer; the destructive methods carry @internal one by one instead',
        \ModxKit\Testbench\Environment\TestbenchConfig::class => 'disputed: the read-back of the MODX_TESTBENCH_* variables the consumer sets',
        \ModxKit\Testbench\Environment\DatabaseConfig::class => 'disputed: a component of TestbenchConfig, reachable through it',
        \ModxKit\Testbench\Environment\AdminConfig::class => 'disputed: a component of TestbenchConfig, reachable through it',
        \ModxKit\Testbench\Environment\CoreLocation::class => 'disputed: the return type of CoreProvider::provide(), which is a public interface',
        \ModxKit\Testbench\Database\Dumper::class => 'disputed: an interface, and SPEC describes the pair of implementations behind it',
        \ModxKit\Testbench\Support\CommandRunner::class => 'disputed: an interface; nothing offers it as an extension point, and nothing forbids it either',
    ];

    public function testEverythingOutsideThePublicSurfaceIsMarkedInternal(): void
    {
        $classes = $this->packageClasses();

        // Premise: the traversal found the package. An empty one would go green having checked
        // nothing, and this check has no fixture of its own to fall over.
        self::assertGreaterThan(50, count($classes), 'The traversal found almost no package classes.');

        $unmarked = [];

        foreach ($classes as $class) {
            if (isset(self::PUBLIC_SURFACE[$class])) {
                continue;
            }

            if (!str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal')) {
                $unmarked[] = $class;
            }
        }

        sort($unmarked);

        self::assertSame(
            [],
            $unmarked,
            "A class is neither on the package's public surface nor marked @internal. Decide which "
            . "it is: add it to PUBLIC_SURFACE with the reason, or annotate the class @internal.\n"
            . implode("\n", $unmarked)
        );
    }

    /**
     * The mirror half. Without it the list above could quietly grow until it covered everything,
     * and the check would keep passing while promising more with every entry.
     */
    public function testThePublicSurfaceIsTheSmallerHalf(): void
    {
        $classes = $this->packageClasses();
        $marked = 0;

        foreach ($classes as $class) {
            if (str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal')) {
                ++$marked;
            }
        }

        self::assertGreaterThan(
            count(self::PUBLIC_SURFACE),
            $marked,
            'More of the package is promised than is kept back — check what has been added to PUBLIC_SURFACE.'
        );

        // Every entry of the list must still exist: a renamed class would otherwise leave a stale
        // promise behind and, worse, would arrive unmarked without anybody noticing.
        foreach (array_keys(self::PUBLIC_SURFACE) as $class) {
            self::assertContains($class, $classes, $class . ' is on the public surface list but not in src/.');
        }
    }

    /**
     * The destructive methods of classes that stay public: the class is offered to the consumer,
     * these particular methods are not.
     */
    public function testTheDestructiveMethodsOfPublicClassesAreMarkedInternal(): void
    {
        $expected = [
            [\ModxKit\Testbench\Environment\Workspace::class, 'destroy'],
            [\ModxKit\Testbench\Environment\Workspace::class, 'ensureExists'],
            [\ModxKit\Testbench\Environment\Workspace::class, 'writeLock'],
            [\ModxKit\Testbench\Environment\Workspace::class, 'prepareStaging'],
            [\ModxKit\Testbench\Environment\Workspace::class, 'adoptStagedCore'],
            [\ModxKit\Testbench\Environment\TestbenchKernel::class, 'reset'],
        ];

        $unmarked = [];

        foreach ($expected as [$class, $method]) {
            $reflection = new ReflectionClass($class);

            self::assertTrue($reflection->hasMethod($method), $class . '::' . $method . '() no longer exists.');

            if (!str_contains((string) $reflection->getMethod($method)->getDocComment(), '@internal')) {
                $unmarked[] = $class . '::' . $method . '()';
            }
        }

        self::assertSame([], $unmarked, "Machinery on a public class must say so:\n" . implode("\n", $unmarked));
    }

    /**
     * @return list<class-string>
     */
    private function packageClasses(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $classes = [];

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if (!$item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($root) + 1, -strlen('.php'));
            $class = 'ModxKit\\Testbench\\' . str_replace('/', '\\', $relative);

            self::assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class),
                $class . ' does not load — the file layout and the namespace have diverged.'
            );

            /** @var class-string $class */
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}

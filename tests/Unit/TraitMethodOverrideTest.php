<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use FilesystemIterator;
use ModxKit\Testbench\Tests\Support\CapturesWarnings;
use ModxKit\Testbench\Tests\Support\OwnsTestbenchEnvironment;
use ModxKit\Testbench\Tests\Unit\Environment\TestbenchConfigTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Catches a class that has silently overridden a method taken from a trait.
 *
 * The mechanism: a method declared in the body of a class always beats a trait method of the same
 * name, and neither PHP nor PHPUnit says anything about it. For an ordinary method that is
 * documented behaviour of the language; for `setUp()`/`tearDown()` it is a loss of ownership over
 * what the trait had taken on: PHPUnit calls only the method of the class, and the trait's
 * preparation never runs. How that ends depends on the trait and on the machine: on a run here,
 * planting such a `setUp()` into {@see TestbenchConfigTest} reddened five tests with diagnostics
 * about a foreign DSN, never once naming the cause.
 *
 * `final` on a trait method is no insurance against this: measured on PHP 8.4.8 — a class with its
 * own `setUp()` over a trait with `final protected function setUp()` compiles and executes the
 * method of the class, without an error and without a warning.
 *
 * ANY trait methods are checked, not only the lifecycle ones. The occasion was measured on this
 * very repository: `WorkspaceTest` held a private `captureWarnings()` repeating the method of the
 * trait {@see CapturesWarnings} word for word — the class did not mix that trait in, so there was
 * no silent override, but had anyone mixed it in, the method of the class would have won silently.
 * The earlier edition of the guard, which knew only `setUp`/`tearDown`, would not have seen that.
 *
 * The boundary of the check is the traits attached DIRECTLY to the class
 * ({@see ReflectionClass::getTraits()}). An override of a method inherited from a parent is not
 * looked for here: it has a regular way out (`parent::setUp()`), visible in the body of the method.
 *
 * Three things clear the suspicion, and all three mean a deliberate arrangement:
 *
 * - an alias ({@see ReflectionClass::getTraitAliases()}): the class gave the trait method a second
 *   name;
 * - an `abstract` trait method: that is its contract with the class, and an implementation in the
 *   class fulfils the contract rather than intercepting it;
 * - a conflict between two traits resolved with `insteadof`: the winning method came from ANOTHER
 *   trait rather than from the body of the class — visible from the declaring file and line.
 */
#[Group('unit')]
final class TraitMethodOverrideTest extends TestCase
{
    public function testNoClassSilentlyOverridesAMethodTakenFromATrait(): void
    {
        $classes = $this->packageClasses();

        self::assertContains(
            TestbenchConfigTest::class,
            $classes,
            'Premise of the test: the traversal sees a consumer of a lifecycle trait. An empty '
            . 'or incomplete traversal would go green having checked nothing.'
        );

        self::assertSame(
            [],
            $this->overrides($classes),
            'A method of the class beats a trait method of the same name silently. Either remove your '
            . 'own method, or give the trait method an alias and call it explicitly.'
        );
    }

    /**
     * Mutation insurance for the scan itself: the offending class is declared right here, so the
     * check above cannot go green by construction. It does not fall into the general traversal —
     * {@see self::packageClasses()} derives class names from file paths, that is, sees exactly one
     * class per file, named after the file.
     */
    public function testTheScanSeesAClassThatOverridesATraitLifecycleMethod(): void
    {
        self::assertSame(
            [ClassOverridingTraitTearDown::class . '::tearDown() overrides ' . OwnsTestbenchEnvironment::class],
            $this->overrides([ClassOverridingTraitTearDown::class])
        );
    }

    /**
     * A deliberate arrangement of a trait method is not a violation: the class gave it a second name
     * and calls it itself.
     */
    public function testAliasedTraitMethodIsNotReportedAsAnOverride(): void
    {
        self::assertSame([], $this->overrides([ClassAliasingTraitSetUp::class]));
    }

    /**
     * An ordinary trait method overridden by a class is just as silent as a lifecycle one. The
     * sample repeats a real case — the private copy of `captureWarnings()` that `WorkspaceTest`
     * used to hold.
     */
    public function testTheScanSeesAClassThatOverridesAnOrdinaryTraitMethod(): void
    {
        self::assertSame(
            [ClassOverridingOrdinaryTraitMethod::class . '::captureWarnings() overrides ' . CapturesWarnings::class],
            $this->overrides([ClassOverridingOrdinaryTraitMethod::class])
        );
    }

    /**
     * The false positive the check parses declarations for: a conflict between two traits resolved
     * with `insteadof`. The winning method came from another trait rather than from the body of the
     * class — there is nothing to report.
     */
    public function testTraitConflictResolvedWithInsteadofIsNotReportedAsAnOverride(): void
    {
        self::assertSame([], $this->overrides([ClassResolvingTraitConflict::class]));
    }

    /**
     * The second false positive: an `abstract` trait method is its requirement upon the class. A
     * class that implemented it intercepted nothing.
     */
    public function testClassImplementingAnAbstractTraitMethodIsNotReportedAsAnOverride(): void
    {
        self::assertSame([], $this->overrides([ClassImplementingAbstractTraitMethod::class]));
    }

    /**
     * @param list<class-string> $classes
     *
     * @return list<string>
     */
    private function overrides(array $classes): array
    {
        $found = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $aliased = array_values($reflection->getTraitAliases());

            foreach ($reflection->getTraits() as $trait) {
                foreach ($trait->getMethods() as $inherited) {
                    $method = $inherited->getName();

                    // The trait's contract with the class: implementing it is an obligation, not an interception.
                    if ($inherited->isAbstract()) {
                        continue;
                    }

                    if (in_array($trait->getName() . '::' . $method, $aliased, true)) {
                        continue;
                    }

                    $own = $reflection->getMethod($method);

                    // A method that came from a trait is literally the same one: the same file and the same
                    // declaration line. An overridden one points at a declaration in the class.
                    if ($this->sameDeclaration($own, $inherited)) {
                        continue;
                    }

                    // `insteadof`: the winner was a method of ANOTHER trait of the same class rather than the
                    // body of the class. The conflict is resolved explicitly, and there is no silence here.
                    if ($this->declaredByAnotherTraitOf($reflection, $own)) {
                        continue;
                    }

                    $found[] = sprintf('%s::%s() overrides %s', $class, $method, $trait->getName());
                }
            }
        }

        return $found;
    }

    /**
     * One and the same declaration: the same file and the same line. The name of the declaring
     * class is not enough here — for a method that came from a trait, `getDeclaringClass()` names
     * the class itself.
     */
    private function sameDeclaration(ReflectionMethod $own, ReflectionMethod $inherited): bool
    {
        return $own->getFileName() === $inherited->getFileName()
            && $own->getStartLine() === $inherited->getStartLine();
    }

    /**
     * Whether the method is declared in some OTHER trait of the same class.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function declaredByAnotherTraitOf(ReflectionClass $reflection, ReflectionMethod $own): bool
    {
        foreach ($reflection->getTraits() as $trait) {
            if ($trait->hasMethod($own->getName())
                && $this->sameDeclaration($own, $trait->getMethod($own->getName()))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class name is derived from the path, but is checked against the contents of the file
     * before loading: under `tests/` there are also files whose name is not a class (the sample
     * extra in `Fixtures/` declares classes of a foreign namespace, the race participant script
     * declares nothing at all). Composer's autoloader would include such a file on a PATH match and
     * execute it, and the class declared in it would then be unable to declare itself a second time.
     */
    private function declares(string $file, string $class): bool
    {
        $contents = (string) file_get_contents($file);
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $contents, $matches) === 1
            ? trim($matches[1]) . '\\'
            : '';

        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $matches) !== 1) {
            return false;
        }

        return $namespace . $matches[1] === $class;
    }

    /**
     * @return list<class-string>
     */
    private function packageClasses(): array
    {
        $root = dirname(__DIR__, 2);
        $classes = [];

        foreach (['/src' => 'ModxKit\\Testbench\\', '/tests' => 'ModxKit\\Testbench\\Tests\\'] as $directory => $prefix) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $directory, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($items as $item) {
                /** @var SplFileInfo $item */
                if (!$item->isFile() || $item->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($item->getPathname(), strlen($root . $directory) + 1, -4);
                $candidate = $prefix . str_replace('/', '\\', $relative);

                if ($this->declares($item->getPathname(), $candidate) && class_exists($candidate)) {
                    $classes[] = $candidate;
                }
            }
        }

        sort($classes);

        return $classes;
    }
}

/**
 * The offender for {@see TraitMethodOverrideTest::testTheScanSeesAClassThatOverridesATraitLifecycleMethod()}:
 * a `tearDown()` of its own over a trait that already supplies one.
 */
final class ClassOverridingTraitTearDown
{
    use OwnsTestbenchEnvironment;

    protected function tearDown(): void
    {
        unset($_SERVER['MODX_TESTBENCH_PROVIDER']);
    }
}

/**
 * The counter-sample for {@see TraitMethodOverrideTest::testAliasedTraitMethodIsNotReportedAsAnOverride()}:
 * the trait method got a second name and is called explicitly.
 */
final class ClassAliasingTraitSetUp
{
    use OwnsTestbenchEnvironment {
        setUp as takeOverTestbenchEnvironment;
    }

    protected function setUp(): void
    {
        $this->takeOverTestbenchEnvironment();
    }
}

/**
 * The offender for {@see TraitMethodOverrideTest::testTheScanSeesAClassThatOverridesAnOrdinaryTraitMethod()}:
 * a copy of its own of an ordinary (non-lifecycle) trait method.
 */
final class ClassOverridingOrdinaryTraitMethod
{
    use CapturesWarnings;

    /**
     * @param callable(): void $run
     *
     * @return list<string>
     */
    protected function captureWarnings(callable $run): array
    {
        $run();

        return [];
    }
}

/** The first party to the conflict for {@see ClassResolvingTraitConflict}. */
trait FirstGreeting
{
    public function greet(): string
    {
        return 'first';
    }
}

/** The second party to the conflict for {@see ClassResolvingTraitConflict}. */
trait SecondGreeting
{
    public function greet(): string
    {
        return 'second';
    }
}

/**
 * The counter-sample for {@see TraitMethodOverrideTest::testTraitConflictResolvedWithInsteadofIsNotReportedAsAnOverride()}:
 * the same-named methods of two traits are separated with `insteadof`, and the body of the class
 * declares no `greet()` of its own.
 */
final class ClassResolvingTraitConflict
{
    use FirstGreeting, SecondGreeting {
        FirstGreeting::greet insteadof SecondGreeting;
    }
}

/** A trait with a requirement upon the class — for {@see ClassImplementingAbstractTraitMethod}. */
trait DemandsAName
{
    abstract public function name(): string;

    public function greeting(): string
    {
        return 'hello, ' . $this->name();
    }
}

/**
 * The counter-sample for {@see TraitMethodOverrideTest::testClassImplementingAnAbstractTraitMethodIsNotReportedAsAnOverride()}:
 * the class fulfils the trait's contract rather than intercepting its implementation.
 */
final class ClassImplementingAbstractTraitMethod
{
    use DemandsAName;

    public function name(): string
    {
        return 'guard';
    }
}

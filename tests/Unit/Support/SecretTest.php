<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Support;

use FilesystemIterator;
use LogicException;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Environment\Workspace;
use ModxKit\Testbench\Exception\SecretFreeMessage;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Exception\WorkspaceLocationException;
use ModxKit\Testbench\Exception\WorkspaceOwnershipException;
use ModxKit\Testbench\Support\Secret;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

#[Group('unit')]
final class SecretTest extends TestCase
{
    public function testReplacesEveryOccurrenceOfEverySecret(): void
    {
        self::assertSame(
            'user=root password=*** retry with *** or ***',
            Secret::mask('user=root password=s3cret retry with s3cret or other', 's3cret', 'other')
        );
    }

    public function testEmptySecretLeavesTheTextAlone(): void
    {
        // An empty password is a working setting: str_replace('' , '***') would spoil the whole text.
        self::assertSame('access denied', Secret::mask('access denied', ''));
    }

    public function testTextWithoutSecretsIsReturnedAsIs(): void
    {
        self::assertSame('all good', Secret::mask('all good'));
    }

    /**
     * The user-facing symptom, pinned one level below the command that showed it: the package
     * refuses `/` as a working directory and names `/tmp/modx-testbench` as the thing to use
     * instead. Under the package's own password (`testbench`, `ci/docker-compose.yml`) blind
     * masking turned that recommendation into `(/tmp/modx-***, for example)` — the user was
     * told to look for the very name that had been hidden from them.
     *
     * Built by calling `Workspace::forConfig()` rather than by quoting its message: a copy of
     * the text here would keep passing after someone reworded the real one.
     */
    public function testTheRefusalOfTheFilesystemRootKeepsTheDirectoryItRecommends(): void
    {
        $failure = null;

        try {
            Workspace::forConfig($this->configWithWorkspaceDir('/'));
        } catch (TestbenchException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(WorkspaceLocationException::class, $failure);
        self::assertStringContainsString('/tmp/modx-testbench', $failure->getMessage());
        self::assertSame($failure->getMessage(), Secret::maskMessage($failure, 'testbench'));
    }

    public function testAnOrdinaryExceptionIsStillMaskedByItsMessage(): void
    {
        $exception = new TestbenchException('connection to db as user=root password=s3cret failed');

        self::assertSame(
            'connection to db as user=root password=*** failed',
            Secret::maskMessage($exception, 's3cret')
        );
    }

    /**
     * The exemption may not grow by accident. Masking is the one measure here that cannot be
     * walked back once it has failed — a real password in someone else's CI log stays there —
     * so every type that opts out of it is named here, and adding one is a change to this test
     * rather than a side effect of an edit elsewhere.
     *
     * Two separate questions, and both have to go to the language rather than to the text:
     *
     * 1. WHICH types exist. Enumerated by tokenising every file for every `class`/`interface`/
     *    `enum`/`trait` declaration it contains. Deriving one PSR-4 name per path — the earlier
     *    revision — silently missed a second class declared at the bottom of an existing file,
     *    and neither cs-fixer nor PHPStan sees that either (measured).
     * 2. WHETHER a type carries the marker. `is_subclass_of()` answers for every way the marker
     *    can arrive: declared literally, inherited from a derived marker interface, reached
     *    through an import alias, or picked up from a parent class (all four measured).
     *
     * Loading is guarded rather than assumed: `Stubs/TestbenchModx.php` extends
     * `MODX\Revolution\modX`, and whether that exists depends on whether `bootstrap.php` managed
     * to register `CoreAutoloader` on a prepared workspace — so the same file loads under the
     * suite and raises `Error` under a bare `php -r` (both measured). The `Error` is catchable,
     * and what is asserted is that skipping happens ONLY for types named here: a skip list free
     * to grow in silence would hollow the scan out from the inside, and an exact match would
     * just be flaky between environments.
     *
     * Named limit: the scan asks about `src/`. A marked type declared elsewhere — in `tests/`,
     * or in consumer code — is not covered. `maskMessage()` is not called from either.
     */
    public function testOnlyTheAuditedExceptionTypesOptOutOfMasking(): void
    {
        $found = [];
        $unloadable = [];
        $scanned = 0;

        foreach ($this->packageDeclarations() as $file => $names) {
            foreach ($names as $name) {
                try {
                    $defined = class_exists($name) || interface_exists($name) || trait_exists($name);
                } catch (Throwable) {
                    $unloadable[] = $name;

                    continue;
                }

                // A declaration the tokeniser found is defined by definition once its file is
                // loaded; `false` here would mean the two disagree, which is itself a finding.
                self::assertTrue($defined, $name . ' was declared in ' . $file . ' but is not defined after loading.');

                $scanned++;

                if (is_subclass_of($name, SecretFreeMessage::class)) {
                    $found[] = $file;
                }
            }
        }

        // An empty result proves something only together with the sample size.
        self::assertGreaterThan(50, $scanned, 'The declaration scan found almost nothing — it is looking in the wrong place.');
        self::assertSame(
            [],
            array_values(array_diff($unloadable, [\ModxKit\Testbench\Stubs\TestbenchModx::class])),
            'A type declared in `src/` could not be loaded, so the marker scan never asked about it.'
        );

        sort($found);
        self::assertSame(
            ['Exception/WorkspaceLocationException.php', 'Exception/WorkspaceOwnershipException.php'],
            $found
        );
    }

    /**
     * The guard that makes {@see WorkspaceLocationException} safe regardless of how it is
     * called. Dynamic dispatch defeats any scan of the sources — `$class::filesystemRoot()`,
     * `call_user_func`, reflection — so the proof was moved onto the argument, where the way
     * the call is written stops mattering.
     *
     * The second assertion is the one that is easy to lose: the refusal must not quote what it
     * refused. A `LogicException` is not a `TestbenchException`, so nothing masks it on its way
     * out — quoting the argument would reopen, inside the guard, the very hole it closes.
     */
    public function testTheFilesystemRootFactoryRefusesAnythingButTheRoot(): void
    {
        $pdoMessage = 'SQLSTATE[HY000] [1045] Access denied for user "root" (using password: testbench)';

        try {
            WorkspaceLocationException::filesystemRoot($pdoMessage);
            self::fail('The factory accepted a path that is not the filesystem root.');
        } catch (LogicException $refusal) {
            self::assertStringNotContainsString($pdoMessage, $refusal->getMessage());
            self::assertStringNotContainsString('testbench', $refusal->getMessage());
        }

        // The legitimate arguments still pass, and the message still names the replacement.
        foreach (['/', '//', '///'] as $root) {
            self::assertStringContainsString(
                '/tmp/modx-testbench',
                Secret::maskMessage(WorkspaceLocationException::filesystemRoot($root), 'testbench')
            );
        }
    }

    /**
     * A second net under the exempt types, and only a net — read the limit at the bottom.
     *
     * What the exemption rests on is a proof about the CALL SITE: the factories take strings,
     * and only the caller knows where its string came from. `WorkspaceLocationException::
     * filesystemRoot()` no longer depends on this test at all — it checks its own argument, so
     * no caller can get an unmasked message out of it however the call is written. For
     * `WorkspaceOwnershipException` no such check exists: the path there IS a consumer-chosen
     * directory name, and no predicate separates that from arbitrary text. Its callers are
     * therefore pinned by name.
     *
     * Names are resolved rather than matched: the scan tokenises each file, parses its namespace
     * and every shape of `use` statement — plain, aliased, the list form `use A\\B, C\\D;` and the
     * group form `use Ns\\{A, B};` — and resolves every `Name::method()` to a fully qualified
     * name. Which shapes those are is not a guess any more. Rounds 2 to 5 each found spellings
     * that were perfectly ordinary static PHP — the import alias, then the group and list `use`,
     * then six more, then a leading separator inside a string class name and the fact
     * that PHP compares class names case-insensitively at all. Every one was measured, and every
     * one was fixed rather than named. What finally settled the question was not another list of
     * shapes but brute force: all 92 PHP reserved words were put through both `<kw>::m()` and
     * `Holder::<kw>::m()`, which separates the token kinds that can carry a class name from the
     * far larger set that can only stand there as a member name. That measurement and what
     * follows from it are in {@see self::resolveCallee()}.
     *
     * Named limits, and the first one is the only one of its kind:
     * - The name is a VALUE rather than text: `$class::notOurs()`, `call_user_func()`,
     *   reflection, a parenthesised expression, an array element, a property, and a class
     *   constant such as `Holder::NAME::notOurs()` — where the name is written in the constant
     *   and not at the call site. No scan of these sources can close that, which is exactly why
     *   {@see \ModxKit\Testbench\Exception\WorkspaceLocationException::filesystemRoot()} carries
     *   its own check instead of relying on this test. Anything statically written, however it
     *   is spelled, belongs in the paragraph above and is a defect if it is missed.
     * - The scan covers `src/` only. A caller in `tests/` is not seen.
     */
    public function testTheExemptExceptionTypesAreBuiltOnlyWhereTheirProofHolds(): void
    {
        $calls = [];
        $scanned = 0;
        $scan = $this->packageStaticCalls();

        // Folded, because PHP resolves class names case-insensitively: `\modx\testbench\
        // exception\workspaceownershipexception::notOurs()` reaches the same factory (measured).
        $exempt = array_map(
            strtolower(...),
            [WorkspaceLocationException::class, WorkspaceOwnershipException::class]
        );

        foreach ($scan['calls'] as $file => $sites) {
            $scanned++;

            foreach ($sites as $site) {
                if (!in_array(strtolower($site['class']), $exempt, true)) {
                    continue;
                }

                $calls[] = sprintf('%s → %s::%s()', $file, $this->shortName($site['class']), $site['method']);
            }
        }

        // The sample size is asserted alongside the findings.
        self::assertGreaterThan(50, $scanned, 'The call scan found almost nothing — it is looking in the wrong place.');

        // `self::` and `static::` inside a trait belong to whichever class composes the trait,
        // not to the trait, so the resolution above reports the trait's own name there. That can
        // only hide a caller if the trait is composed into an exempt type, since `notOurs()` and
        // `filesystemRoot()` exist nowhere else and no subclass can inherit them. Both premises
        // are asserted rather than argued: the second one used to live in this comment alone, and
        // lifting `final` off an exempt type left the scan green (measured, adjudication).
        foreach ([WorkspaceLocationException::class, WorkspaceOwnershipException::class] as $type) {
            self::assertTrue(
                (new ReflectionClass($type))->isFinal(),
                $type . ' is no longer final, so a subclass can reach its factories '
                    . 'through a trait unseen.'
            );

            self::assertSame(
                [],
                $scan['traits'][strtolower($type)] ?? [],
                $type . ' composes a trait, so `self::` written inside that trait now '
                    . 'reaches its factories unseen.'
            );
        }

        sort($calls);
        self::assertSame(
            [
                // Guarded by the factory itself, so this entry is bookkeeping, not the proof.
                'Environment/Workspace.php → WorkspaceLocationException::filesystemRoot()',
                // A consumer-chosen path plus the marker name — both printed raw before the
                // marker interface existed too, so the exemption here is inherited, not new.
                // These two entries ARE the proof, and it is a proof about this file.
                'Environment/Workspace.php → WorkspaceOwnershipException::cannotMark()',
                'Environment/Workspace.php → WorkspaceOwnershipException::notOurs()',
            ],
            $calls
        );
    }

    /**
     * Every type declared anywhere in `src/`, keyed by the file that declares it. A file may
     * declare several, and PSR-4 names only the first — which is the whole point of tokenising
     * instead of deriving a name from the path.
     *
     * @return array<string, list<string>>
     */
    private function packageDeclarations(): array
    {
        $declarations = [];

        foreach ($this->packageFiles() as $relative => $path) {
            $tokens = token_get_all((string) file_get_contents($path));
            $namespace = '';
            $names = [];

            foreach ($tokens as $index => $token) {
                if (!is_array($token)) {
                    continue;
                }

                if ($token[0] === T_NAMESPACE) {
                    $namespace = $this->readName($tokens, $index);

                    continue;
                }

                if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    continue;
                }

                // `Foo::class` is a constant, not a declaration; `new class {}` has no name.
                $previous = $this->significantToken($tokens, $index, -1);

                if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    continue;
                }

                $name = $this->significantToken($tokens, $index, 1);

                if (!is_array($name) || $name[0] !== T_STRING) {
                    continue;
                }

                $names[] = $namespace === '' ? $name[1] : $namespace . '\\' . $name[1];
            }

            if ($names !== []) {
                $declarations[$relative] = $names;
            }
        }

        return $declarations;
    }

    /**
     * Every `Name::method()` of `src/`, with `Name` resolved to a fully qualified name, plus the
     * traits each type composes.
     *
     * The enclosing type and its parent are tracked alongside the namespace and the imports,
     * because `self::`, `static::` and `parent::` are class names too and are decidable from the
     * same token stream. Brace depth is tracked for two reasons: a `use` inside a class body
     * composes a trait rather than importing anything, and a type's scope has to end where its
     * body ends — an anonymous class installs an EMPTY scope, so `self::` inside one resolves to
     * nothing rather than to the type around it.
     *
     * @return array{calls: array<string, list<array{class: string, method: string}>>, traits: array<string, list<string>>}
     */
    private function packageStaticCalls(): array
    {
        $calls = [];
        $composed = [];

        foreach ($this->packageFiles() as $relative => $path) {
            $tokens = token_get_all((string) file_get_contents($path));
            $namespace = '';
            $enclosing = '';
            $parent = '';
            $depth = 0;
            $importDepth = 0;
            $aliases = [];
            $scopes = [];
            $pending = null;
            $pendingIndex = null;
            $sites = [];

            foreach ($tokens as $index => $token) {
                if (!is_array($token)) {
                    if ($token === '{') {
                        $depth++;

                        if ($pendingIndex === $index && $pending !== null) {
                            $scopes[$depth] = [$enclosing, $parent];
                            [$enclosing, $parent] = $pending;
                            $pending = null;
                            $pendingIndex = null;
                        }

                        continue;
                    }

                    if ($token === '}') {
                        if (isset($scopes[$depth])) {
                            [$enclosing, $parent] = $scopes[$depth];
                            unset($scopes[$depth]);
                        }

                        $depth = max(0, $depth - 1);
                    }

                    continue;
                }

                // `"{$x}"` and `"${x}"` open a brace that closes as a plain `}`.
                if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;

                    continue;
                }

                // Every keyword below is also a legal class-constant or property name, and PHP
                // hands back the keyword token there too: `Holder::use::m()` and `$o->class` both
                // lint. Behind `::`, `->` or `?->` a keyword names a member, never a declaration.
                if (in_array($token[0], [T_NAMESPACE, T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                    && $this->isMemberName($tokens, $index)) {
                    continue;
                }

                if ($token[0] === T_NAMESPACE) {
                    $namespace = $this->readName($tokens, $index);
                    // `namespace Foo { … }` puts the whole file one brace deeper.
                    $importDepth = $this->namespaceIsBraced($tokens, $index) ? 1 : 0;

                    continue;
                }

                if ($token[0] === T_USE) {
                    if ($depth === $importDepth) {
                        $aliases = array_merge($aliases, $this->readImports($tokens, $index));
                    } elseif ($enclosing !== '') {
                        foreach ($this->readComposedTraits($tokens, $index, $namespace, $aliases) as $trait) {
                            // Folded like the call comparison below: a class may be DECLARED in a
                            // case other than the one its consumers write (measured, adjudication).
                            $composed[strtolower($enclosing)][] = $trait;
                        }
                    }

                    continue;
                }

                if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $name = $this->significantToken($tokens, $index, 1);
                    $body = $this->bodyBraceIndex($tokens, $index);

                    if ($body === null) {
                        continue;
                    }

                    // An anonymous class has no name to answer `self::` with, so it installs an
                    // empty one rather than leaving the enclosing type's name standing.
                    $declared = is_array($name) && $name[0] === T_STRING
                        ? ($namespace === '' ? $name[1] : $namespace . '\\' . $name[1])
                        : '';

                    $pending = [$declared, $this->readExtends($tokens, $index, $namespace, $aliases)];
                    $pendingIndex = $body;

                    continue;
                }

                if ($token[0] !== T_DOUBLE_COLON) {
                    continue;
                }

                $right = $this->significantToken($tokens, $index, 1);
                $after = $this->significantToken($tokens, $index, 2);

                if (!is_array($right) || $right[0] !== T_STRING || $after !== '(') {
                    continue;
                }

                $class = $this->resolveCallee($tokens, $index, $namespace, $aliases, $enclosing, $parent);

                if ($class === null) {
                    continue;
                }

                $sites[] = ['class' => $class, 'method' => $right[1]];
            }

            $calls[$relative] = $sites;
        }

        return ['calls' => $calls, 'traits' => $composed];
    }

    /**
     * The class named to the left of the `::` at `$index`, fully qualified, or `null` when the
     * name is not written there at all.
     *
     * Which token kinds can stand there was settled by brute force rather than by listing the
     * shapes that came to mind — the method three rounds in a row missed one. Every PHP reserved
     * word was put through `Holder::<kw>::m()` and through `<kw>::m()` and linted: one pass
     * enumerated the shapes, a later one ran the words. The result splits in two:
     *
     * - A class NAME can be spelled only as `T_STATIC` or one of the four name tokens
     *   (`T_STRING`, `T_NAME_QUALIFIED`, `T_NAME_FULLY_QUALIFIED`, `T_NAME_RELATIVE`) — of 92
     *   reserved words, exactly 16 are accepted as a bare class name and they arrive as
     *   `T_STATIC` or `T_STRING`. All of these are resolved here, `self` and `parent` included.
     * - Anything else to the left of `::` is the tail of an EXPRESSION. 77 token kinds in all
     *   can stand there, because all 92 words are legal after `::` as a class-constant name
     *   (`Holder::print::m()` and `Holder::for::m()` lint), and 75 of the 77 can never carry a
     *   name — the other two are the `T_STATIC` and `T_STRING` above. Two of those tails
     *   carry a name anyway and are resolved: `T_CONSTANT_ENCAPSED_STRING` (`'A\B'::method()`
     *   is a working call, and a string class name is always fully qualified,
     *   case-insensitive, and may carry one leading separator — all measured) and `T_CLASS`
     *   (`A\B::class::method()`, resolved by recursing onto the earlier `::`). Every other
     *   tail returns `null`.
     *
     * `null` is the whole of the dynamic-dispatch limit named on the test above, and it is what
     * a member name behind `::`, `->` or `?->` returns too — `Holder::static::m()` reads as
     * `T_STATIC` and would otherwise resolve to the enclosing class, which was the one place
     * this method produced a wrong name instead of no name (measured).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                               $aliases
     */
    private function resolveCallee(array $tokens, int $index, string $namespace, array $aliases, string $enclosing, string $parent): ?string
    {
        $leftIndex = $this->significantIndex($tokens, $index, -1);

        if ($leftIndex === null) {
            return null;
        }

        $left = $tokens[$leftIndex];

        if (!is_array($left)) {
            return null;
        }

        if ($left[0] === T_CLASS) {
            $previous = $this->significantIndex($tokens, $leftIndex, -1);

            if ($previous === null || !is_array($tokens[$previous]) || $tokens[$previous][0] !== T_DOUBLE_COLON) {
                return null;
            }

            return $this->resolveCallee($tokens, $previous, $namespace, $aliases, $enclosing, $parent);
        }

        if ($this->isMemberName($tokens, $leftIndex)) {
            return null;
        }

        if ($left[0] === T_STATIC) {
            return $enclosing === '' ? null : $enclosing;
        }

        if ($left[0] === T_CONSTANT_ENCAPSED_STRING) {
            // A string class name goes to the class loader as written, and the loader ignores
            // ONE leading separator: `'\A\B'` reaches `A\B` (measured). Repeated separators it
            // does NOT collapse — a value of `A\\B` is not found (measured, adjudication), so
            // collapsing them here is wider than PHP. The direction is a false positive rather
            // than a hidden caller, so it is left as it is and named instead of trimmed.
            return ltrim((string) preg_replace('#\\\\+#', '\\', $this->decodeStringLiteral($left[1])), '\\');
        }

        if (!in_array($left[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return null;
        }

        if ($left[0] === T_STRING) {
            $keyword = strtolower($left[1]);

            if ($keyword === 'self') {
                return $enclosing === '' ? null : $enclosing;
            }

            if ($keyword === 'parent') {
                return $parent === '' ? null : $parent;
            }
        }

        return $this->resolveName($left[1], $namespace, $aliases);
    }

    /**
     * The value of a PHP string literal, decoded the way PHP decodes it. Single quotes know two
     * escapes; double quotes know the documented set and leave every other backslash standing —
     * `"A\Test"` really is `A\Test`, which is why `stripcslashes()` is not used here.
     *
     * The token is a whole literal with no interpolation in it by construction: an interpolated
     * string tokenises as `"` … `"` and never reaches this method (measured).
     */
    private function decodeStringLiteral(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        if (str_starts_with($literal, "'")) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];

        return (string) preg_replace_callback(
            '/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            static function (array $matches) use ($simple): string {
                if ($matches[1] !== '') {
                    return $simple[$matches[1]];
                }

                if (($matches[2] ?? '') !== '') {
                    return chr((int) octdec($matches[2]) & 0xFF);
                }

                if (($matches[3] ?? '') !== '') {
                    return chr((int) hexdec($matches[3]));
                }

                return mb_chr((int) hexdec($matches[4] ?? '0'));
            },
            $inner
        );
    }

    /**
     * Whether the token at `$index` is a member name rather than a name in its own right — that
     * is, whether `::`, `->` or `?->` stands immediately before it. Every reserved word is a
     * legal member name, so without this the keyword branches read `Holder::use::m()` as a `use`
     * statement and `Holder::static::m()` as the `static` keyword.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isMemberName(array $tokens, int $index): bool
    {
        $before = $this->significantToken($tokens, $index, -1);

        return is_array($before)
            && in_array($before[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    /**
     * Whether a `namespace` statement opens a braced block instead of ending at `;`.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function namespaceIsBraced(array $tokens, int $index): bool
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            if ($tokens[$i] === ';') {
                return false;
            }

            if ($tokens[$i] === '{') {
                return true;
            }
        }

        return false;
    }

    /**
     * The index of the `{` that opens a declaration's body. Parentheses and brackets are skipped
     * so that a closure inside an anonymous class's constructor arguments is not mistaken for it.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function bodyBraceIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        $nested = 0;

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                continue;
            }

            if (in_array($token, ['(', '['], true)) {
                $nested++;

                continue;
            }

            if (in_array($token, [')', ']'], true)) {
                $nested = max(0, $nested - 1);

                continue;
            }

            if ($token === '{' && $nested === 0) {
                return $i;
            }

            if ($token === ';' && $nested === 0) {
                return null;
            }
        }

        return null;
    }

    /**
     * The traits a `use` inside a class body composes, fully qualified. Read on its own rather
     * than through {@see self::readImports()}, which answers `[]` for `use T { a as protected b; }`
     * and would let a composed trait go unrecorded (measured).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                               $aliases
     *
     * @return list<string>
     */
    private function readComposedTraits(array $tokens, int $index, string $namespace, array $aliases): array
    {
        $names = [];
        $current = '';
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR], true)) {
                    break;
                }

                $current .= $token[1];

                continue;
            }

            if ($current !== '') {
                $names[] = $this->resolveName($current, $namespace, $aliases);
                $current = '';
            }

            if (in_array($token, ['{', ';'], true)) {
                break;
            }

            if ($token !== ',') {
                break;
            }
        }

        return $names;
    }

    /**
     * The fully qualified name a declaration extends, or `''` when it extends nothing. Only the
     * first name is read: `interface A extends B, C` has no `parent::` of its own.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                               $aliases
     */
    private function readExtends(array $tokens, int $index, string $namespace, array $aliases): string
    {
        $count = count($tokens);
        $seen = false;

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (in_array($token, ['{', ';'], true)) {
                break;
            }

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_EXTENDS) {
                $seen = true;

                continue;
            }

            if (!$seen) {
                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                return $this->resolveName($token[1], $namespace, $aliases);
            }

            if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
        }

        return '';
    }

    /**
     * Resolves a name as it was written to a fully qualified one: leading `\` means it already
     * is, a `namespace\` head means the current namespace, a first segment matching an import
     * takes that import's target, and anything else is relative to the current namespace.
     *
     * `namespace\X` is spelled out separately rather than folded into the last case, because it
     * skips the imports: `namespace\X` is the current namespace's `X` even when `X` is imported
     * from somewhere else. `namespace` is a reserved word, so no class can be called that.
     *
     * @param array<string, string> $aliases
     */
    private function resolveName(string $written, string $namespace, array $aliases): string
    {
        if (str_starts_with($written, '\\')) {
            return ltrim($written, '\\');
        }

        if (stripos($written, 'namespace\\') === 0) {
            $tail = substr($written, strlen('namespace\\'));

            return $namespace === '' ? $tail : $namespace . '\\' . $tail;
        }

        $parts = explode('\\', $written);
        $head = strtolower($parts[0]);

        if (isset($aliases[$head])) {
            $parts[0] = $aliases[$head];

            return implode('\\', $parts);
        }

        return $namespace === '' ? $written : $namespace . '\\' . $written;
    }

    /**
     * The dotted name following a `namespace` or `use` keyword, or `''` when there is none
     * (`use` of a closure variable, a trait inside a class body).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function readName(array $tokens, int $index): string
    {
        $name = '';
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (in_array($token, [';', '{', '(', ','], true)) {
                break;
            }

            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];

                continue;
            }

            if ($token[0] === T_AS) {
                break;
            }

            if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
        }

        return ltrim($name, '\\');
    }

    /**
     * Every name a single `use` statement imports, as alias => fully qualified name.
     *
     * All four static shapes are parsed, because all four are ordinary PHP that the same tokens
     * already carry — `use A\\B;`, `use A\\B as C;`, the list form `use A\\B, C\\D;`, and the group
     * form `use Ns\\{A, B as C};`. The earlier revision read the statement as one name and
     * stopped at the first `{`, `,` or `;`: a group import came out as the bare prefix
     * `ModxKit\\Testbench\\Exception\\`, whose last segment is the empty string, so the alias map
     * gained a junk `'' => …` entry and every name in the braces resolved as if it had never
     * been imported (measured).
     *
     * Returns `[]` for the two `use` keywords that import nothing: a closure's `use ($var)`, and
     * `use function` / `use const`. The third keyword that imports nothing — a trait `use` in a
     * class body — is told apart by the CALLER, which only asks at brace depth 0, because the
     * two are indistinguishable from the statement alone. An earlier revision left that one
     * conflated and called it inert; measured, it was neither harmless in shape (`use T { a as b; }`
     * yielded the fabricated name `Ta`) nor idle: `src/TestCase.php` imports
     * `ModxKit\Testbench\Concerns\InteractsWithModx` and then composes it in the class body, and
     * the second reading overwrote the first through `array_merge()`. It happened to hurt
     * nothing because that name is neither of the two being resolved. Fixing the depth was
     * cheaper than keeping the argument: measured over `src/`, exactly one file's import map
     * changes and it changes back to the imported name.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<string, string>
     */
    private function readImports(array $tokens, int $index): array
    {
        $next = $this->significantToken($tokens, $index, 1);

        if ($next === '(' || (is_array($next) && in_array($next[0], [T_FUNCTION, T_CONST], true))) {
            return [];
        }

        $imports = [];
        $prefix = '';
        $name = '';
        $alias = null;
        $awaitingAlias = false;
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($token[0] === T_AS) {
                    $awaitingAlias = true;

                    continue;
                }

                if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                    break;
                }

                if ($awaitingAlias) {
                    $alias = $token[1];

                    continue;
                }

                $name .= $token[1];

                continue;
            }

            // `{` opens the group form: what has been read so far is the shared prefix.
            if ($token === '{') {
                $prefix = $name;
                $name = '';

                continue;
            }

            if (!in_array($token, [',', '}', ';'], true)) {
                break;
            }

            if ($name !== '') {
                $qualified = ltrim($prefix . $name, '\\');
                $segments = explode('\\', $qualified);
                // Class names and import aliases are case-insensitive in PHP (measured), so the
                // key is folded; the target keeps the case it was written with.
                $imports[strtolower($alias ?? end($segments))] = $qualified;
            }

            $name = '';
            $alias = null;
            $awaitingAlias = false;

            if ($token === '}') {
                $prefix = '';

                continue;
            }

            if ($token === ';') {
                break;
            }
        }

        return $imports;
    }

    /**
     * The token `$offset` significant positions from `$index`, skipping whitespace and comments.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function significantToken(array $tokens, int $index, int $offset): array|string|null
    {
        $found = $this->significantIndex($tokens, $index, $offset);

        return $found === null ? null : $tokens[$found];
    }

    /**
     * The same position as an index, for the callers that have to keep walking from it.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function significantIndex(array $tokens, int $index, int $offset): ?int
    {
        $step = $offset < 0 ? -1 : 1;
        $remaining = abs($offset);
        $count = count($tokens);

        for ($i = $index + $step; $i >= 0 && $i < $count; $i += $step) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $remaining--;

            if ($remaining === 0) {
                return $i;
            }
        }

        return null;
    }

    private function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts);
    }

    /**
     * Absolute path of every PHP file of `src/`, keyed by path relative to `src/`.
     *
     * Separators are normalised to `/` so the expected lists above read the same everywhere.
     * NOT MEASURED on Windows: no host to run it on, and the normalisation is the whole of the
     * provision made for it.
     *
     * @return array<string, string>
     */
    private function packageFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = [];

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($entries as $entry) {
            if ($entry->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
            $files[$relative] = $entry->getPathname();
        }

        return $files;
    }

    /**
     * A real configuration with the working directory replaced — through the public
     * constructor rather than through `$_SERVER`, so the test mutates no global state.
     */
    private function configWithWorkspaceDir(string $workspaceDir): TestbenchConfig
    {
        $base = TestbenchConfig::fromEnvironment();

        return new TestbenchConfig(
            provider: $base->provider,
            version: $base->version,
            gitRef: $base->gitRef,
            localCorePath: $base->localCorePath,
            database: $base->database,
            admin: $base->admin,
            cacheDir: $base->cacheDir,
            workspaceDir: $workspaceDir,
            forceInstall: $base->forceInstall,
        );
    }
}

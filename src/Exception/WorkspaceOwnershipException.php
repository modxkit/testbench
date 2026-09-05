<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

/**
 * The directory given as the working one does not belong to the package — it must not be removed.
 *
 * A type of its own is needed for exactly one thing: the commands print such a message WITHOUT
 * masking. It consists only of the working directory path and the package's own file names, it holds
 * no secrets by construction, and `Secret::mask()` destroys it: the package's regular password is
 * `testbench` (`ci/docker-compose.yml`), and the text used to turn into "was not created by
 * modx-***: it is not empty and holds neither the ".***-workspace" marker nor a ***.lock.json
 * file" — the user was told to look for exactly the two names that had been hidden. Escaping on word
 * boundaries would not have helped either: `.testbench-workspace` is surrounded by non-word
 * characters.
 *
 * All other environment preparation failures (`DatabaseCleaner`, `HeadlessInstaller`, raw PDO
 * messages) are masked as before — there the password is real.
 *
 * The "print it raw" part is no longer a `catch` branch of its own in every command: the type
 * declares {@see SecretFreeMessage}, and the commands honour that one marker. What the marker
 * rests on is a proof about the CALL SITES, not about the type: both factories take free
 * strings, and a private constructor does nothing about that. Today `Workspace` is the only
 * caller, passing its own path and the ownership marker name, and the callers are pinned by a
 * test — which raises the cost of adding one silently, and does not reduce it to zero: the
 * pinning is a source scan, and its limits are named on the constructor below.
 *
 * Named limit, unchanged by that move and older than it: the path comes from
 * `MODX_TESTBENCH_WORKSPACE`, so a consumer who puts their own database password inside a
 * directory NAME would see it printed back unmasked. This exemption is not new — the commands
 * printed this type raw through a `catch` branch of its own before the marker existed — and
 * the marker inherits it rather than introducing it.
 */
final class WorkspaceOwnershipException extends TestbenchException implements SecretFreeMessage
{
    /**
     * Private so that `new` with an arbitrary message is not a way into an exempt type. What it
     * does NOT do is make the {@see SecretFreeMessage} promise structural: both factories below
     * take a free string, and unlike {@see WorkspaceLocationException::filesystemRoot()} they
     * cannot check it — the path in these messages IS a consumer-chosen directory name, and no
     * predicate tells that apart from arbitrary text. What holds the exemption up here is
     * therefore a proof about the CALL SITE, and the call sites are pinned by
     * `SecretTest::testTheExemptExceptionTypesAreBuiltOnlyWhereTheirProofHolds()`.
     *
     * That pinning has two named limits. The first is the only one of its kind: the scan
     * resolves a name only where a name is written. It parses namespaces and every static shape
     * of `use`, and it resolves every token kind that can carry a class NAME — `static` or one
     * of the four name tokens, which is what the grammar allows there and what a brute force
     * over all 92 PHP reserved words confirms: exactly 16 of them are accepted as a bare class
     * name. Where the class is a VALUE instead of text the sources do not say which class it is,
     * and the call is NOT caught: `$class::notOurs()`, `call_user_func()`, reflection, a
     * parenthesised expression, or a class constant that holds the name — the last of which is
     * where the bulk of the token kinds live, since every reserved word is a legal class-constant
     * name: 77 kinds can stand to the left of `::` by that route alone, and 75 of them can never
     * carry a name. Two of those value shapes carry a name anyway and ARE resolved: a string
     * literal and `::class`. The second limit is plainer: the scan reads `src/`, so a
     * caller in `tests/` is not seen.
     *
     * Earlier revisions named the first limit alone and called it whole. It was not, four times
     * over — the import alias, then the group and list `use` forms, then six more spellings
     * including `namespace\WorkspaceOwnershipException::notOurs()`, `self::notOurs()` and
     * `'ModxKit\Testbench\Exception\WorkspaceOwnershipException'::notOurs()` (of those six,
     * `parent::` is unreachable while this class stays final), then a leading separator inside
     * such a literal, and case: PHP matches class names case-insensitively and
     * the scan was matching bytes. Every one of them turned out to be ordinary resolvable PHP
     * and was fixed rather than named.
     *
     * The promise is about the MESSAGE. An exception's stack trace carries the arguments the
     * factory was called with (`getTrace()`, and 15 characters of each in `getTraceAsString()`,
     * under the default `zend.exception_ignore_args = 0`); nothing here masks those. No command
     * of this package prints a trace, and Symfony does not print arguments even at `-vvv`
     * (measured), so this is a statement of scope, not a known leak.
     */
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function notOurs(string $path, string $marker): self
    {
        return new self(sprintf(
            'Directory "%s" was not created by modx-testbench: it is not empty and holds neither the '
            . '"%s" marker nor a testbench.lock.json file. Deletion cancelled — otherwise all of its '
            . "contents would have been lost irrecoverably.\nCheck MODX_TESTBENCH_WORKSPACE: the "
            . 'working directory of the environment must be a directory of its own (empty or '
            . 'non-existent). If the directory really is redundant, delete it by hand.',
            $path,
            $marker
        ));
    }

    public static function cannotMark(string $path, string $marker): self
    {
        return new self(sprintf(
            'Failed to mark directory "%s" with file "%s" — check write permissions. '
            . 'Deletion cancelled: without the marker an interrupted cleanup would leave the directory '
            . 'unidentifiable, and the package could no longer delete it.',
            $path,
            $marker
        ));
    }
}

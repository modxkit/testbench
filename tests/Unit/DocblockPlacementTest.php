<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Catches an orphaned docblock — the trace of an edit that landed in the neighbouring block.
 *
 * Twice over two pieces of work a scripted insertion of a new method, anchored on "the signature of
 * the next method", landed BETWEEN an existing docblock and its method: the docblock was left
 * describing somebody else's code, and its own method was left undocumented. The suite stays green
 * through it, the number of assertions does not change, and neither cs-fixer nor
 * `git diff --stat` sees such an insertion — PSR-12 knows nothing about the meaning of docblocks,
 * and a diff shows only the volume.
 *
 * The defect leaves a simple and unambiguous fingerprint: two docblocks in a row with nothing but
 * whitespace between them. In this code base that is always an error — at the time this test
 * appeared there was NOT ONE legitimate occurrence (both that were found turned out to be traces of
 * exactly such insertions).
 *
 * The test does not replace the discipline of anchoring (insert after the closing brace of the
 * previous method, or include the target's docblock in the anchor), but it does check that half of
 * it mechanically.
 */
#[Group('unit')]
final class DocblockPlacementTest extends TestCase
{
    public function testNoOrphanedDocblocksInPackageSources(): void
    {
        $orphans = [];

        foreach ($this->phpSources() as $file) {
            $contents = (string) file_get_contents($file);

            // `*/`, then whitespace only, then `/**` again.
            if (preg_match_all('#\*/\s*+/\*\*#', $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as [, $offset]) {
                $line = substr_count($contents, "\n", 0, $offset) + 1;
                $orphans[] = sprintf('%s:%d', $this->relative($file), $line);
            }
        }

        self::assertSame(
            [],
            $orphans,
            'Two docblocks in a row are almost certainly the trace of an edit that landed between '
            . "somebody else's docblock and its method. Move the upper docblock to the method it describes:\n"
            . implode("\n", $orphans)
        );
    }

    /**
     * The second form of the same breakage, and it differs from the first in producing no two
     * docblocks in a row: a new method WITHOUT a docblock of its own, inserted between somebody
     * else's docblock and its method, takes that docblock for itself, while the method it describes
     * is left bare. The check above does not see that.
     *
     * What is caught here is not the move itself but its TRACE: a dangling reference to a docblock
     * whose addressee does not exist. Exactly two addressees are mechanically resolvable, and both
     * are checked:
     *
     * - the enclosing method — a line comment in the body of a method naming nobody else
     *   ("…the docblock of the method", "(…the docblock)");
     * - a named property — "…the docblock of `$name`", wherever the reference comes from.
     *
     * The other addressees are skipped deliberately, because they name no unambiguous entity:
     * `{@see Class}` (the addressee is in another file — the language server's job), "the docblock
     * of the class", "the docblock of the corresponding test", "…its docblock".
     *
     * What the check does NOT catch and cannot: a move that left no reference at all. Such a defect
     * is distinguishable from healthy code only by the meaning of the text, not by its form.
     * Reproduced by a reviewer: a restored defect with the reference removed passes both checks of
     * this file green.
     */
    public function testEveryResolvableDocblockReferenceResolves(): void
    {
        $checked = ['method' => [], 'property' => [], 'dangling' => []];

        foreach ($this->phpSources() as $file) {
            $lines = explode("\n", (string) file_get_contents($file));

            foreach (array_keys($lines) as $index) {
                $form = $this->referenceForm($lines, $index);

                if ($form === null) {
                    continue;
                }

                $where = sprintf('%s:%d', $this->relative($file), $index + 1);
                $checked[$this->referenceResolves($lines, $index, $form) ? $form : 'dangling'][] = $where;
            }
        }

        // Preconditions: both resolvable forms are present in the suite. An empty traversal would go
        // green having checked nothing — exactly what the previous edition of this check was caught on.
        self::assertNotSame([], $checked['method'], 'The traversal found not a single reference to a method docblock.');
        self::assertNotSame([], $checked['property'], 'The traversal found not a single reference to a property docblock.');

        self::assertSame(
            [],
            $checked['dangling'],
            "A docblock reference points where there is no docblock:\n" . implode("\n", $checked['dangling'])
        );
    }

    /**
     * A comment naming a test BY NAME must name a test that exists.
     *
     * The occasion was an orphaned docblock in `CommandsTest` that survived a test being renamed.
     * That one is not caught by this check and could not have been: it described its addressee in
     * PROSE ("the test about the rollback into a world-readable temporary directory"), and there is
     * nothing to resolve prose with. But the same breakage has a resolvable half — a reference that
     * names a test by name with parentheses — and checking it mechanically costs
     * one traversal: at the time the test appeared there were twenty such references, and all
     * twenty resolve (measured).
     *
     * The search covers the WHOLE tree rather than the same file, and that is not a concession:
     * eight of the twenty references lead from production code into a test in another file — that
     * is how the production code names the witness of its own property. A "same file" check would
     * have reddened all eight.
     */
    public function testEveryTestNamedInACommentExists(): void
    {
        $declared = [];
        $mentioned = [];

        foreach ($this->phpSources() as $file) {
            $contents = (string) file_get_contents($file);

            preg_match_all('/function\s+(\w+)\s*\(/', $contents, $declarations);
            $declared = array_merge($declared, $declarations[1]);

            foreach (explode("\n", $contents) as $index => $line) {
                $trimmed = ltrim($line);

                // Comments and docblocks only: a test name in CODE is a call, and PHP checks that one
                // itself.
                if (!str_starts_with($trimmed, '*') && !str_starts_with($trimmed, '//')) {
                    continue;
                }

                if (preg_match_all('/\b(test[A-Z]\w*)\s*\(\)/', $line, $names) === 0) {
                    continue;
                }

                foreach ($names[1] as $name) {
                    $mentioned[$name][] = sprintf('%s:%d', $this->relative($file), $index + 1);
                }
            }
        }

        // Premise: the traversal found something. An empty one would go green having checked nothing.
        self::assertNotSame([], $mentioned, 'The traversal found not a single reference to a test by name.');

        $dangling = [];

        foreach ($mentioned as $name => $places) {
            if (!in_array($name, $declared, true)) {
                $dangling[] = $name . ' — ' . implode(', ', $places);
            }
        }

        self::assertSame(
            [],
            $dangling,
            "A comment names a test that is not in the package:\n" . implode("\n", $dangling)
        );
    }

    /**
     * Which addressee the reference on this line addresses: `method`, `property`, or `null` — the
     * line is not a reference, or its addressee is outside the resolvable set.
     *
     * @param list<string> $lines
     */
    private function referenceForm(array $lines, int $index): ?string
    {
        $line = $lines[$index];
        $position = mb_strpos($line, 'docblock');

        if ($position === false || preg_match('/\bsee\s+the\s*$/iu', mb_substr($line, 0, $position)) !== 1) {
            return null;
        }

        // The addressee is sometimes carried onto the next line, so the window is two lines counted
        // from the word "docblock" itself: whatever stands to its LEFT is never the addressee.
        $tail = mb_substr($line, $position) . ' ' . ($lines[$index + 1] ?? '');

        if (str_contains($tail, '{@see')) {
            return null;
        }

        if (preg_match('/`\$\w+`/u', $tail) === 1) {
            return 'property';
        }

        if (preg_match('/^docblocks?\s+of\s+the\s+(?:class|corresponding)/u', $tail) === 1) {
            return null;
        }

        return str_starts_with(ltrim($line), '//') ? 'method' : null;
    }

    /**
     * @param list<string> $lines
     */
    private function referenceResolves(array $lines, int $index, string $form): bool
    {
        if ($form === 'property') {
            $window = $lines[$index] . ' ' . ($lines[$index + 1] ?? '');

            if (preg_match('/`\\$(\\w+)`/u', $window, $matches) !== 1) {
                return false;
            }

            return $this->propertyIsDocumented($lines, $matches[1]);
        }

        return $this->enclosingMethodIsDocumented($lines, $index);
    }

    /**
     * @param list<string> $lines
     */
    private function propertyIsDocumented(array $lines, string $property): bool
    {
        $declaration = '/^ {4}(?:(?:final|public|protected|private|readonly|static|\??[\w\\|]+)\s+)*\$'
            . preg_quote($property, '/') . '\b/';

        foreach (array_keys($lines) as $number) {
            if (preg_match($declaration, $lines[$number]) === 1) {
                return $this->docblockSitsAbove($lines, $number);
            }
        }

        return false;
    }

    /**
     * A method declaration is looked for at an indentation of exactly one level: a method of an
     * anonymous class declared inside the body of another method (the suite has several of those)
     * sits deeper and would intercept the search.
     *
     * @param list<string> $lines
     */
    private function enclosingMethodIsDocumented(array $lines, int $index): bool
    {
        for ($cursor = $index; $cursor >= 0; $cursor--) {
            if (preg_match('/^ {4}(?:(?:final|abstract|public|protected|private|static)\s+)*function\s/', $lines[$cursor]) !== 1) {
                continue;
            }

            return $this->docblockSitsAbove($lines, $cursor);
        }

        return false;
    }

    /**
     * @param list<string> $lines
     */
    private function docblockSitsAbove(array $lines, int $declaration): bool
    {
        for ($above = $declaration - 1; $above >= 0; $above--) {
            if (trim($lines[$above]) === '' || str_starts_with(trim($lines[$above]), '#[')) {
                continue;
            }

            return str_ends_with(trim($lines[$above]), '*/');
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function phpSources(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/bin/modx-testbench', $root . '/bootstrap.php'];

        foreach (['/src', '/tests', '/stubs'] as $directory) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $directory, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($items as $item) {
                /** @var SplFileInfo $item */
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $files[] = $item->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 2)) + 1);
    }
}

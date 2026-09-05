<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * No shipped file may anchor an argument on an address the reader cannot fetch.
 *
 * The files below travel into `vendor/` of a consumer who has no access to this repository's
 * history at all, and the public history is started anew at the release — so a seven-character
 * hash in a comment resolves nowhere for everybody who reads it. Three such anchors were shipped
 * (`src/Support/Secret.php` twice, `src/Exception/SecretFreeMessage.php` once); the argument each
 * of them carried survived the removal, the address did not.
 *
 * What is forbidden is the ADDRESS, not the measurement: a count taken at some revision stays,
 * it simply says what was counted and how instead of pointing at a commit nobody can fetch.
 *
 * A commit hash is not the only unfetchable address. A GitHub Actions run identifier is purely
 * numeric, so the hexadecimal pattern — which requires a letter — walked past it: `32715824640`
 * shipped in `README.md`, `README.ru.md` and `src/Database/MysqlDumper.php`, and that run does not
 * exist at all: 404 under the owner's token against a control 200 on the same path for a run that
 * does exist. Hence the second pattern. The distance argument that used to stand here was wrong and
 * is dropped rather than repaired — all 45 runs of this repository were enumerated, and the lowest
 * real identifier, `33428588009`, is 2.2% above the phantom, not an order of magnitude. The 404 is
 * the whole of the evidence.
 *
 * The set of files is the DISTRIBUTION, and git is the only authority consulted about it — never a
 * second reading of `.gitattributes`. It is assembled from three questions put to git, because
 * each of them alone has a hole:
 *
 *   - `git archive HEAD` is the build itself, the same one the `.gitattributes` allowlist is
 *     verified by. It answers for everything already committed.
 *   - `git ls-files --others --exclude-standard` answers for a file that is not tracked at all.
 *     Without this half a brand-new shipped source was invisible to the guard until its first
 *     commit — measured with an untracked `src/ProbeAnchorTmp.php` carrying both anchors, which
 *     the guard read as clean.
 *   - `git diff --cached --diff-filter=ACMR HEAD` answers for a file that is already in the INDEX.
 *     `git ls-files --others` lists precisely what the index does not hold, so one `git add` used
 *     to hide the file again — measured on that same probe: red while untracked, `OK` the moment
 *     it was staged. That is not an exotic state; it is the middle of `git add` → `composer qa` →
 *     `git commit`.
 *
 * Both uncommitted halves are put through `git check-attr export-ignore`, so a staged or untracked
 * file that does not ship is not read.
 *
 * One shape of "will ship with the next commit" is still invisible, and it is written down rather
 * than left unsaid: a file that is already tracked and already clean, which begins to ship because
 * `.gitattributes` is edited in the WORKING TREE. Measured — with `/ci/** -export-ignore` added to
 * the working tree and both anchors written into the tracked `ci/consumer/composer.json`, the run
 * stays green. Closing it means classifying every tracked file by the working tree's attributes
 * instead of asking the build, that is, giving up `git archive` as the single source of truth
 * about the boundary. Whether that trade pays is NOT MEASURED.
 *
 * The paths come from git; the CONTENTS are read from the working tree. A guard that read the
 * archive's own bytes would go green on an uncommitted anchor and report it only after it had been
 * committed.
 *
 * Two of the three files that carried the numeric identifier were `README.md` and `README.ru.md`,
 * and an earlier version of this test walked PHP only: it named those two files in this very
 * docblock and then did not read them. That is why the premises below are not decoration — every
 * claim this docblock makes about WHAT is read is checked by an assertion, including the claim
 * that the non-PHP part of the distribution is read at all.
 *
 * Five external calls stand behind that: four to git (`archive`, `ls-files`, `diff --cached`,
 * `check-attr`) and one to `tar`. Each is run without a shell and with its own exit code and
 * stderr — a pipeline would report the exit code of its last command, and that mistake has
 * already been made here once. A sixth call runs no tool of the distribution at all: it asks a
 * child of `runExternal` what descriptors it was handed, and is the premise that keeps the
 * one-pipe rule below from staying prose.
 *
 * None of the six carries a premise about its own exit code, because `runExternal` carries it for
 * all of them — see its docblock. Written out per call it could be forgotten, and the sixth call
 * is where it was.
 *
 * An uncommitted file is classified by the `.gitattributes` of the WORKING TREE, not of HEAD —
 * measured: with `/ci/** -export-ignore` added to the working tree only, an untracked
 * `ci/ProbeWorktreeAttr.yml` is read and reported, although the archive of HEAD carries nothing
 * from `ci/` but `docker-compose.yml`. The error is therefore towards reading a file that will
 * not ship, never towards skipping one that will.
 *
 * That demonstration is run on `ci/` and not on `docs/` for a measured reason: unsetting
 * `/docs/**` also unsets `docs/.export-ignore-control`, the control pinned to `set` below, so the
 * run stops on the control before it reads anything. It reddens either way — but on `docs/` it
 * reddens for the wrong reason, and a recipe that proves something else is not a recipe.
 */
#[Group('unit')]
final class CommitAnchorTest extends TestCase
{
    /**
     * A hexadecimal word of git's abbreviation length carrying both a digit and a letter. The two
     * requirements together are what tells `0259ec6` from `abcdef` (an English word in the middle
     * of a sentence) and from a bare `1000000`. The archive size threshold it was once said to guard
     * against is written `1_000_000` in `ZipReleaseProvider` and never reaches either pattern:
     * measured, `git grep 1000000` finds the two lines of this file and nothing else, while
     * `1_000_000` lives in `ZipReleaseProvider:273` and two of its tests.
     *
     * The `\b` at both ends is what keeps a SHA-256 release digest out: at 64 characters it is
     * longer than the upper bound, and no shorter run inside it ends on a word boundary.
     *
     * Two known holes, left open on purpose and recorded rather than patched here.
     *
     * An abbreviated hash made of digits only carries no letter and is caught by neither pattern —
     * `\d{7,8}` is below the numeric floor as well. About 3.7% of seven-character hashes are
     * all-digit.
     *
     * The character class is lower case and there is no `i` modifier, so a hash written in upper
     * or mixed case walks past. Measured by injection into the committed, shipped
     * `src/Support/Secret.php`: `0259ec6` and its 40-character lower-case counterpart are caught,
     * while `0259EC6`, `0259Ec6` and a 40-character upper-case hash all give
     * `OK (1 test, 34 assertions)`. Git prints hashes in lower case, so the shape has to be typed
     * by hand to occur — which is why it is recorded and not patched: widening the class widens
     * everything it already lets through.
     */
    private const ANCHOR = '/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{7,40}\b/';

    /**
     * A bare decimal integer long enough to be a CI run identifier. GitHub's are eleven digits
     * today (`33428588009`), and the phantom one that shipped was eleven as well.
     *
     * Nine is the floor rather than eleven so that the guard does not depend on GitHub's counter
     * staying the width it is now, and it is not lower because the shipped files legitimately
     * carry `31536000` (a year in seconds, `SnapshotFailedException`) and `4194304`
     * (`PhpDumper::BYTES_PER_INSERT`) — measured over the same traversal this test walks.
     *
     * The identifier must be a WORD of its own, and that is the whole of the second requirement.
     * Without it the pattern reads the inside of `ZipReleaseProvider::RELEASE_DIGESTS`: a SHA-256
     * digest is 64 characters drawn from an alphabet of sixteen, so a run of nine or more
     * consecutive decimal digits turns up
     * in it by chance — measured at 28.15% over 200 000 random digests, that is, adding one more
     * supported MODX line would have reddened this guard in about a quarter of cases. With the
     * boundaries below the same 200 000 give 0.00%, while a real address in prose is still caught.
     *
     * The price of that boundary, measured and not hidden: an identifier glued straight to a
     * letter or an underscore — `run_32715824640`, `run32715824640`, `32715824640x` — is no longer
     * a word of its own and walks past. Three forms out of thirty-five tried; all three are shapes
     * no prose in this repository has ever used, and the alternative is the 28.15% above.
     *
     * A dot disqualifies only when it joins the run to another number: `1.234567890` is a version
     * fragment, while `… run 32715824640.` at the end of a sentence is an address and must be
     * caught. An earlier form refused BOTH and was caught by the negative control below.
     */
    private const RUN_ANCHOR = '/(?<![0-9a-zA-Z_.])\d{9,}(?![0-9a-zA-Z_]|\.\d)/';

    /**
     * A digest of the shape `RELEASE_DIGESTS` holds, carrying the nine-digit run `718862789`. Not
     * a real release digest — it is drawn from the same measurement that produced the 28.15% above,
     * and it is here so that the false positive stays refuted by a test rather than by a report.
     */
    private const DIGEST_SHAPED = '1acd414c02518d9574a660483aae4eebee718862789dd0e6b041a1779231ce48';

    /**
     * Every shipped file that is not PHP, named one by one.
     *
     * The list is short enough to write out, and writing it out is the point: the guard once
     * claimed to read the READMEs while walking `*.php` only. If the distribution stops carrying
     * one of these, this test says so instead of quietly reading five files where it read six.
     */
    private const SHIPPED_NON_PHP = [
        'LICENSE',
        'README.md',
        'README.ru.md',
        'bin/modx-testbench',
        'ci/docker-compose.yml',
        'composer.json',
    ];

    /**
     * A child that reports what kind of thing it was handed on each of its three standard
     * descriptors.
     *
     * `AT MOST ONE PIPE` in `runExternal` is a rule, and a rule that only a reader enforces is
     * prose: both deadlocks this file has lived through were a second pipe added by someone who
     * had the rule in front of them. It is testable without reading the source — the child says
     * what its own descriptors are. `fstat()['mode'] & 0170000` is `0010000` for a pipe and
     * `0100000` for a regular file, so one answer names all three at once.
     */
    private const DESCRIPTOR_PROBE = '$kinds = [0010000 => "FIFO", 0100000 => "REGULAR"]; $out = [];'
        . 'foreach ([STDIN, STDOUT, STDERR] as $fd => $stream) {'
        . '$out[] = $fd . "=" . ($kinds[fstat($stream)["mode"] & 0170000] ?? "OTHER");'
        . '} echo implode(" ", $out);';

    /**
     * How much of one argument the premise of {@see self::runExternal()} quotes.
     *
     * One argument this file passes is long by construction and long everywhere: the source of
     * {@see self::DESCRIPTOR_PROBE}, 216 bytes measured. Every other argument is a fixed flag or a
     * path, and the longest of those is `--output=` plus what `tempnam()` returned. That one varies
     * with TWO things, not one — the temporary directory and the PHP version, because the random
     * suffix `tempnam()` appends is not the same width across releases. Measured with
     * `strlen('--output=' . tempnam(sys_get_temp_dir(), 'testbench-shipped-'))`, three runs each,
     * every run identical:
     *
     *   PHP 8.4.8 here, the directory `tempnam()` returned 56 characters, suffix 19  103 bytes
     *   PHP 8.4.25 in a container, `sys_get_temp_dir()` `/tmp`, suffix 19             51 bytes
     *   PHP 8.2.33 and 8.3.33, same container `/tmp`, suffix 6                        38 bytes
     *
     * The width that adds up is the directory `tempnam()` puts in the path it RETURNS, not the one
     * it was handed: here it resolves the 48 characters of `sys_get_temp_dir()` (`/var/folders/…`)
     * to 56 (`/private/var/folders/…`), measured in the same three runs, so `--output=` 9 +
     * directory 56 + `/` 1 + prefix `testbench-shipped-` 18 + suffix 19 = 103. NOT MEASURED:
     * whether the container resolves its `/tmp` to anything else; there the same addition closes
     * on the measured 51 and 38 with the four characters of `/tmp` itself.
     *
     * The limit is above the largest of the three and below the probe, so the `--output=` argument
     * is quoted whole in all three and only the probe is cut.
     *
     * NOT MEASURED: the temporary directory at which an ordinary argument would start being cut
     * too; whether any release outside those four widens the suffix further; and the length of
     * `PHP_BINARY` anywhere but here, where it is 56. The consequence would be cosmetic either way
     * — the message still names the flag, the beginning of the path and the argument's full
     * length.
     */
    private const ARGUMENT_LIMIT = 120;

    public function testNoShippedFileAnchorsAnArgumentOnAnUnfetchableAddress(): void
    {
        $shipped = $this->shippedFiles();
        $root = dirname(__DIR__, 2);
        $anchors = [];
        $scanned = [];
        $missing = [];

        foreach ($shipped as $relative) {
            $file = $root . '/' . $relative;

            if (!is_file($file)) {
                // Not skipped silently: a shipped path that is not on disk means the distribution
                // and the working tree disagree, and a guard that reads one file fewer without
                // saying so is the failure mode this whole test exists to prevent.
                $missing[] = $relative;

                continue;
            }

            $contents = (string) file_get_contents($file);
            $scanned[] = $relative;

            foreach ([self::ANCHOR, self::RUN_ANCHOR] as $pattern) {
                if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as [$token, $offset]) {
                    $anchors[] = sprintf(
                        '%s:%d — %s',
                        $relative,
                        substr_count($contents, "\n", 0, $offset) + 1,
                        $token
                    );
                }
            }
        }

        // Premise: nothing the distribution names went unread.
        self::assertSame([], $missing, 'A shipped path is absent from the working tree.');

        // Premise: the traversal found files. An empty one would go green having read nothing.
        self::assertGreaterThan(50, count($scanned), 'The traversal found almost no shipped files.');

        // Premise: it found the files that are not PHP. This is the check the earlier version of
        // the test lacked, and the reason it named two READMEs it never opened.
        foreach (self::SHIPPED_NON_PHP as $file) {
            self::assertContains($file, $scanned, "The traversal did not read the shipped {$file}.");
        }

        // Control: the patterns really fire. Without these lines "no anchors" would also be the
        // answer of a regular expression that matches nothing at all.
        self::assertSame(1, preg_match(self::ANCHOR, 'measured on 0259ec6 over the literals'));
        self::assertSame(0, preg_match(self::ANCHOR, 'abcdef and 1000000 and 12 are not anchors'));
        self::assertSame(1, preg_match(self::RUN_ANCHOR, 'from the log of CI run 32715824640: the'));
        self::assertSame(1, preg_match(self::RUN_ANCHOR, 'measured in CI run 32715824640.'));
        self::assertSame(0, preg_match(self::RUN_ANCHOR, '31536000 seconds, 4194304 bytes, 8.0.46'));
        self::assertSame(0, preg_match(self::RUN_ANCHOR, 'version 1.234567890 is not an address'));

        // Control: neither pattern reads the inside of a release digest. The line is written the
        // way `RELEASE_DIGESTS` writes it, quotes and all, because the quotes are what the
        // lookbehind sees.
        self::assertSame(0, preg_match(self::RUN_ANCHOR, "    '3.9.9-pl' => '" . self::DIGEST_SHAPED . "',"));
        self::assertSame(0, preg_match(self::ANCHOR, "    '3.9.9-pl' => '" . self::DIGEST_SHAPED . "',"));
        // …and the run inside it really is one this guard would catch out in the open.
        self::assertSame(1, preg_match(self::RUN_ANCHOR, 'run 718862789 in prose'));

        // Premise: the child of `runExternal` gets exactly one pipe, and that keeps its
        // AT MOST ONE PIPE rule enforced instead of merely written down. Measured in both
        // directions on this very line: as the code stands the answer is
        // `0=REGULAR 1=FIFO 2=REGULAR`; hand stderr back to a pipe and it becomes
        // `0=REGULAR 1=FIFO 2=FIFO`, hand stdin back too and it becomes three FIFOs — the two
        // shapes that hung this test for real.
        // That the probe itself ran is asserted by `runExternal`, not here — measured with
        // `exit(7);` appended to the source above: the run reddens on the helper's own premise and
        // names THIS line as its second frame, instead of the comparison below reddening in its
        // place.
        $descriptorKinds = $this->runExternal([PHP_BINARY, '-r', self::DESCRIPTOR_PROBE]);
        self::assertSame(
            '0=REGULAR 1=FIFO 2=REGULAR',
            $descriptorKinds,
            'runExternal() no longer hands the child one pipe and two files; see its docblock for what that cost.'
        );

        self::assertSame(
            [],
            $anchors,
            "A shipped file anchors on an address the consumer cannot fetch. The public history is\n"
            . "started anew at the release — keep the argument, drop the address:\n"
            . implode("\n", $anchors)
        );
    }

    /**
     * The distribution as git reports it: what is in the archive of HEAD, plus what is not
     * committed yet and will be in the next one.
     *
     * @return list<string>
     */
    private function shippedFiles(): array
    {
        $files = array_merge($this->archivedFiles(), $this->uncommittedFilesThatWillShip());

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * `git archive HEAD` and then `tar tf` — two commands, not a pipeline.
     *
     * A pipeline's exit code in `sh` is the exit code of its LAST command, and `tar t` on empty
     * input exits zero: written as `git archive HEAD | tar t`, a `git` that dies with 128 produces
     * an empty listing and a status of 0. That is measured, not feared — with a `git` replaced by
     * a stub exiting 128 the pipeline still reported `exit=0`, and the test went red only on the
     * "found almost no shipped files" premise, that is, on precisely the empty traversal the
     * comment beside it promised would not be allowed to happen.
     *
     * `tar tf` prints archive members verbatim, without git's C-quoting: measured on an archive
     * holding a non-ASCII name, the listing carries the raw UTF-8 bytes. The quoting problem that
     * bit the untracked half below therefore does not arise here.
     *
     * @return list<string>
     */
    private function archivedFiles(): array
    {
        $archive = (string) tempnam(sys_get_temp_dir(), 'testbench-shipped-');

        try {
            $this->runExternal(['git', 'archive', 'HEAD', '--output=' . $archive]);

            $listing = $this->runExternal(['tar', 'tf', $archive]);
        } finally {
            @unlink($archive);
        }

        $files = [];

        foreach (explode("\n", $listing) as $entry) {
            if ($entry !== '' && !str_ends_with($entry, '/')) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * Files that exist in the working tree, are not in HEAD as they stand, are not ignored, and
     * are not excluded from the distribution — untracked ones and staged ones alike.
     *
     * The `export-ignore` verdict is asked of `git check-attr` — git's own attribute engine, the
     * one `git archive` itself consults — and not derived by reading `.gitattributes` a second
     * time. A second reading would be a second source of truth about the distribution boundary,
     * which is the thing this file is not allowed to become.
     *
     * Both calls are `-z`, and that is not tidiness. Without it `git ls-files --others` renders a
     * non-ASCII path in C-quotes whenever `core.quotePath` is on — which is git's DOCUMENTED
     * default — and the quotes then travel into `check-attr` as part of the name: asked about
     * `"src/\320\237…"` it answers `set`, because a path beginning with a quote character matches
     * no rule in the allowlist. Measured with one and the same file carrying both anchors: red
     * under `core.quotePath=false`, green under `core.quotePath=true`. With `-z` the bytes are
     * passed through untouched in both directions.
     *
     * The paths go to `check-attr` through its stdin rather than its argv. Six thousand untracked
     * files put 1 343 999 bytes on the command line against an `ARG_MAX` of 1 048 576, and the
     * call died with status 127 — red, but on a premise whose message body was empty, because the
     * shell's complaint went nowhere. Through `--stdin` there is no such limit at all, and every
     * call in this file carries its own stderr into the assertion message `runExternal` builds
     * for it.
     *
     * @return list<string>
     */
    private function uncommittedFilesThatWillShip(): array
    {
        $listed = $this->runExternal(['git', 'ls-files', '--others', '--exclude-standard', '-z']);

        // `--others` lists what the index does NOT hold, so it goes blind the moment a file is
        // staged — and staged is where a file spends the `git add` → `composer qa` → `git commit`
        // of an ordinary working session. `ACMR` and not `ACMRD`: a path deleted in the index will
        // not ship, and it is not on disk to be read either.
        $staged = $this->runExternal(
            ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z', 'HEAD']
        );

        $uncommitted = array_values(array_unique(array_filter(
            array_merge(explode("\0", $listed), explode("\0", $staged)),
            static fn (string $p): bool => $p !== ''
        )));

        // Two paths that do not exist are asked about alongside the real ones, for three reasons:
        // the question is then put unconditionally (so the number of assertions in this test does
        // not depend on whether the working tree happens to be clean), the answers are known in
        // advance, and they differ from each other — without that last part a `check-attr` that
        // answered "set" to everything would look exactly like a clean tree. `check-attr` judges
        // by path, not by existence; that is measured, and these two are the measurement.
        $shipsControl = 'src/.export-ignore-control';
        $doesNotShipControl = 'docs/.export-ignore-control';
        $queried = array_merge($uncommitted, [$shipsControl, $doesNotShipControl]);

        $answers = $this->runExternal(
            ['git', 'check-attr', '-z', '--stdin', 'export-ignore'],
            implode("\0", $queried) . "\0"
        );

        // `-z` output is a flat run of NUL-terminated fields, three per answer: path, attribute,
        // value. No quoting, no colons to split on, no path shape that can be mistaken for a
        // separator.
        $fields = explode("\0", $answers);
        $fieldCount = count($fields);
        $parsed = [];

        for ($i = 0; $i + 2 < $fieldCount; $i += 3) {
            $parsed[$fields[$i]] = $fields[$i + 2];
        }

        // The default is towards READING, and this premise is why the default can afford to be
        // anything at all. "Asked git" and "got an answer about this path" are two different
        // facts, and an answer that did not come back must stop the run rather than quietly
        // subtract a file from the distribution — that mistake, made here as `?? 'set'`, is what
        // hid the quoting bug above.
        $unresolved = array_values(array_filter(
            $queried,
            static fn (string $path): bool => !array_key_exists($path, $parsed)
        ));
        self::assertSame([], $unresolved, '`git check-attr` returned no verdict for a path it was asked about.');

        self::assertSame('unset', $parsed[$shipsControl], 'check-attr no longer says src/ ships.');
        self::assertSame('set', $parsed[$doesNotShipControl], 'check-attr no longer says docs/ does not ship.');

        $files = [];

        foreach ($uncommitted as $path) {
            // Only an explicit "set" excludes. Any other verdict — including one this test does
            // not know — leads to the file being read.
            if ($parsed[$path] !== 'set') {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * One external command, run without a shell, with its own exit code and its own stderr.
     *
     * No shell: nothing can be word-split, glob-expanded or quoted wrongly on the way in, and
     * there is no pipeline whose exit code could belong to the wrong command.
     *
     * Two limits of this helper are known, measured, and left standing — both are about what
     * happens when the run does not reach its own `finally`:
     *
     *   - stderr is slurped whole (`file_get_contents` below), and the message of the premise that
     *     carries it is built as an argument, that is, on every call and not only on a failing
     *     one. Under the suite's `memory_limit` of 128M a child writing 32 MiB of stderr passes
     *     and one writing 64 MiB kills the run with `Fatal error: Premature end of PHP process` —
     *     measured with a stub `tar`, whether that `tar` then failed or succeeded.
     *   - a run that dies that way, or one killed by `SIGKILL`, skips `finally` and leaves the
     *     temporary files of the call in flight: at most three, measured — `testbench-shipped-*`
     *     (573 440 bytes, the archive `git archive --output=` needs a NAME for and which therefore
     *     cannot be nameless), plus this call's stdin and stderr files. The stdin file is the path
     *     list and is not bounded: 55 bytes on a clean tree, 318 055 bytes with 6 000 untracked
     *     files.
     *
     * The premise that the command exited zero belongs HERE and not to the caller. Written out at
     * every call site it could be forgotten, and it was: the descriptor probe went in without one,
     * so its exit code and its stderr were dropped at the destructuring and its failure reddened
     * the descriptor comparison instead — which named the wrong culprit and threw the real stderr
     * away. Made here, the premise is created by the same line that creates the call, and only
     * the child's stdout is handed back: there is no status left to destructure away.
     *
     * The price, measured with a `git` stub exiting 42: the top frame of the failure is now this
     * method, one and the same for every call, where it used to be the call site. The call site is
     * named by the SECOND frame. "Its own failure carries its own command and its own stderr" is
     * unchanged.
     *
     * No caller in this file expects a non-zero status, so there is no way to ask for one.
     *
     * The message names the command from `$command`, each argument longer than
     * {@see self::ARGUMENT_LIMIT} bytes cut short — the probe's third argument is the whole source
     * of `DESCRIPTOR_PROBE` and would otherwise be the whole message. See that constant for what
     * the limit is measured against.
     *
     * @param list<string> $command
     */
    private function runExternal(array $command, string $stdin = ''): string
    {
        // AT MOST ONE PIPE. That is the whole rule here, and it is what keeps the two sides from
        // deadlocking: a pipe blocks its writer once the reader stops reading, and this parent
        // can only ever be reading one of them. Standard input was a pipe first, and the parent
        // writing it while the child, having filled its stdout pipe, had stopped reading hung
        // this test on the six thousand paths the argv limit was measured with. Standard error
        // was a pipe next, read only after stdout had been drained to EOF: a child that filled
        // its stderr buffer stopped before closing stdout, so the EOF never came. Measured with
        // a `tar` writing N bytes to stderr ahead of its listing, under a 25-second watchdog:
        // green at 65 536 bytes, hung at 65 537 — the pipe capacity of this machine to the byte.
        // Both are files below; stdout is the one pipe left, and stderr is read back off its
        // file only once the child is gone.
        $input = (string) tempnam(sys_get_temp_dir(), 'testbench-stdin-');
        file_put_contents($input, $stdin);
        $errors = (string) tempnam(sys_get_temp_dir(), 'testbench-stderr-');

        try {
            $descriptors = [0 => ['file', $input, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']];
            $pipes = [];
            $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2));

            self::assertIsResource($process, 'Could not start ' . $command[0] . '.');

            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            $errorOutput = (string) file_get_contents($errors);

            self::assertSame(0, $status, '`' . $this->abbreviated($command) . "` failed:\n" . $errorOutput);

            return $output;
        } finally {
            @unlink($input);
            @unlink($errors);
        }
    }

    /**
     * The command as the premise of {@see self::runExternal()} names it.
     *
     * `mb_strcut` and not `substr`: both cut on a byte count, which is what the limit is about, but
     * only `mb_strcut` refuses to cut in the middle of a UTF-8 character — and a path in argv may
     * carry one. Measured on an argument whose 120th byte falls inside a three-byte character:
     * `mb_strcut(…, 0, 120)` returns 118 bytes and `mb_check_encoding(…, 'UTF-8')` is true, while
     * `substr(…, 0, 120)` returns 120 bytes and the same check is false.
     *
     * An argument that contains whitespace, and an empty one, are wrapped in single quotes, because
     * otherwise the boundaries between arguments are guesswork: `PHP_BINARY` on the machine this was
     * written on is `/Users/…/Library/Application Support/Herd/bin/php84`, and unquoted it reads as
     * two arguments. The quoting is for READING only — it is not shell quoting and the result is
     * not meant to be pasted into a shell; {@see self::runExternal()} runs no shell at all.
     *
     * @param list<string> $command
     */
    private function abbreviated(array $command): string
    {
        return implode(' ', array_map(
            static function (string $argument): string {
                $shown = strlen($argument) <= self::ARGUMENT_LIMIT
                    ? $argument
                    : mb_strcut($argument, 0, self::ARGUMENT_LIMIT) . '…[' . strlen($argument) . ' bytes]';

                return $argument === '' || preg_match('/\s/', $argument) === 1 ? "'" . $shown . "'" : $shown;
            },
            $command
        ));
    }
}

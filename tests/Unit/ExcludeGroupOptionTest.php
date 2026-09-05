<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use ModxKit\Testbench\Support\ProcessRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * More than one group cannot be excluded portably ON THE COMMAND LINE: the PHPUnit versions the
 * package supports behave differently, and each of them silently.
 *
 * Measured BY RUNS on a separate project with two tests in the group `alpha`; the second group in
 * the options is deliberately one that does not exist:
 *
 * | PHPUnit  | one option | two options            | comma-separated list      |
 * |----------|------------|------------------------|---------------------------|
 * | 10.5.36  | excludes   | only the LAST group    | excludes                  |
 * | 11.5.56  | excludes   | excludes both          | excludes                  |
 * | 12.5.33  | excludes   | excludes both          | excludes NOTHING          |
 *
 * Only a real run can serve as the oracle here: `--list-tests` on 10.5.36 ignores group filters
 * altogether (the list of two tests arrives in full even with `--exclude-group alpha`), and the
 * first edition of this test, built on it, gave a false picture — refuted by the lower bounds job
 * of run 33482550517.
 *
 * Hence the rule: several groups are excluded by `phpunit.xml` only, and the command-line option is
 * admissible for exactly one group. A manifest script that breaks this looks stricter than usual
 * while in fact including groups the runner has not provided for — and the CI job goes red with
 * tests that were never meant to run in it.
 */#[Group('unit')]
final class ExcludeGroupOptionTest extends TestCase
{
    /**
     * The premise of the whole rule: with ONE group the option works — `test:integration:with-clients`
     * rests on that. An oracle that does not touch the database: the whole unit suite lies in the
     * group `unit`, so excluding it must leave not a single test.
     *
     * `--filter` narrows the subprocess down to a foreign class deliberately: without it a broken
     * exclusion would run the entire unit suite together with THIS test, that is, a recursion.
     */
    public function testSingleGroupExclusionWorksOnTheRunningPhpunit(): void
    {
        $filter = ['--filter', 'DependencyFloorGuardTest'];

        self::assertGreaterThan(
            0,
            $this->executedTests($filter),
            'The oracle is broken: a subprocess with no group exclusion must run at least one test.'
        );

        self::assertSame(
            0,
            $this->executedTests([...$filter, '--exclude-group', 'unit']),
            'Excluding a single group by the option has stopped working — the manifest scripts rest on it.'
        );
    }

    public function testNoManifestScriptExcludesMoreThanOneGroupFromTheCommandLine(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');

        self::assertIsString($contents);

        /** @var array{scripts: array<string, string|list<string>>} $manifest */
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $offenders = [];
        $checked = 0;

        foreach ($manifest['scripts'] as $name => $script) {
            foreach ((array) $script as $line) {
                $occurrences = preg_match_all('/--exclude-group[ =](\S+)/', $line, $matches);
                $checked += $occurrences;

                if ($occurrences > 1) {
                    $offenders[] = sprintf('%s: the option is repeated %d times', $name, $occurrences);
                }

                foreach ($matches[1] as $groups) {
                    if (str_contains($groups, ',')) {
                        $offenders[] = sprintf('%s: --exclude-group %s', $name, $groups);
                    }
                }
            }
        }

        // Premise: the option is present in the manifest at all. Otherwise the check is green and empty.
        self::assertGreaterThan(0, $checked, 'Not a single manifest script uses --exclude-group.');

        self::assertSame(
            [],
            $offenders,
            "More than one group cannot be excluded portably on the command line — move the set\n"
            . "into the exclusions of `phpunit.xml` and run the suite without the option:\n" . implode("\n", $offenders)
        );
    }

    /**
     * How many tests actually RAN (not how many `--list-tests` enumerated, see the class docblock).
     *
     * @param list<string> $options
     */
    private function executedTests(array $options): int
    {
        $root = dirname(__DIR__, 2);
        $result = (new ProcessRunner())->run(
            [PHP_BINARY, $root . '/vendor/bin/phpunit', '--testsuite', 'unit', ...$options],
            $root
        );
        $output = $result->output();

        if (str_contains($output, 'No tests executed')) {
            return 0;
        }

        self::assertSame(
            1,
            preg_match('/^(?:OK|Tests:).*?(\d+) (?:test|assertion)/mi', $output, $matches),
            'The phpunit subprocess did not say how many tests it ran: ' . $output
        );

        return (int) $matches[1];
    }
}

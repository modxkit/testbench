<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The `dependency lower bounds` job proves that the FLOOR of every dependency the package declares
 * really is exercised: the suite is run on exactly that floor. The proof rests on version literals
 * inside `.github/workflows/tests.yml`, and a literal lives apart from `composer.json` and
 * therefore drifts from it silently — the bound is moved in the manifest, forgotten in the job, and
 * the guard goes on confirming yesterday's truth.
 *
 * This test closes the gap between the two places: every `require` dependency must be covered by a
 * guard in the job, and the version in the guard must match the floor of the declared bound. A
 * divergence goes red here rather than a month later in CI.
 *
 * Why the floor is computed rather than compared against the text of the bound itself: the bound
 * `^10.5.36|^11.0|^12.0` allows three branches, and `--prefer-lowest` descends into the lowest one
 * — 10.5.36. That is the number that must stand in the guard.
 */
#[Group('unit')]
final class DependencyFloorGuardTest extends TestCase
{
    public function testEveryDeclaredDependencyFloorIsGuardedByTheLowestBoundsJob(): void
    {
        $floors = $this->declaredFloors();
        $guards = $this->guardsOfTheLowestBoundsJob();

        // Preconditions: both parses found something. A broken parse (the job renamed, a different
        // shape of the `composer show` line) would otherwise go green without comparing anything.
        self::assertNotSame([], $floors, 'Not a single package dependency was found in composer require.');
        self::assertNotSame([], $guards, 'Not a single `composer show … | grep` check was found in the lower bounds job.');

        self::assertSame(
            array_keys($floors),
            array_keys($guards),
            'The lower bounds job must cover EVERY declared dependency with a guard: '
            . 'an uncovered bound is never exercised and is therefore confirmed by nothing.'
        );

        self::assertSame(
            $floors,
            $guards,
            'The version in the job guard has drifted from the floor of the bound in composer.json — the '
            . 'guard confirms a version other than the one the package promises to work on.'
        );
    }

    /**
     * The floor of every declared package dependency: the lower bound of the first (lowest) branch
     * of the constraint, padded to three components. `php` and the extensions are skipped —
     * `composer show` does not speak of them in package versions.
     *
     * @return array<string, string>
     */
    private function declaredFloors(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');

        self::assertIsString($contents);

        /** @var array{require: array<string, string>} $manifest */
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $floors = [];

        foreach ($manifest['require'] as $package => $constraint) {
            if ($package === 'php' || str_starts_with($package, 'ext-')) {
                continue;
            }

            $lowestBranch = explode('|', $constraint)[0];
            $version = ltrim(trim($lowestBranch), '^~>=v ');
            $parts = explode('.', $version);

            while (count($parts) < 3) {
                $parts[] = '0';
            }

            $floors[$package] = implode('.', $parts);
        }

        ksort($floors);

        return $floors;
    }

    /**
     * The versions the job guard requires. The shape of the line is `composer show <package> |
     * grep -E '^versions … v?<version>'`; the dots in the `grep` pattern are escaped, so the
     * backslashes are stripped.
     *
     * @return array<string, string>
     */
    private function guardsOfTheLowestBoundsJob(): array
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/tests.yml');

        self::assertIsString($workflow);

        preg_match_all(
            "#composer show (\S+) \| grep -E '\^versions[^']*?v\?([0-9\\\\.]+)'#",
            $workflow,
            $matches,
            PREG_SET_ORDER
        );

        $guards = [];

        foreach ($matches as $match) {
            $guards[$match[1]] = str_replace('\\', '', $match[2]);
        }

        ksort($guards);

        return $guards;
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Environment;

use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Tests\Support\OwnsTestbenchEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The configuration is read from the environment, so the test sets the environment ITSELF (see
 * {@see OwnsTestbenchEnvironment}) — otherwise the class would go red on the regular variables of a
 * developer who has a test database of their own (`MODX_TESTBENCH_DB_NAME`), and `composer qa`
 * would be red out of the box.
 */
#[Group('unit')]
final class TestbenchConfigTest extends TestCase
{
    use OwnsTestbenchEnvironment;

    public function testDefaultsAreAppliedWhenEnvironmentIsEmpty(): void
    {
        $config = TestbenchConfig::fromEnvironment();

        self::assertSame('zip', $config->provider);
        self::assertSame('127.0.0.1', $config->database->host);
        self::assertSame(3306, $config->database->port);
        self::assertSame('modx_testbench', $config->database->name);
        self::assertSame('modx_', $config->database->prefix);
        self::assertFalse($config->forceInstall);
    }

    public function testEnvironmentOverridesDefaults(): void
    {
        $_SERVER['MODX_TESTBENCH_PROVIDER'] = 'git';
        $_SERVER['MODX_TESTBENCH_DB_PORT'] = '3307';
        $_SERVER['MODX_TESTBENCH_FORCE_INSTALL'] = '1';

        $config = TestbenchConfig::fromEnvironment();

        self::assertSame('git', $config->provider);
        self::assertSame(3307, $config->database->port);
        self::assertTrue($config->forceInstall);
    }

    /**
     * The fingerprint stays usable as a component of a MySQL database name — twelve characters
     * from `[0-9a-f]`.
     */
    public function testFingerprintIsTwelveHexadecimalCharacters(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{12}$/',
            TestbenchConfig::fromEnvironment()->fingerprint()
        );
    }

    /**
     * FR-ENV-4 taken literally: an input that changes the outcome of the install must change the
     * environment directory too. Five of these variables (`DB_USER`, `DB_PASS`, `DB_COLLATION`,
     * `ADMIN_USER`, `ADMIN_PASS`) travelled into `setup/config.xml` without changing the
     * fingerprint — an installed environment went on being reused with foreign credentials.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function inputsThatShapeTheInstallation(): iterable
    {
        yield 'core provider' => ['MODX_TESTBENCH_PROVIDER', 'git'];
        yield 'core version' => ['MODX_TESTBENCH_VERSION', '3.1.3-pl'];
        yield 'DBMS host' => ['MODX_TESTBENCH_DB_HOST', 'db.example.test'];
        yield 'DBMS port' => ['MODX_TESTBENCH_DB_PORT', '3307'];
        yield 'database name' => ['MODX_TESTBENCH_DB_NAME', 'modx_other'];
        yield 'DBMS user' => ['MODX_TESTBENCH_DB_USER', 'tester'];
        yield 'DBMS password' => ['MODX_TESTBENCH_DB_PASS', 'secret'];
        yield 'table prefix' => ['MODX_TESTBENCH_DB_PREFIX', 'other_'];
        yield 'charset' => ['MODX_TESTBENCH_DB_CHARSET', 'utf8'];
        yield 'collation' => ['MODX_TESTBENCH_DB_COLLATION', 'utf8mb4_unicode_ci'];
        yield 'administrator login' => ['MODX_TESTBENCH_ADMIN_USER', 'someone'];
        yield 'administrator password' => ['MODX_TESTBENCH_ADMIN_PASS', 'AnotherPass123!'];
        yield 'administrator email' => ['MODX_TESTBENCH_ADMIN_EMAIL', 'someone@example.com'];
    }

    #[DataProvider('inputsThatShapeTheInstallation')]
    public function testFingerprintChangesWithEveryInputThatShapesTheInstallation(
        string $variable,
        string $value
    ): void {
        $before = TestbenchConfig::fromEnvironment()->fingerprint();

        $_SERVER[$variable] = $value;

        self::assertNotSame($before, TestbenchConfig::fromEnvironment()->fingerprint());
    }

    /**
     * The mirror half of FR-ENV-4: an input that does not shape the outcome of the install must not
     * dare change the environment directory — otherwise the environment is reinstalled for nothing.
     * `GIT_REF` and `CORE_PATH` used to be part of the fingerprint ALWAYS, including with the `zip`
     * provider.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function inputsThatDoNotShapeTheInstallation(): iterable
    {
        yield 'release cache directory' => ['MODX_TESTBENCH_CACHE_DIR', '/tmp/another-cache'];
        yield 'environment directory' => ['MODX_TESTBENCH_WORKSPACE', '/tmp/another-workspace'];
        yield 'forced reinstall' => ['MODX_TESTBENCH_FORCE_INSTALL', '1'];
        yield 'git branch with the zip provider' => ['MODX_TESTBENCH_GIT_REF', '3.0.x'];
        yield 'path to the distribution with the zip provider' => ['MODX_TESTBENCH_CORE_PATH', '/srv/modx'];
    }

    #[DataProvider('inputsThatDoNotShapeTheInstallation')]
    public function testFingerprintIgnoresInputsThatDoNotShapeTheInstallation(
        string $variable,
        string $value
    ): void {
        $before = TestbenchConfig::fromEnvironment()->fingerprint();

        $_SERVER[$variable] = $value;

        self::assertSame($before, TestbenchConfig::fromEnvironment()->fingerprint());
    }

    /**
     * The provider part of the fingerprint comes from the provider itself (`SPEC.md:185`), so the
     * variable that matters is exactly the one the chosen provider reads: for `git` it is the
     * branch, not the release version.
     */
    public function testFingerprintFollowsTheSelectedProviderOnly(): void
    {
        $_SERVER['MODX_TESTBENCH_PROVIDER'] = 'git';
        $_SERVER['MODX_TESTBENCH_GIT_REF'] = '3.x';

        $before = TestbenchConfig::fromEnvironment()->fingerprint();

        $_SERVER['MODX_TESTBENCH_VERSION'] = '3.1.3-pl';

        self::assertSame($before, TestbenchConfig::fromEnvironment()->fingerprint());

        $_SERVER['MODX_TESTBENCH_GIT_REF'] = '3.0.x';

        self::assertNotSame($before, TestbenchConfig::fromEnvironment()->fingerprint());
    }

    public function testFingerprintFollowsTheDistributionPathForTheLocalProvider(): void
    {
        $_SERVER['MODX_TESTBENCH_PROVIDER'] = 'local';
        $_SERVER['MODX_TESTBENCH_CORE_PATH'] = '/srv/modx';

        $before = TestbenchConfig::fromEnvironment()->fingerprint();

        $_SERVER['MODX_TESTBENCH_CORE_PATH'] = '/srv/other-modx';

        self::assertNotSame($before, TestbenchConfig::fromEnvironment()->fingerprint());
    }

    /**
     * A configuration for which no provider can be built (an unknown name, `local` without a path)
     * must yield a fingerprint all the same: it is computed BEFORE any diagnostics —
     * `Workspace::forConfig()` builds the directory path from it, and `status` prints the state.
     * The real reason for the refusal is named by the preparation of the environment.
     */
    public function testFingerprintSurvivesAProviderThatCannotBeBuilt(): void
    {
        $_SERVER['MODX_TESTBENCH_PROVIDER'] = 'bogus';

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{12}$/',
            TestbenchConfig::fromEnvironment()->fingerprint()
        );

        $_SERVER['MODX_TESTBENCH_PROVIDER'] = 'local';

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{12}$/',
            TestbenchConfig::fromEnvironment()->fingerprint()
        );
    }

    public function testDsnIsBuiltFromDatabaseConfig(): void
    {
        $config = TestbenchConfig::fromEnvironment();

        self::assertSame(
            'mysql:host=127.0.0.1;port=3306;dbname=modx_testbench;charset=utf8mb4',
            $config->database->dsn()
        );
    }
}

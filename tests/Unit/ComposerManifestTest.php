<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit;

use ModxKit\Testbench\Database\PhpDumper;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Support\ProcessRunner;
use ModxKit\Testbench\TestCase as TestbenchTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;

/**
 * The manifest against the code: whatever the package cannot work without at the CONSUMER's must
 * stand in `require`, and whatever the package's own suite cannot run without in `require-dev`.
 * Errors of this class are invisible while developing the package at all — everything is installed
 * here — while the consumer gets a runtime failure instead of a resolution failure.
 *
 * The need is derived from BEHAVIOUR and reflection rather than from the text of other files: the
 * test must not go red with a message about composer.json because of a harmless refactoring of
 * `DatabaseConfig`. The only exception is `ext-mbstring` (see the docblock of the corresponding
 * test), and there the source is parsed by a tokenizer rather than by a substring search.
 */
#[Group('unit')]
final class ComposerManifestTest extends TestCase
{
    /**
     * `ModxKit\Testbench\TestCase` EXTENDS `PHPUnit\Framework\TestCase`: without PHPUnit the
     * package's public API does not even load, which makes it a run-time dependency rather than a
     * development one.
     */
    public function testPhpunitIsARuntimeDependencyBecauseThePublicApiExtendsIt(): void
    {
        self::assertContains(
            PHPUnitTestCase::class,
            class_parents(TestbenchTestCase::class),
            'Premise of the test: the package public API extends PHPUnit.'
        );

        $manifest = $this->manifest();

        self::assertArrayHasKey('phpunit/phpunit', $manifest['require'], 'PHPUnit must be in require');
        self::assertArrayNotHasKey(
            'phpunit/phpunit',
            $manifest['require-dev'],
            'there is no need to duplicate the dependency in require-dev: composer validate --strict is against it'
        );
    }

    /**
     * The package builds MySQL DSNs exclusively, so `ext-pdo` alone is not enough: without the
     * driver the consumer gets `PDOException: could not find driver` in the middle of a run.
     */
    public function testMysqlDriverIsDeclaredBecausePackageBuildsMysqlDsnOnly(): void
    {
        $dsn = (new DatabaseConfig(
            host: '127.0.0.1',
            port: 3306,
            name: 'modx_testbench_probe',
            user: 'tester',
            password: 'secret',
            prefix: 'modx_',
            charset: 'utf8mb4',
            collation: 'utf8mb4_general_ci',
        ))->dsn();

        self::assertStringStartsWith('mysql:', $dsn, 'Premise of the test: the package DSN is always mysql.');

        $manifest = $this->manifest();

        self::assertArrayHasKey('ext-pdo', $manifest['require']);
        self::assertArrayHasKey('ext-pdo_mysql', $manifest['require']);
        self::assertContains(
            'mysql',
            PDO::getAvailableDrivers(),
            'The declared driver must be available where the package is developed.'
        );
    }

    /**
     * `PhpDumper` trims diagnostics with `mb_*`. That need can be derived neither from behaviour
     * (every path of `PhpDumper` requires a live database) nor from reflection — only from the
     * source; so the source is parsed by a TOKENIZER, which is indifferent to whitespace, line
     * breaks and comments, rather than by a substring search.
     */
    public function testMbstringIsDeclaredBecauseTheDumperCallsIt(): void
    {
        self::assertNotEmpty($this->multibyteCalls(PhpDumper::class), 'Premise of the test: PhpDumper calls mb_*.');

        self::assertArrayHasKey('ext-mbstring', $this->manifest()['require']);
        self::assertTrue(function_exists('mb_strlen'));
    }

    /**
     * The package's suite kills a child process with a signal (`ProcessRunnerTest`), and
     * `posix_kill()` comes with `ext-posix` — on a PHP build without it the unit suite would fail
     * with `Call to undefined function`. The package does not need that extension at run time, so
     * `require-dev` rather than `require`.
     *
     * The premise is checked in THE SAME interpreter that exercises it: `posix_kill()` is called
     * not here but in the child process, and that one is launched by `PHP_BINARY` — that is, by
     * this very PHP rather than by the first one in `PATH`. It is asked itself, not the current
     * process.
     */
    public function testPosixIsDeclaredForTheOwnSuiteOnly(): void
    {
        $child = (new ProcessRunner())->run([
            PHP_BINARY,
            '-r',
            'echo function_exists("posix_kill") ? "yes" : "no";',
        ]);

        self::assertSame('yes', trim($child->stdout), 'Premise: the child PHP can do posix_kill().');

        $manifest = $this->manifest();

        self::assertArrayHasKey('ext-posix', $manifest['require-dev']);
        self::assertArrayNotHasKey('ext-posix', $manifest['require']);
    }

    /**
     * The names of the `mb_*` functions the class calls, taken from the tokens of its file.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private function multibyteCalls(string $class): array
    {
        $file = (new ReflectionClass($class))->getFileName();

        self::assertIsString($file);

        $calls = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && $token[0] === T_STRING && str_starts_with($token[1], 'mb_')) {
                $calls[] = $token[1];
            }
        }

        return $calls;
    }

    /**
     * @return array{require: array<string, string>, require-dev: array<string, string>}
     */
    private function manifest(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');

        self::assertIsString($contents);

        /** @var array{require: array<string, string>, require-dev: array<string, string>} $manifest */
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $manifest;
    }
}

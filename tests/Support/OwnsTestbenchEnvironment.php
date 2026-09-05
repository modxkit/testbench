<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Support;

use ModxKit\Testbench\Environment\TestbenchConfig;

/**
 * A unit test that calls {@see TestbenchConfig::fromEnvironment()} reads the real variables of the
 * process — without an environment of its own it would go red, or stay silent, differently
 * depending on what the consumer's environment holds (their own test database, a DBMS password).
 *
 * Clearing the variables has to be done by OVERWRITING: `unset($_SERVER['MODX_TESTBENCH_…'])`
 * does not override the real process variable — `Env::get()` reads it through `getenv()` as a
 * fallback. An empty string, on the other hand, is equivalent to a missing value for `Env::get()`,
 * so `''` is the only way to get the default back whatever the run's environment holds.
 *
 * Exact restoration is delegated to {@see RestoresServerVariables}: a sentinel of its own for
 * absence (`null`) did not tell "the variable was not there" from "the variable was `null`".
 *
 * Merged from two byte-identical copies ({@see \ModxKit\Testbench\Tests\Unit\Environment\TestbenchConfigTest}
 * and {@see \ModxKit\Testbench\Tests\Unit\Installer\ConfigXmlWriterTest}): the list of 18
 * variables would otherwise have to be kept up to date in two places at once.
 */
trait OwnsTestbenchEnvironment
{
    use RestoresServerVariables;

    /**
     * Every variable {@see TestbenchConfig::fromEnvironment()} reads.
     *
     * @var list<string>
     */
    private const VARIABLES = [
        'MODX_TESTBENCH_PROVIDER',
        'MODX_TESTBENCH_VERSION',
        'MODX_TESTBENCH_GIT_REF',
        'MODX_TESTBENCH_CORE_PATH',
        'MODX_TESTBENCH_CACHE_DIR',
        'MODX_TESTBENCH_WORKSPACE',
        'MODX_TESTBENCH_FORCE_INSTALL',
        'MODX_TESTBENCH_DB_HOST',
        'MODX_TESTBENCH_DB_PORT',
        'MODX_TESTBENCH_DB_NAME',
        'MODX_TESTBENCH_DB_USER',
        'MODX_TESTBENCH_DB_PASS',
        'MODX_TESTBENCH_DB_PREFIX',
        'MODX_TESTBENCH_DB_CHARSET',
        'MODX_TESTBENCH_DB_COLLATION',
        'MODX_TESTBENCH_ADMIN_USER',
        'MODX_TESTBENCH_ADMIN_PASS',
        'MODX_TESTBENCH_ADMIN_EMAIL',
    ];

    /** @var callable(): void */
    private $restorePreviousEnvironment;

    protected function setUp(): void
    {
        $this->restorePreviousEnvironment = $this->serverVariableRestorer(self::VARIABLES);

        foreach (self::VARIABLES as $variable) {
            $_SERVER[$variable] = '';
        }
    }

    protected function tearDown(): void
    {
        ($this->restorePreviousEnvironment)();
    }
}

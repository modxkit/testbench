<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Concerns;

use MODX\Revolution\modChunk;
use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MODX\Revolution\Processors\ProcessorResponse;
use ModxKit\Testbench\TestCase;

/**
 * A stand-in for the base `TestCase` with a substitutable core.
 *
 * It is needed for two things that cannot be checked from inside the test itself: the failure paths
 * of `InteractsWithModx` that are unreachable against a live MODX 3.2.3-pl core, and observing what
 * `tearDown()` does — a test's own `tearDown()` runs after its body.
 *
 * The instance is created by hand, so `setUp()` is not called and the isolation strategy stays
 * empty: the transaction is managed by the test that created this stand-in.
 */
final class InteractsWithModxProbe extends TestCase
{
    /**
     * `PHPUnit\Framework\TestCase::__construct()` is declared `final`
     * (`vendor/phpunit/phpunit/src/Framework/TestCase.php:238`), so the core is substituted by a
     * factory rather than by the constructor.
     */
    public static function forModx(modX $modx): self
    {
        $probe = new self('interacts-with-modx-probe');
        $probe->modx = $modx;

        return $probe;
    }

    public function callRunProcessor(string $action): ProcessorResponse
    {
        return $this->runProcessor($action);
    }

    public function callCreateChunk(string $name, string $content): modChunk
    {
        return $this->createChunk($name, $content);
    }

    public function callActingAs(modUser $user): void
    {
        $this->actingAs($user);
    }

    public function callSetSetting(string $key, string $value): void
    {
        $this->setSetting($key, $value);
    }

    public function callTearDown(): void
    {
        $this->tearDown();
    }
}

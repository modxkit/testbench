<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Exception;

use ModxKit\Testbench\Exception\PackageRegistrationException;
use ModxKit\Testbench\Exception\TestbenchException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A pin: `atStep()` (declarative registration) and `atTransportStep()` (a transport package) must
 * give DIFFERENT advice on the next action — `atStep()` points at
 * `PackageDefinition`/`metadata.<dbtype>.php`, which is useless for a failure to build or install
 * a real transport package.
 */
#[Group('unit')]
final class PackageRegistrationExceptionTest extends TestCase
{
    public function testAtTransportStepNamesTheStepPackageAndReason(): void
    {
        $exception = PackageRegistrationException::atTransportStep('build', 'sampleextra-1.0.0-pl', 'the subprocess crashed');

        self::assertContains(TestbenchException::class, class_parents(PackageRegistrationException::class));
        self::assertStringContainsString('"build"', $exception->getMessage());
        self::assertStringContainsString('"sampleextra-1.0.0-pl"', $exception->getMessage());
        self::assertStringContainsString('the subprocess crashed', $exception->getMessage());
    }

    /**
     * Tells `atTransportStep()` from `atStep()`: the advice on the next action must point at the
     * build script, `core/packages/` or the subprocess output, and NOT at
     * `PackageDefinition`/`metadata.<dbtype>.php` — that advice is useless for a transport package
     * and leads away from the real cause.
     */
    public function testAtTransportStepAdviceDiffersFromDeclarativeAtStep(): void
    {
        $exception = PackageRegistrationException::atTransportStep('install', 'sampleextra-1.0.0-pl', 'reason');

        self::assertStringContainsString('build.transport.php', $exception->getMessage());
        self::assertStringContainsString('core/packages/', $exception->getMessage());
        self::assertStringNotContainsString('PackageDefinition', $exception->getMessage());
        self::assertStringNotContainsString('metadata.', $exception->getMessage());
    }

    public function testAtStepAdviceIsUnaffectedByTheNewFactory(): void
    {
        $exception = PackageRegistrationException::atStep('modNamespace', 'sampleextra', 'the object was not saved');

        self::assertStringContainsString('PackageDefinition', $exception->getMessage());
        self::assertStringNotContainsString('build.transport.php', $exception->getMessage());
    }
}

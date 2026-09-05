<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Concerns;

use MODX\Revolution\Error\modError;
use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * `$modx->error` is a core service that lives until the end of the PHPUnit process: neither the
 * transaction nor a snapshot rolls it back. A failed processor left the accumulated error in it, and
 * the NEXT test got that error in the response of ITS OWN processor — a foreign error explaining a
 * completely different test. Caught live: `element/chunk/get` with a non-existent id felled
 * `system/settings/update` in another test with the message "Chunk not found with id: 2147483647".
 */
#[Group('integration')]
final class ErrorStateResetTest extends TestCase
{
    public function testFailedProcessorLeavesItsErrorOnTheKernelService(): string
    {
        $response = $this->runProcessor('element/chunk/get', ['id' => 0x7FFFFFFF]);

        self::assertTrue($response->isError());
        self::assertInstanceOf(modError::class, $this->modx->error);
        self::assertTrue($this->modx->error->hasError());

        return $response->getMessage();
    }

    /**
     * The test that runs next must start with a clean `$modx->error` and receive the response of ITS
     * OWN processor rather than a foreign error.
     */
    #[Depends('testFailedProcessorLeavesItsErrorOnTheKernelService')]
    public function testNextTestDoesNotSeeTheForeignError(string $foreignMessage): void
    {
        self::assertNotSame('', $foreignMessage);
        self::assertInstanceOf(modError::class, $this->modx->error);
        self::assertFalse(
            $this->modx->error->hasError(),
            'The error of a failed processor survived the test boundary.'
        );

        $chunk = $this->createChunk('tb-error-reset-' . bin2hex(random_bytes(4)), 'ok');
        $response = $this->runProcessor('element/chunk/get', ['id' => $chunk->get('id')]);

        self::assertFalse($response->isError(), $response->getMessage());
        self::assertStringNotContainsString('2147483647', $response->getMessage());
        $name = $chunk->get('name');
        self::assertIsString($name);
        self::assertNotSame('', $name);
    }
}

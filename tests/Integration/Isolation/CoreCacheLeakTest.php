<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use PHPUnit\Framework\Attributes\Group;

/**
 * The core file cache leak under the default isolation — transactions.
 */
#[Group('integration')]
final class CoreCacheLeakTest extends CoreCacheLeakScenario
{
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use ModxKit\Testbench\Concerns\RefreshesDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The core file cache leak under the second isolation strategy: a snapshot brings the database
 * back, but the core's file cache it does not, and `Workspace/Packages/Install` (the
 * `TransportInstaller` path the trait exists for) regenerates it from the dirty state just as
 * `System/Settings/Update` does.
 */
#[Group('integration')]
final class CoreCacheLeakUnderSnapshotTest extends CoreCacheLeakScenario
{
    use RefreshesDatabase;
}

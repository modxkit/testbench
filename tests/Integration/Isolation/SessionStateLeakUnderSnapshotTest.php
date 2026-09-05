<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use ModxKit\Testbench\Concerns\RefreshesDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The MySQL session state leak under the second strategy: a snapshot brings the database back, but
 * the core connection's session it does not.
 */
#[Group('integration')]
final class SessionStateLeakUnderSnapshotTest extends SessionStateLeakScenario
{
    use RefreshesDatabase;
}

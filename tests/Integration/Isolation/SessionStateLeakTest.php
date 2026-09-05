<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use PHPUnit\Framework\Attributes\Group;

/**
 * The MySQL session state leak under the default isolation — transactions.
 */
#[Group('integration')]
final class SessionStateLeakTest extends SessionStateLeakScenario
{
}

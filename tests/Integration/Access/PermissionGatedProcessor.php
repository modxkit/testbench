<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Access;

use MODX\Revolution\Processors\Processor;

/**
 * A processor that guards itself with a permission, the way an extra's processor does.
 *
 * The base `Processor::checkPermissions()` answers `true` unconditionally
 * (`core/src/Revolution/Processors/Processor.php:94-97`); the permission is consulted by
 * `ModelProcessor::checkPermissions()`, which is exactly the one line repeated here
 * (`core/src/Revolution/Processors/ModelProcessor.php:37-40`). Repeating it rather than extending
 * `ModelProcessor` keeps the fixture free of a `classKey` and of the model machinery that has
 * nothing to do with the permission.
 *
 * @internal
 */
final class PermissionGatedProcessor extends Processor
{
    /** @var string */
    public $permission = 'save_document';

    /** @return bool */
    public function checkPermissions()
    {
        return $this->modx->hasPermission($this->permission);
    }

    /** @return array<string, mixed>|string */
    public function process()
    {
        return $this->success('', ['ran' => true]);
    }
}

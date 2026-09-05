<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Package;

use MODX\Revolution\modNamespace;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modX;
use ModxKit\Testbench\Exception\PackageRegistrationException;

/**
 * Brings the test MODX core into a state where the extra described by {@see PackageDefinition} is
 * "installed": namespace, xPDO model, tables, system settings and services.
 *
 * An extra that declares tables through {@see PackageDefinition::tables()} must be tested under the
 * `ModxKit\Testbench\Concerns\RefreshesDatabase` trait rather than under the default transaction:
 * {@see self::createTables()} calls `xPDOManager::createObjectContainer()`, and that is DDL. In
 * MySQL, DDL causes an implicit commit, so `TransactionIsolation::end()` would be unable to roll the
 * test back — it will detect the lost transaction and point at `RefreshesDatabase` through
 * `ModxKit\Testbench\Exception\TransactionLostException`.
 *
 * Part of the registration state survives the end of the test, because the core is a singleton for
 * the whole process ({@see \ModxKit\Testbench\Environment\TestbenchKernel::modx()}), while what is
 * rolled back is either the database (a snapshot or a transaction) or the little of the core's
 * memory that {@see \ModxKit\Testbench\Concerns\InteractsWithModx} explicitly backs up:
 * - the system settings applied by {@see self::applySettings()} through `setOption()` survive the
 *   test ONLY if the calling code backed up their keys before the registration — `PackageRegistrar`
 *   does no rolling back of its own (see `TestCase::setUp()`);
 * - `$modx->services`, filled in by {@see self::registerServices()}, and the
 *   `$xpdo->packages`/`$xpdo->classMap` entries filled in by {@see self::registerModel()} through
 *   `addPackage()`, are not subject to a rollback at all — they survive every test until the end of
 *   the whole PHPUnit run (the exhaustive list of what is rolled back is in `docs/DX_GUIDE.md`,
 *   section 4). For services that is safe thanks to the idempotent re-registration; for
 *   `addPackage()` it means that a second extra with a different table prefix under the same package
 *   name in one run will unpredictably overwrite the metadata of the first — a scenario outside the
 *   scope of this design.
 *
 * @internal
 */
final readonly class PackageRegistrar
{
    public function __construct(private modX $modx)
    {
    }

    public function register(PackageDefinition $definition): void
    {
        $this->registerNamespace($definition);
        $this->registerModel($definition);
        $this->createTables($definition);
        $this->applySettings($definition);
        $this->registerServices($definition);
    }

    private function registerNamespace(PackageDefinition $definition): void
    {
        $name = $definition->namespaceName();

        /** @var modNamespace|null $namespace */
        $namespace = $this->modx->getObject(modNamespace::class, ['name' => $name]);

        if ($namespace === null) {
            /** @var modNamespace $namespace */
            $namespace = $this->modx->newObject(modNamespace::class);
            $namespace->set('name', $name);
        }

        // The field is left alone if the declaration set nothing: for a NEW namespace the default
        // value ('' — modNamespace.php fieldMeta) is applied by xPDO itself, while for an EXISTING
        // one an unconditional write of '' would erase somebody else's data that happened to match
        // by namespace name.
        if ($definition->getCorePath() !== null) {
            $namespace->set('path', $definition->getCorePath());
        }

        if ($definition->getAssetsPath() !== null) {
            $namespace->set('assets_path', $definition->getAssetsPath());
        }

        if (!$namespace->save()) {
            throw PackageRegistrationException::atStep('modNamespace', $name, 'the object was not saved');
        }
    }

    private function registerModel(PackageDefinition $definition): void
    {
        $model = $definition->getModel();

        if ($model === null) {
            return;
        }

        $added = $this->modx->addPackage(
            $model['package'],
            $model['path'],
            $model['prefix'],
            $model['namespacePrefix']
        );

        if (!$added) {
            throw PackageRegistrationException::atStep(
                'addPackage',
                $definition->namespaceName(),
                "xPDO did not accept package {$model['package']} at path {$model['path']}"
            );
        }
    }

    private function createTables(PackageDefinition $definition): void
    {
        $tables = $definition->getTables();

        if ($tables === []) {
            return;
        }

        $manager = $this->modx->getManager();

        if ($manager === null) {
            throw PackageRegistrationException::atStep(
                'getManager',
                $definition->namespaceName(),
                'xPDO could not create an xPDOManager for the current connection'
            );
        }

        foreach ($tables as $class) {
            // createObjectContainer() returns false when the table already exists too — we check
            // the fact of its presence.
            if (!$manager->createObjectContainer($class) && !$this->tableExists($class)) {
                throw PackageRegistrationException::atStep(
                    'createObjectContainer',
                    $definition->namespaceName(),
                    "failed to create the table for {$class}"
                );
            }
        }
    }

    /**
     * `_`/`%` in a `LIKE` are pattern characters, not literals: `SHOW TABLES LIKE` could give a
     * false positive on a neighbouring name (for example `smp_sample_items` would match
     * `smpXsample_items`). The comparison against `information_schema.tables` is an exact `=`, with
     * no patterns.
     *
     * @param class-string $class
     */
    private function tableExists(string $class): bool
    {
        $table = trim((string) $this->modx->getTableName($class), '`');
        $statement = $this->modx->query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '
            . $this->modx->quote($table)
        );

        return $statement !== false && $statement->fetch() !== false;
    }

    private function applySettings(PackageDefinition $definition): void
    {
        foreach ($definition->getSettings() as $key => $value) {
            /** @var modSystemSetting|null $setting */
            $setting = $this->modx->getObject(modSystemSetting::class, ['key' => $key]);

            if ($setting === null) {
                /** @var modSystemSetting $setting */
                $setting = $this->modx->newObject(modSystemSetting::class);
                $setting->fromArray([
                    'key' => $key,
                    'namespace' => $definition->namespaceName(),
                    'xtype' => 'textfield',
                    'area' => 'default',
                ], '', true);
            }

            $setting->set('value', $value);

            // On failure `xPDOObject::save()` returns `false` rather than throwing
            // (`xPDOObject.php:1326`) — a swallowed result would leave the setting visible through
            // getOption() (see below) but absent from the database, and would fail later in another
            // test for another, wrong reason.
            if (!$setting->save()) {
                throw PackageRegistrationException::atStep(
                    'modSystemSetting',
                    $definition->namespaceName(),
                    "failed to save setting \"{$key}\""
                );
            }

            $this->modx->setOption($key, $value);
        }
    }

    private function registerServices(PackageDefinition $definition): void
    {
        foreach ($definition->getServices() as $key => $factory) {
            // The container lives as long as the core does (one process for the whole run), while
            // `register()` is called anew on every `setUp()`. Pimple freezes a service at the moment
            // of the first `get()`, and a repeated `add()` (that is, `offsetSet()`) on a frozen key
            // throws `Pimple\Exception\FrozenServiceException`
            // (`vendor/pimple/pimple/src/Pimple/Container.php:82-90`). We remove the existing entry
            // before re-registering — `offsetUnset()` clears the frozen flag unconditionally as well
            // (`Container.php:150-160`), so a fresh `add()` does not fail.
            if ($this->modx->services->has($key)) {
                $this->modx->services->offsetUnset($key);
            }

            $this->modx->services->add($key, static fn (): mixed => $factory());
        }
    }
}

<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Package;

use MODX\Revolution\modNamespace;
use MODX\Revolution\modSystemSetting;
use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\Exception\PackageRegistrationException;
use ModxKit\Testbench\Package\PackageDefinition;
use ModxKit\Testbench\Package\PackageRegistrar;
use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use SampleExtra\Model\SampleItem;
use SampleExtra\Service\SampleService;

#[Group('integration')]
final class PackageRegistrarTest extends TestCase
{
    use RefreshesDatabase;

    protected function packageDefinition(): PackageDefinition
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/SampleExtra/';

        return PackageDefinition::make('sampleextra')
            ->corePath($fixture)
            ->model('SampleExtra\\Model', $fixture . 'src/', 'smp_', 'SampleExtra\\')
            ->tables(SampleItem::class)
            ->settings(['sampleextra_limit' => 42])
            ->service(SampleService::class, fn (): SampleService => new SampleService($this->modx));
    }

    public function testNamespaceIsRegistered(): void
    {
        $this->assertObjectExists(modNamespace::class, ['name' => 'sampleextra']);
    }

    public function testModelTableIsCreatedAndUsable(): void
    {
        // Composer already autoloads the fixture directly, so xPDO is able to read the static::$metaMap
        // of the platform class even without addPackage() — and this check of the table by name is
        // exactly what does tell them apart: without addPackage() xPDO uses the global table_prefix
        // instead of the package's own prefix 'smp_' from PackageDefinition::model().
        self::assertSame('`smp_sample_items`', $this->modx->getTableName(SampleItem::class));

        $item = $this->modx->newObject(SampleItem::class);

        self::assertInstanceOf(SampleItem::class, $item);

        $item->fromArray(['name' => 'widget', 'quantity' => 7]);

        self::assertTrue($item->save());
        $this->assertObjectExists(SampleItem::class, ['name' => 'widget']);
    }

    public function testSettingsAndServicesAreAvailable(): void
    {
        self::assertSame('42', $this->modx->getOption('sampleextra_limit'));
        self::assertTrue($this->modx->services->has(SampleService::class));

        $service = $this->modx->services->get(SampleService::class);

        self::assertInstanceOf(SampleService::class, $service);
        self::assertSame(42, $service->limit());
    }

    /**
     * Proves the point self-sufficiently — without relying on the service-resolving test running
     * earlier (`phpunit.xml` sets no `executionOrder`; the order of methods is deterministic today,
     * but that is not a contract). Inside a single test the service is first resolved through
     * `get()`, freezing it in Pimple, and then the same package is registered again: without
     * removing the old entry the second `add()` would throw a
     * `Pimple\Exception\FrozenServiceException`.
     */
    public function testServiceStaysUsableAfterRepeatedRegistration(): void
    {
        $service = $this->modx->services->get(SampleService::class);
        self::assertInstanceOf(SampleService::class, $service);

        (new PackageRegistrar($this->modx))->register($this->packageDefinition());

        $serviceAfterReRegistration = $this->modx->services->get(SampleService::class);
        self::assertInstanceOf(SampleService::class, $serviceAfterReRegistration);
        self::assertSame(42, $serviceAfterReRegistration->limit());
    }

    /**
     * `xPDOObject::save()` returns `false` on a failure rather than throwing an exception itself
     * (`xPDOObject.php:1326`) — without checking the result, `applySettings()` would swallow the
     * failure.
     *
     * The failure is produced by making the table itself unreachable (`DROP TABLE` — safe under
     * `RefreshesDatabase`, the snapshot restores it after the test) rather than by overflowing the
     * `varchar(50)` primary key: an overflow yields `false` only with a strict server `sql_mode`,
     * while on a non-strict one the value is truncated silently and `save()` returns `true` — the
     * test would fail for an unexplained reason on somebody else's machine (CI on somebody else's
     * MySQL). A missing table is driver error 1146, not a warning about data, and does not depend on
     * `sql_mode`.
     */
    public function testRegistrationFailsWithDiagnosticStepWhenSettingCannotBeSaved(): void
    {
        $this->modx->exec('DROP TABLE ' . $this->modx->getTableName(modSystemSetting::class));

        $definition = PackageDefinition::make('sampleextra')->settings(['sampleextra_limit' => 'value']);

        $this->expectException(PackageRegistrationException::class);
        $this->expectExceptionMessageMatches('/modSystemSetting/');

        (new PackageRegistrar($this->modx))->register($definition);
    }
}

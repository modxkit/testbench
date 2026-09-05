<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Package;

use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Package\PackageDefinition;
use ModxKit\Testbench\Tests\Unit\Package\Fixture\SampleCategory;
use ModxKit\Testbench\Tests\Unit\Package\Fixture\SampleItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PackageDefinitionTest extends TestCase
{
    public function testBuildsCompleteDefinition(): void
    {
        $definition = PackageDefinition::make('sampleextra')
            ->corePath('/tmp/extra/core/components/sampleextra/')
            ->assetsPath('/tmp/extra/assets/components/sampleextra/')
            ->model('SampleExtra\\Model', '/tmp/extra/core/components/sampleextra/src/', 'smp_', 'SampleExtra\\')
            ->tables(SampleItem::class)
            ->settings(['sampleextra_limit' => 10])
            ->service('sampleextra.service', static fn (): string => 'service');

        $model = $definition->getModel();

        self::assertSame('sampleextra', $definition->namespaceName());
        self::assertSame('/tmp/extra/core/components/sampleextra/', $definition->getCorePath());
        self::assertSame('/tmp/extra/assets/components/sampleextra/', $definition->getAssetsPath());
        self::assertNotNull($model);
        self::assertSame('SampleExtra\\Model', $model['package']);
        self::assertSame('/tmp/extra/core/components/sampleextra/src/', $model['path']);
        self::assertSame('smp_', $model['prefix']);
        self::assertSame('SampleExtra\\', $model['namespacePrefix']);
        self::assertSame([SampleItem::class], $definition->getTables());
        self::assertSame(['sampleextra_limit' => '10'], $definition->getSettings());
        self::assertArrayHasKey('sampleextra.service', $definition->getServices());
    }

    /**
     * An empty namespace used to travel all the way to `PackageRegistrar` and be saved as a
     * `modNamespace` with an empty `name`: registration "succeeded", while not a single path of
     * the extra was known to the core. The refusal belongs where the mistake is made.
     */
    public function testEmptyNamespaceIsRefusedAtTheDeclarationSite(): void
    {
        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessageMatches('/modNamespace/');

        PackageDefinition::make('  ');
    }

    public function testFreshDefinitionCarriesOnlyNamespace(): void
    {
        $definition = PackageDefinition::make('sampleextra');

        self::assertSame('sampleextra', $definition->namespaceName());
        self::assertNull($definition->getCorePath());
        self::assertNull($definition->getAssetsPath());
        self::assertNull($definition->getModel());
        self::assertSame([], $definition->getTables());
        self::assertSame([], $definition->getSettings());
        self::assertSame([], $definition->getServices());
    }

    public function testPathsAreNormalizedWithTrailingSlash(): void
    {
        $definition = PackageDefinition::make('sampleextra')
            ->corePath('/tmp/extra/core/components/sampleextra')
            ->assetsPath('/tmp/extra/assets/components/sampleextra')
            ->model('SampleExtra\\Model', '/tmp/extra/core/components/sampleextra/src');

        $model = $definition->getModel();

        self::assertSame('/tmp/extra/core/components/sampleextra/', $definition->getCorePath());
        self::assertSame('/tmp/extra/assets/components/sampleextra/', $definition->getAssetsPath());
        self::assertNotNull($model);
        self::assertSame('/tmp/extra/core/components/sampleextra/src/', $model['path']);
    }

    public function testModelPrefixesDefaultToNull(): void
    {
        $model = PackageDefinition::make('sampleextra')
            ->model('SampleExtra\\Model', '/tmp/extra/core/components/sampleextra/src/')
            ->getModel();

        self::assertNotNull($model);
        self::assertNull($model['prefix']);
        self::assertNull($model['namespacePrefix']);
    }

    public function testTablesAccumulateWithoutDuplicates(): void
    {
        $definition = PackageDefinition::make('sampleextra')
            ->tables(SampleItem::class)
            ->tables(SampleCategory::class, SampleItem::class);

        self::assertSame([SampleItem::class, SampleCategory::class], $definition->getTables());
    }

    public function testSettingsAreMergedAndCastToStrings(): void
    {
        $definition = PackageDefinition::make('sampleextra')
            ->settings(['sampleextra_limit' => 10, 'sampleextra_enabled' => true])
            ->settings(['sampleextra_enabled' => false, 'sampleextra_label' => 'demo']);

        self::assertSame([
            'sampleextra_limit' => '10',
            'sampleextra_enabled' => '0',
            'sampleextra_label' => 'demo',
        ], $definition->getSettings());
    }

    public function testServicesAccumulateAndKeepFactories(): void
    {
        $definition = PackageDefinition::make('sampleextra')
            ->service('sampleextra.first', static fn (): string => 'first')
            ->service('sampleextra.second', static fn (): string => 'second');

        $services = $definition->getServices();

        self::assertSame(['sampleextra.first', 'sampleextra.second'], array_keys($services));
        self::assertSame('second', $services['sampleextra.second']());
    }

    public function testDefinitionIsImmutable(): void
    {
        $base = PackageDefinition::make('sampleextra');
        $extended = $base->tables(SampleItem::class);

        self::assertSame([], $base->getTables());
        self::assertSame([SampleItem::class], $extended->getTables());
    }

    public function testEveryMutatorReturnsNewInstanceAndKeepsSourceUntouched(): void
    {
        $base = PackageDefinition::make('sampleextra')
            ->corePath('/tmp/extra/core/components/sampleextra/')
            ->settings(['sampleextra_limit' => 10])
            ->service('sampleextra.first', static fn (): string => 'first');

        $mutated = [
            $base->corePath('/tmp/other/'),
            $base->assetsPath('/tmp/other/'),
            $base->model('Other\\Model', '/tmp/other/src/'),
            $base->tables(SampleItem::class),
            $base->settings(['sampleextra_limit' => 20]),
            $base->service('sampleextra.second', static fn (): string => 'second'),
        ];

        foreach ($mutated as $index => $definition) {
            self::assertNotSame($base, $definition, "mutator #{$index} returned the same instance");
        }

        self::assertSame('/tmp/extra/core/components/sampleextra/', $base->getCorePath());
        self::assertNull($base->getAssetsPath());
        self::assertNull($base->getModel());
        self::assertSame([], $base->getTables());
        self::assertSame(['sampleextra_limit' => '10'], $base->getSettings());
        self::assertSame(['sampleextra.first'], array_keys($base->getServices()));
    }
}

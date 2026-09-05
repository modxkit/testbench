<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Package;

use ModxKit\Testbench\Exception\TestbenchException;

/**
 * An immutable declaration of the extra under test: namespace, paths, model, tables, system
 * settings and services. Every mutator returns a new instance, so the base declaration can safely be
 * extended in individual tests.
 *
 * The signature of {@see self::model()} repeats
 * `xPDO::addPackage($pkg, $path, $prefix, $namespacePrefix)`
 * (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:465`).
 */
final readonly class PackageDefinition
{
    /**
     * @param array{package: string, path: string, prefix: ?string, namespacePrefix: ?string}|null $model
     * @param array<int, class-string>                                                             $tables
     * @param array<string, string>                                                                $settings
     * @param array<string, callable>                                                              $services
     */
    private function __construct(
        private string $namespace,
        private ?string $corePath = null,
        private ?string $assetsPath = null,
        private ?array $model = null,
        private array $tables = [],
        private array $settings = [],
        private array $services = [],
    ) {
    }

    /**
     * An empty name used to reach `PackageRegistrar` and be saved as a `modNamespace` with an empty
     * `name`: the registration looked successful while not a single path of the extra became known
     * to the core. The refusal stands where the mistake is made — otherwise it would have to be
     * hunted down by the symptom "the extra's snippet is not found".
     */
    public static function make(string $namespace): self
    {
        if (trim($namespace) === '') {
            throw new TestbenchException(
                'The extra namespace cannot be empty: PackageDefinition::make() sets the name under '
                . 'which a modNamespace is created in the core and to which the core/assets paths, '
                . 'the model and the system settings are bound. Pass the name of your extra — the '
                . 'same one its transport package carries.'
            );
        }

        return new self($namespace);
    }

    public function corePath(string $path): self
    {
        return $this->with(corePath: rtrim($path, '/') . '/');
    }

    public function assetsPath(string $path): self
    {
        return $this->with(assetsPath: rtrim($path, '/') . '/');
    }

    public function model(
        string $package,
        string $path,
        ?string $tablePrefix = null,
        ?string $namespacePrefix = null,
    ): self {
        return $this->with(model: [
            'package' => $package,
            'path' => rtrim($path, '/') . '/',
            'prefix' => $tablePrefix,
            'namespacePrefix' => $namespacePrefix,
        ]);
    }

    /**
     * @param class-string ...$classes
     */
    public function tables(string ...$classes): self
    {
        return $this->with(tables: array_values(array_unique([...$this->tables, ...$classes])));
    }

    /**
     * @param array<string, string|int|bool> $settings
     */
    public function settings(array $settings): self
    {
        $normalized = [];

        foreach ($settings as $key => $value) {
            $normalized[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $this->with(settings: [...$this->settings, ...$normalized]);
    }

    public function service(string $key, callable $factory): self
    {
        return $this->with(services: [...$this->services, $key => $factory]);
    }

    public function namespaceName(): string
    {
        return $this->namespace;
    }

    public function getCorePath(): ?string
    {
        return $this->corePath;
    }

    public function getAssetsPath(): ?string
    {
        return $this->assetsPath;
    }

    /**
     * @return array{package: string, path: string, prefix: ?string, namespacePrefix: ?string}|null
     */
    public function getModel(): ?array
    {
        return $this->model;
    }

    /**
     * @return array<int, class-string>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * @return array<string, string>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @return array<string, callable>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * @param array{package: string, path: string, prefix: ?string, namespacePrefix: ?string}|null $model
     * @param array<int, class-string>|null                                                        $tables
     * @param array<string, string>|null                                                           $settings
     * @param array<string, callable>|null                                                         $services
     */
    private function with(
        ?string $corePath = null,
        ?string $assetsPath = null,
        ?array $model = null,
        ?array $tables = null,
        ?array $settings = null,
        ?array $services = null,
    ): self {
        return new self(
            $this->namespace,
            $corePath ?? $this->corePath,
            $assetsPath ?? $this->assetsPath,
            $model ?? $this->model,
            $tables ?? $this->tables,
            $settings ?? $this->settings,
            $services ?? $this->services,
        );
    }
}

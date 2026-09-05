<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Installer;

use ModxKit\Testbench\Environment\AdminConfig;
use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\TestbenchConfig;

/**
 * @internal
 */
final readonly class InstallConfig
{
    public function __construct(
        public string $rootPath,
        public string $corePath,
        public string $managerPath,
        public string $connectorsPath,
        public DatabaseConfig $database,
        public AdminConfig $admin,
        public bool $unpacked,
        public string $language = 'en',
        public string $httpHost = 'localhost',
        public int $httpsPort = 443,
    ) {
    }

    public static function forCore(CoreLocation $core, TestbenchConfig $config, bool $unpacked): self
    {
        return new self(
            rootPath: $core->rootPath,
            corePath: $core->corePath(),
            managerPath: $core->managerPath(),
            connectorsPath: $core->connectorsPath(),
            database: $config->database,
            admin: $config->admin,
            unpacked: $unpacked,
        );
    }
}

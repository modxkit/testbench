<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Installer;

use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Support\FilePermissions;
use SimpleXMLElement;

/**
 * @internal
 */
final class ConfigXmlWriter
{
    public function render(InstallConfig $config): string
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><modx/>');

        foreach ($this->values($config) as $key => $value) {
            // `ENT_SUBSTITUTE` is mandatory: without it `htmlspecialchars()` returns an EMPTY
            // string on invalid UTF-8 (the default behaviour), and a broken byte in the password or
            // the database name silently turned the value into an empty one — the install failed
            // later, on "Could not connect to the database", without a hint of the real cause.
            $xml->addChild(
                $key,
                htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        $rendered = $xml->asXML();

        if ($rendered === false) {
            throw new TestbenchException('Failed to render the setup/config.xml manifest.');
        }

        return $rendered;
    }

    public function write(InstallConfig $config, string $targetFile): string
    {
        $directory = dirname($targetFile);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new TestbenchException(
                "Failed to write the installation manifest to {$targetFile}: directory {$directory} is not writable."
            );
        }

        if (file_put_contents($targetFile, $this->render($config)) === false) {
            throw new TestbenchException("Failed to write the installation manifest to {$targetFile}.");
        }

        // The manifest carries the database and admin passwords in clear text, and it lies in a
        // shared temporary directory (`sys_get_temp_dir()` by default), where anyone can read. The
        // `chmod` comes after the write rather than a `umask` before it: the umask is process state,
        // and changing it for the duration of the write would affect the whole rest of the
        // package.
        //
        // A failure here warns but does not undo the write: see {@see FilePermissions}.
        FilePermissions::restrict(
            $targetFile,
            0o600,
            'the installation manifest holds the database and admin passwords in clear text.'
        );

        return $targetFile;
    }

    /**
     * @return array<string, string>
     */
    private function values(InstallConfig $config): array
    {
        $database = $config->database;

        return [
            'database_type' => 'mysql',
            'database_server' => $database->port === 3306
                ? $database->host
                : $database->host . ':' . $database->port,
            'database' => $database->name,
            'database_user' => $database->user,
            'database_password' => $database->password,
            'database_connection_charset' => $database->charset,
            'database_charset' => $database->charset,
            'database_collation' => $database->collation,
            'table_prefix' => $database->prefix,
            'https_port' => (string) $config->httpsPort,
            'http_host' => $config->httpHost,
            'inplace' => '1',
            'unpacked' => $config->unpacked ? '1' : '0',
            'language' => $config->language,
            'cmsadmin' => $config->admin->user,
            'cmspassword' => $config->admin->password,
            'cmsadminemail' => $config->admin->email,
            'core_path' => $config->corePath,
            'context_web_path' => $config->rootPath,
            'context_web_url' => '/',
            'context_mgr_path' => $config->managerPath,
            'context_mgr_url' => '/manager/',
            'context_connectors_path' => $config->connectorsPath,
            'context_connectors_url' => '/connectors/',
            'remove_setup_directory' => '0',
        ];
    }
}

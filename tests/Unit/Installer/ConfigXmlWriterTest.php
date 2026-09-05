<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Installer;

use ModxKit\Testbench\Environment\CoreLocation;
use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Environment\TestbenchConfig;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Installer\ConfigXmlWriter;
use ModxKit\Testbench\Installer\InstallConfig;
use ModxKit\Testbench\Tests\Support\CapturesWarnings;
use ModxKit\Testbench\Tests\Support\OwnsTestbenchEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `render()` calls {@see TestbenchConfig::fromEnvironment()}, that is, reads the real variables of
 * the process. Without an environment of its own (see {@see OwnsTestbenchEnvironment}) the suite
 * would say nothing about that and would depend on what the consumer's environment holds (their own
 * test database, a DBMS password) — exactly the defect already fixed in
 * {@see \ModxKit\Testbench\Tests\Unit\Environment\TestbenchConfigTest}. The substitution scheme is
 * the same one: both schemes were merged into a shared trait.
 */
#[Group('unit')]
final class ConfigXmlWriterTest extends TestCase
{
    use CapturesWarnings;
    use OwnsTestbenchEnvironment;

    private function render(): string
    {
        $core = new CoreLocation('/tmp/env/', '3.2.3-pl');
        $install = InstallConfig::forCore($core, TestbenchConfig::fromEnvironment(), true);

        return (new ConfigXmlWriter())->render($install);
    }

    /**
     * The regular configuration, with only what the caller passed replaced in the database part —
     * the other fields (provider, version, administrator…) stay at the defaults of
     * {@see TestbenchConfig::fromEnvironment()} with the environment cleared (see
     * {@see self::setUp()}).
     */
    private function renderWithDatabase(DatabaseConfig $database): string
    {
        $config = TestbenchConfig::fromEnvironment();
        $core = new CoreLocation('/tmp/env/', '3.2.3-pl');
        $install = InstallConfig::forCore(
            $core,
            new TestbenchConfig(
                provider: $config->provider,
                version: $config->version,
                gitRef: $config->gitRef,
                localCorePath: $config->localCorePath,
                database: $database,
                admin: $config->admin,
                cacheDir: $config->cacheDir,
                workspaceDir: $config->workspaceDir,
                forceInstall: $config->forceInstall,
            ),
            true
        );

        return (new ConfigXmlWriter())->render($install);
    }

    public function testRootElementIsModx(): void
    {
        $xml = simplexml_load_string($this->render());

        self::assertNotFalse($xml);
        self::assertSame('modx', $xml->getName());
    }

    public function testContextPathsAreExplicit(): void
    {
        $xml = simplexml_load_string($this->render());
        self::assertNotFalse($xml);

        self::assertSame('/tmp/env/', (string) $xml->context_web_path);
        self::assertSame('/', (string) $xml->context_web_url);
        self::assertSame('/tmp/env/manager/', (string) $xml->context_mgr_path);
        self::assertSame('/manager/', (string) $xml->context_mgr_url);
        self::assertSame('/tmp/env/connectors/', (string) $xml->context_connectors_path);
        self::assertSame('/connectors/', (string) $xml->context_connectors_url);
        self::assertSame('/tmp/env/core/', (string) $xml->core_path);
    }

    public function testInstallFlagsAreSetForTestbench(): void
    {
        $xml = simplexml_load_string($this->render());
        self::assertNotFalse($xml);

        self::assertSame('1', (string) $xml->inplace);
        self::assertSame('1', (string) $xml->unpacked);
        self::assertSame('0', (string) $xml->remove_setup_directory);
        self::assertSame('utf8mb4', (string) $xml->database_connection_charset);
    }

    /**
     * `ConfigXmlWriter::values()` writes `database_server` as `host:port` for a port other than 3306
     * (`src/Installer/ConfigXmlWriter.php:75-77`) — exactly the branch that
     * `MODX_TESTBENCH_DB_PORT=3307` from `ci/docker-compose.yml` leads into, and before this test no
     * test of the suite covered it.
     */
    public function testDatabaseServerIncludesPortWhenItIsNotTheMysqlDefault(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $database = new DatabaseConfig(
            host: $config->database->host,
            port: 3307,
            name: $config->database->name,
            user: $config->database->user,
            password: $config->database->password,
            prefix: $config->database->prefix,
            charset: $config->database->charset,
            collation: $config->database->collation,
        );

        $xml = simplexml_load_string($this->renderWithDatabase($database));
        self::assertNotFalse($xml);

        self::assertSame($database->host . ':3307', (string) $xml->database_server);
    }

    /**
     * The second branch: the default port (3306) is written as a bare host, without `:3306`. The
     * mutation "always append the port" is caught by this test, the mutation "never append the
     * port" by the previous one; on its own neither of the two covers the pair.
     */
    public function testDatabaseServerIsBareHostWhenPortIsTheMysqlDefault(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $database = new DatabaseConfig(
            host: $config->database->host,
            port: 3306,
            name: $config->database->name,
            user: $config->database->user,
            password: $config->database->password,
            prefix: $config->database->prefix,
            charset: $config->database->charset,
            collation: $config->database->collation,
        );

        $xml = simplexml_load_string($this->renderWithDatabase($database));
        self::assertNotFalse($xml);

        self::assertSame($database->host, (string) $xml->database_server);
    }

    public function testWritePersistsFileAndReturnsPath(): void
    {
        $core = new CoreLocation('/tmp/env/', '3.2.3-pl');
        $install = InstallConfig::forCore($core, TestbenchConfig::fromEnvironment(), false);
        $target = sys_get_temp_dir() . '/testbench-config-' . bin2hex(random_bytes(4)) . '.xml';

        $path = (new ConfigXmlWriter())->write($install, $target);

        self::assertSame($target, $path);
        self::assertFileExists($target);
        self::assertStringContainsString('<modx>', (string) file_get_contents($target));

        unlink($target);
    }

    /**
     * `htmlspecialchars()` without `ENT_SUBSTITUTE` returns an EMPTY string on invalid UTF-8. A
     * broken byte in the database password (copied out of an email, typed in another encoding)
     * silently made the value empty, and the install then failed later, on "Could not connect to
     * the database", without a hint of the real cause.
     */
    public function testBrokenUtf8InAPasswordDoesNotSilentlyBecomeAnEmptyValue(): void
    {
        $core = new CoreLocation('/tmp/env/', '3.2.3-pl');
        $config = TestbenchConfig::fromEnvironment();
        $broken = new TestbenchConfig(
            provider: $config->provider,
            version: $config->version,
            gitRef: $config->gitRef,
            localCorePath: $config->localCorePath,
            database: new DatabaseConfig(
                host: $config->database->host,
                port: $config->database->port,
                name: $config->database->name,
                user: $config->database->user,
                // 0xB1 is a valid byte in latin-1 and an invalid sequence in UTF-8.
                password: "pass\xB1word",
                prefix: $config->database->prefix,
                charset: $config->database->charset,
                collation: $config->database->collation,
            ),
            admin: $config->admin,
            cacheDir: $config->cacheDir,
            workspaceDir: $config->workspaceDir,
            forceInstall: $config->forceInstall,
        );

        $xml = simplexml_load_string(
            (new ConfigXmlWriter())->render(InstallConfig::forCore($core, $broken, true))
        );

        self::assertNotFalse($xml);
        self::assertNotSame('', (string) $xml->database_password);
        self::assertStringStartsWith('pass', (string) $xml->database_password);
        self::assertStringEndsWith('word', (string) $xml->database_password);
    }

    /**
     * `assertStringContainsString('&amp;', …)` is green even when the escaping breaks in the
     * OPPOSITE direction — for example, if `<` in a value were not escaped at all, the document
     * would stop parsing altogether, while a raw `&amp;` for a password containing `&` would still
     * be in the output if only one of the four special characters is broken. What is checked is what
     * matters to the consumer: the document stays XML, and the value read back out of it matches
     * WHAT WAS SET, including quotes and angle brackets inside the value itself.
     */
    public function testXmlMetacharactersInPasswordSurviveRoundTrip(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $password = 'p&ss<"\'w';
        $database = new DatabaseConfig(
            host: $config->database->host,
            port: $config->database->port,
            name: $config->database->name,
            user: $config->database->user,
            password: $password,
            prefix: $config->database->prefix,
            charset: $config->database->charset,
            collation: $config->database->collation,
        );

        $rendered = $this->renderWithDatabase($database);
        $xml = simplexml_load_string($rendered);

        self::assertNotFalse($xml, "The document must stay parseable XML:\n" . $rendered);
        self::assertSame($password, (string) $xml->database_password);
    }

    /**
     * The second half: non-ASCII (Cyrillic, diacritics, CJK) is not the same thing as the invalid
     * UTF-8 of {@see self::testBrokenUtf8InAPasswordDoesNotSilentlyBecomeAnEmptyValue()}: there the
     * byte sequence is INVALID, here it is valid, and what this test checks is that such a value
     * comes back out of the manifest as the very characters that went in.
     *
     * That single axis is the whole of it, and the boundary is measured rather than assumed. Six
     * mutations of the encoding call in `ConfigXmlWriter` leave THIS test green: dropping the
     * explicit `'UTF-8'`, replacing it with `ISO-8859-1` or with `Windows-1251`, dropping
     * `ENT_SUBSTITUTE`, dropping `ENT_XML1`, and swapping `ENT_XML1` for `ENT_HTML5`. Three of
     * them (the two charset names and `ENT_SUBSTITUTE`) are caught by the neighbour named above;
     * the other three are caught by nobody in this class, and for two of them — the dropped
     * `'UTF-8'`, which hands the behaviour over to the consumer's `default_charset`, and the
     * dropped `ENT_XML1` — whether an equivalent mutant is even possible is NOT MEASURED.
     *
     * What this test, and in this class only this test, does catch is distortion of the VALUE: a
     * mutation that re-encodes the password (`mb_convert_encoding(…, 'ISO-8859-1', 'UTF-8')`) or
     * replaces its non-ASCII bytes with `?` reddens exactly this method and no other.
     *
     * The Cyrillic, the diacritic and the CJK characters in the password below are deliberate and
     * must NOT be replaced with ASCII: they are the input the check consists of. Measured, not
     * feared — with an ASCII password the test stays green under that same re-encoding mutation.
     */
    public function testNonAsciiCharactersInPasswordSurviveRoundTrip(): void
    {
        $config = TestbenchConfig::fromEnvironment();
        $password = 'пароль-Ключ-héllo-测试';
        $database = new DatabaseConfig(
            host: $config->database->host,
            port: $config->database->port,
            name: $config->database->name,
            user: $config->database->user,
            password: $password,
            prefix: $config->database->prefix,
            charset: $config->database->charset,
            collation: $config->database->collation,
        );

        $rendered = $this->renderWithDatabase($database);
        $xml = simplexml_load_string($rendered);

        self::assertNotFalse($xml, "The document must stay parseable XML:\n" . $rendered);
        self::assertSame($password, (string) $xml->database_password);
    }

    public function testWriteRestrictsPermissionsBecauseTheManifestCarriesPasswords(): void
    {
        $core = new CoreLocation('/tmp/env/', '3.2.3-pl');
        $install = InstallConfig::forCore($core, TestbenchConfig::fromEnvironment(), false);
        $target = sys_get_temp_dir() . '/testbench-config-' . bin2hex(random_bytes(4)) . '.xml';

        (new ConfigXmlWriter())->write($install, $target);

        $mode = fileperms($target);

        self::assertNotFalse($mode);
        self::assertSame('0600', substr(sprintf('%o', $mode), -4));

        unlink($target);
    }

    /**
     * Tightening the permissions is a protective measure rather than a criterion of success:
     * `chmod()` may refuse even where the write has just succeeded (by general description, on some
     * network and container mounts; not measured), and an exception here would declare an install
     * failed that in fact goes through perfectly, with advice the consumer cannot act on. The
     * refusal must be a loud warning, and exactly ONE: a raw "chmod(): Operation not permitted" must
     * not get ahead of the package's own message (the model being
     * `Workspace::writeOwnershipMarker()`).
     */
    public function testWriteWarnsInsteadOfFailingWhenPermissionsCannotBeRestricted(): void
    {
        UnchmodableStreamWrapper::install();

        try {
            $core = new CoreLocation('/tmp/env/', '3.2.3-pl');
            $install = InstallConfig::forCore($core, TestbenchConfig::fromEnvironment(), false);
            $target = UnchmodableStreamWrapper::SCHEME . '://volume/config.xml';
            $path = null;

            $warnings = $this->captureWarnings(function () use ($install, $target, &$path): void {
                $path = (new ConfigXmlWriter())->write($install, $target);
            });

            self::assertSame($target, $path, 'The manifest must be written despite the refusal of chmod().');
            self::assertStringContainsString('<modx>', UnchmodableStreamWrapper::$written);
            self::assertCount(1, $warnings, "Exactly one warning was expected:\n" . implode("\n", $warnings));
            self::assertStringContainsString($target, $warnings[0]);
            self::assertStringContainsString('0600', $warnings[0]);
        } finally {
            UnchmodableStreamWrapper::uninstall();
        }
    }

    public function testWriteThrowsWhenTargetPathIsUnwritable(): void
    {
        $core = new CoreLocation('/tmp/env/', '3.2.3-pl');
        $install = InstallConfig::forCore($core, TestbenchConfig::fromEnvironment(), true);
        $target = sys_get_temp_dir()
            . '/testbench-missing-dir-' . bin2hex(random_bytes(4))
            . '/nested/config.xml';

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('is not writable');

        (new ConfigXmlWriter())->write($install, $target);
    }
}

# Non-interactive (headless) installation of MODX Revolution 3

> This reference was verified against the MODX **3.2.3-pl** sources.
> Every statement below is backed by a file and line reference. The document is the source of truth for the package's `Installer\*` components.

## 1. Entry point

The correct command:

```bash
php <root>/setup/index.php \
    --installmode=new \
    --core_path=<root>/core/ \
    --config=<root>/setup/config.xml
```

### Why not `setup/cli-install.php`

`cli-install.php` is an interactive wizard: it calls `readline()` for every parameter that was not given, and additionally asks for confirmation if a `config.xml` already exists (`setup/cli-install.php:119`). In a non-interactive environment that leads to a hang or to reading EOF. It is useful in exactly one respect: at the end it does `require setup/index.php` itself with the `--installmode`, `--core_path` and `--config` flags (`setup/cli-install.php:192-197`) — and that is exactly the command we call directly.

### How the arguments are parsed

CLI mode is enabled by the SAPI:

```php
define('MODX_SETUP_INTERFACE_IS_CLI', (PHP_SAPI === 'cli'));   // setup/provisioner/bootstrap.php:12
```

`$argv` is then parsed into `$_REQUEST` **before** the paths are determined, which is why `--core_path` is mandatory and is handled specially:

```php
foreach ($argv as $idx => $argument) { ... $_REQUEST[$p[0]] = $p[1]; }
if (!empty($_REQUEST['core_path']) && is_dir($_REQUEST['core_path'])) {
    define('MODX_CORE_PATH', $_REQUEST['core_path']);
}
```
`setup/provisioner/bootstrap.php:121-133`

**Consequence:** without `--core_path` the installer looks for the core in the default place and misses it in a non-standard layout. We pass the path as an absolute one, with a trailing slash.

### Precedence of the settings sources

`modInstallCLIRequest::handle()` applies the values in this order (`setup/includes/request/modinstallclirequest.class.php:77-91`):

1. the values from `$_REQUEST` (that is, from the CLI arguments);
2. the values from `config.xml`;
3. the values from `$_REQUEST` **again** — "do again to allow CLI-based overrides of config.xml".

So a CLI argument always beats the file. We do not abuse that: everything except the three mandatory flags is set in `config.xml`.

The path to the file is taken from the `config` setting; if it is not set, `setup/config.xml` is substituted (`modinstallclirequest.class.php:182-196`).

## 2. The `config.xml` manifest

The root element is **`<modx>`**, not `<setup>` (`setup/config.dist.new.xml`). An example manifest for a test environment:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<modx>
    <database_type>mysql</database_type>
    <database_server>127.0.0.1</database_server>
    <database>modx_testbench</database>
    <database_user>root</database_user>
    <database_password>secret</database_password>
    <database_connection_charset>utf8mb4</database_connection_charset>
    <database_charset>utf8mb4</database_charset>
    <database_collation>utf8mb4_general_ci</database_collation>
    <table_prefix>modx_</table_prefix>

    <https_port>443</https_port>
    <http_host>localhost</http_host>

    <inplace>1</inplace>
    <unpacked>1</unpacked>
    <language>en</language>

    <cmsadmin>testbench</cmsadmin>
    <cmspassword>TestbenchPass123!</cmspassword>
    <cmsadminemail>testbench@example.com</cmsadminemail>

    <core_path>/tmp/modx-testbench/abc123/core/</core_path>
    <context_web_path>/tmp/modx-testbench/abc123/</context_web_path>
    <context_web_url>/</context_web_url>
    <context_mgr_path>/tmp/modx-testbench/abc123/manager/</context_mgr_path>
    <context_mgr_url>/manager/</context_mgr_url>
    <context_connectors_path>/tmp/modx-testbench/abc123/connectors/</context_connectors_path>
    <context_connectors_url>/connectors/</context_connectors_url>

    <remove_setup_directory>0</remove_setup_directory>
</modx>
```

### The parameters explained

| Parameter                                          | Purpose                                       | Value for testbench             | Why                                                                                                                    |
| -------------------------------------------------- | --------------------------------------------- | ------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `database_type`                                    | DBMS driver                                   | `mysql`                         | The only one the installer supports in practice                                                                        |
| `database_server`                                  | DBMS host                                     | `127.0.0.1` or a service name   | In CI — the name of the service container                                                                              |
| `database`                                         | Database name                                 | from the environment            | The key is `database`; the installer maps it to its internal `dbase` itself (`modinstallclirequest.class.php:212-218`) |
| `database_user` / `database_password`              | Account                                       | from the environment            | The `CREATE` privilege is required, see §3                                                                             |
| `database_connection_charset` / `database_charset` | Charset                                       | `utf8mb4`                       | The distribution's `utf8` is obsolete                                                                                  |
| `database_collation`                               | Collation                                     | `utf8mb4_general_ci`            | Must match the charset                                                                                                 |
| `table_prefix`                                     | Table prefix                                  | `modx_`                         | Different prefixes allow several environments in one database                                                          |
| `inplace`                                          | The files already sit in the target directory | `1`                             | We deploy the distribution ourselves                                                                                   |
| `unpacked`                                         | `core.transport.zip` is already unpacked      | `1`                             | Saves tens of seconds, see §4                                                                                          |
| `language`                                         | Manager language                              | `en`                            | Deterministic messages                                                                                                 |
| `cmsadmin` / `cmspassword` / `cmsadminemail`       | Administrator                                 | from the environment            | Needed for tests of permissions and processors                                                                         |
| `core_path`                                        | Path to the core                              | absolute, with a trailing slash | Duplicates `--core_path`                                                                                               |
| `context_*_path` / `context_*_url`                 | Context paths and URLs                        | **set explicitly**              | Otherwise auto-detection breaks, see §5                                                                                |
| `remove_setup_directory`                           | Delete `setup/` after the installation        | `0`                             | The directory is needed to reinstall the environment                                                                   |
| `https_port` / `http_host`                         | Host parameters                               | `443` / `localhost`             | They affect the generated system settings                                                                              |

The full list of the keys that are understood is visible in the `$variables` list (`setup/cli-install.php:26-91`) and in the sample shipped with the distribution, `setup/config.dist.new.xml`.

## 3. Creating the database

There is no need to create the database separately. Under `installmode=new` the installer tries to connect to the target database and, if it is absent, creates it itself:

```php
$dbExists = $xpdo->connect();
if (!$dbExists) {
    if ($mode == modInstall::MODE_NEW && $xpdo->getManager()) {
        $dbExists = $xpdo->manager->createSourceContainer([...]);
```
`setup/includes/request/modinstallclirequest.class.php:285-292`

**What the environment must provide:** a reachable MySQL/MariaDB server and an account with the `CREATE` privilege. Nothing more.

That is a statement about the MODX installer, not a recommendation for the package: testbench creates the database itself before running the installer, and FR-INSTALL-5 (`docs/SPEC.md`) says why. The reason is visible two dozen lines above the snippet quoted here — `checkDatabase()` calls `verifyServerVersion()` (`:270`) BEFORE the `connect()`/`createSourceContainer()` pair (`:285-292`), so with the database still absent the version reads empty and the installer writes `'override_table' => 'MyISAM'`. The measured comparison is in `docs/DX_GUIDE.md`, section 10, "The tests are 'green', but the database is dirty after the run".

## 4. Speeding it up: unpacking the core in advance

Under `unpacked=0` the installer unpacks `core/packages/core.transport.zip` itself — that is the longest part of the installation. If the archive is unpacked in advance (with PHP's `ZipArchive`, into the `core/packages/` directory) and `unpacked=1` is set, the step is skipped. The comment in the sample shipped with the distribution confirms what the flag is for (`setup/config.dist.new.xml`).

It makes sense to perform that optimisation once per cached release rather than on every run.

## 5. The pitfall: path auto-detection in the CLI

`modInstallRequest::setDefaultPaths()` computes the base URL from `$_SERVER['SCRIPT_NAME']`:

```php
$webUrl = substr($_SERVER['SCRIPT_NAME'], 0, strpos($_SERVER['SCRIPT_NAME'], 'setup/'));
$webUrl = rtrim($webUrl, '/') . '/';
```
`setup/includes/request/modinstallrequest.class.php:128-129`

In the CLI, `SCRIPT_NAME` is the filesystem path to the script, so the site URL comes out as something like `/tmp/modx-testbench/abc123/`. Values from `config.xml` take precedence over these defaults (`modinstallrequest.class.php:139-144`), so **we set all six `context_*_path`/`context_*_url` explicitly** — then the MODX system settings turn out meaningful.

## 6. Checking that it succeeded

The signs of a successful installation that `HeadlessInstaller` checks (all three are mandatory):

1. the process exit code is `0`;
2. `<core>/config/config.inc.php` exists and is readable;
3. the output contains no installer error markers.

Before installing, the installer runs its own environment tests (`modInstallTest`) and on failure exits with a list of the things that do not match (`modinstallclirequest.class.php:96-107`) — that output must end up in the exception text, because it is what explains 90% of the refusals (a missing PHP extension, no write permission, an unreachable DBMS).

## 7. Loading the installed core in tests

After the installation the core is loaded in API mode:

```php
define('MODX_API_MODE', true);
require '<root>/index.php';   // hands back an initialised $modx
```

What happens then (in the `index.php` of an installed distribution):

- `MODX_API_MODE` is already defined, so the `define('MODX_API_MODE', false)` branch is skipped;
- `config.core.php` is included, defining `MODX_CORE_PATH` and `MODX_CONFIG_KEY`;
- `core/vendor/autoload.php` is included;
- `$modx = MODX\Revolution\modX::getInstance()` is created;
- `$modx->initialize('web')` is called;
- `$modx->handleRequest()` is **not** called — precisely because `MODX_API_MODE` is true.

In addition, after loading you need to:

1. make sure the `error` and `lexicon` services are in the container — the core adds them lazily, only before running processors (`core/src/Revolution/modX.php:1774-1783`);
2. redirect logging to a file and lower the level — otherwise core warnings reach stdout and break PHPUnit with strict output checking;
3. account for `index.php` calling `ob_start()` itself — we wrap the include in a buffer of our own and close it properly.

Worth knowing: `modX::getInstance($id, $config, $forceNew)` is able to create several instances and to accept configuration overrides (`core/src/Revolution/modX.php:452-464`) — but `MODX_CORE_PATH` remains a process constant, so exactly one core version lives in a single PHPUnit process.

## 8. Registering the extra under test at runtime

Without building a transport package:

| Step                                    | API                                                                    | Source                                          |
| --------------------------------------- | ---------------------------------------------------------------------- | ----------------------------------------------- |
| Namespace                               | create a `MODX\Revolution\modNamespace` with the core and assets paths | —                                               |
| xPDO model                              | `$modx->addPackage($pkg, $path, $prefix, $namespacePrefix)`            | `core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:465`   |
| Tables                                  | `$modx->getManager()->createObjectContainer($class)`                   | `xPDO.php:1883`, `Om/mysql/xPDOManager.php:111` |
| Autoloading the model at initialisation | `$modx->addExtensionPackage($name, $path, $options)`                   | `core/src/Revolution/modX.php:2332`             |
| System settings                         | create a `modSystemSetting` and clear the settings cache               | —                                               |
| Services                                | `$modx->services->add($key, $factory)`                                 | `modX.php:75`                                   |

With a transport build: assemble the zip with the extra's `_build/build.transport.php` script and install it with the `MODX\Revolution\Processors\Workspace\Packages\Install` processor.

## 9. Transactions and isolation

Transactions are available directly on the `$modx` instance (xPDO inherits the PDO API):

```php
$modx->beginTransaction();   // core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2474
$modx->commit();             // :2484
$modx->rollBack();           // :2594
```

A separate `getDriver()` call is not needed: `getDriver()` (`xPDO.php:1902`) returns the SQL-generation object, not the connection.

MySQL limitations that cannot be worked around at the library level:

- DDL (`CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`) causes an implicit commit — the transaction is lost silently;
- MyISAM tables do not support transactions at all.

That is why tests that create the extra's tables or install a transport package are obliged to use the snapshot-restore strategy rather than a transaction rollback.

## 10. Headless installation checklist

1. Obtain the distribution (a zip release, a git clone or a copy of a local directory).
2. Unpack `core/packages/core.transport.zip` → `unpacked=1`.
3. Generate `setup/config.xml` with a `<modx>` root and explicit context paths.
4. Run `php setup/index.php --installmode=new --core_path=… --config=…`, capturing the output.
5. Check the exit code, the presence of `core/config/config.inc.php` and the output for error markers.
6. Delete `setup/config.xml` (the manifest carries passwords in clear text, and the core reads it exactly once — `modinstallclirequest.class.php:86`) and narrow the permissions of `core/config/config.inc.php` to 0600: the same database password is duplicated there, and the core needs that file permanently. On a FAILURE the manifest is kept — it is the primary diagnostic artefact. Being unable to narrow the permissions is a reason to warn, not to declare the installation failed: the case of interest is "the write went through but `chmod()` refused", which, by general accounts, some network and container mounts produce (NOT MEASURED). The measured `chmod()` refusals — a file with the `chflags uchg` flag and a read-only mounted volume — do not fall into it, since writing fails on those too; and a FAT32 image on macOS, contrary to the common explanation "a volume without POSIX permissions", does not reject `chmod()` at all.
7. Take a baseline dump of the database.
8. Load the core with `MODX_API_MODE = true`, load the services, quieten the logging.

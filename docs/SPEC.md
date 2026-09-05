# Specification: `modxkit/testbench`

> Status: approved · Version: 1.0 · Date: 2026-08-20
> Target platform: MODX Revolution 3.1+ · PHP 8.2–8.4 · PHPUnit 10–12

## 1. Purpose

The package is a toolkit for the automated testing of MODX Revolution 3 extras. An extra developer adds the package to `require-dev` and gets two things the ecosystem does not have today:

1. **A fully automatic disposable MODX 3 environment** — downloading the core, a non-interactive installation, booting in API mode, state isolation between tests. Not a single manual step, not a single visit to the browser installer.
2. **Base classes and stubs** that turn "MODX is installed" into "there is a convenient test contract": registering the extra, helpers for creating entities, assertions on processors and events.

The package is inspired by `orchestra/testbench` (Laravel), but solves the problem differently: MODX 3 cannot be virtualised in memory (see [ADR-0002](adr/0002-no-in-memory-sqlite.md)), so instead of emulating the core we automate deploying it for real.

### What the package does NOT do

- It does not replace browser/acceptance testing (Codeception, Playwright) — it gives you a core and a database to run those on top of.
- It does not support MODX 2.x.
- It does not support the MODX 3.0.x line. The 3.0.5-pl core does not come up fully in API mode: `modX::reloadConfig()`, in two adjacent lines, drives the process into `include`ing one and the same model file twice (`Cannot redeclare class`), and before the fatal error `getOption('core_path')` returns `null`, so processors fail with `checkPolicy() on null`. The cause is entirely inside the 3.0.5 core — the package registers no second autoloader for `MODX\` — and cannot be worked around with a spot fix. The declared floor is 3.1.2-pl.
- It does not attempt to work without a DBMS at the integration level.

## 2. Scope and the two levels

|                          | Level 1 — Unit                                                                                | Level 2 — Integration                                                        |
| ------------------------ | --------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| Base class               | `ModxKit\Testbench\Unit\UnitTestCase`                                                         | `ModxKit\Testbench\TestCase`                                                 |
| Database needed          | no                                                                                            | yes (MySQL/MariaDB)                                                          |
| MODX installation needed | no (only the core files are needed)                                                           | yes (performed automatically)                                                |
| Speed                    | milliseconds                                                                                  | seconds to prepare, milliseconds per test                                    |
| What it exercises        | business logic, contracts, helpers and the code around the model — detached from the database | xPDO models, schema migrations, processors, plugins, events, system settings |

The levels are independent: level 1 loads nothing from level 2 and must work with the database switched off. That is an invariant, checked in CI by a separate job.

Processors are exclusively the territory of level 2. The level 1 stub does not execute them and cannot: `modX::runProcessor()` looks for the processor file relative to `config['processors_path']` and runs it against the real core. An attempt to call a processor at level 1 is refused with `UnsupportedStubOperationException` rather than returning a plausible `success => false` — otherwise an assertion about a processor refusing would turn green without ever having executed it.

## 3. Terminology

| Term                   | Meaning                                                                                                          |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------- |
| **Core**               | The MODX Revolution 3 distribution: the `core/`, `manager/`, `connectors/`, `setup/` directories and `index.php` |
| **Workspace**          | The temporary directory into which the core is deployed and installed for tests                                  |
| **Core provider**      | The strategy for obtaining the distribution: a release zip, a git clone, a local path                            |
| **Baseline snapshot**  | A database dump taken right after the core was installed successfully                                            |
| **Extra**              | The MODX add-on under test — the reason testbench is there at all                                                |
| **Package definition** | `PackageDefinition` — a declarative description of the extra for registration in the test core                   |

## 4. Functional requirements

The key words MUST / SHOULD / MAY are to be interpreted as described in RFC 2119.

### 4.1 Obtaining the core (FR-CORE)

- **FR-CORE-1.** The package MUST be able to obtain the core in three ways: by downloading a release zip, by `git clone` of the `modx/revolution` repository, and by using a local directory containing a ready distribution.
- **FR-CORE-2.** Downloaded releases MUST be cached in `~/.cache/modx-testbench/releases/` (or `$XDG_CACHE_HOME`) and reused across runs and projects.
- **FR-CORE-3.** The integrity of a downloaded archive MUST be verified (size + the ability to open the zip); a corrupt cache MUST be invalidated automatically and re-downloaded once.
- **FR-CORE-4.** A local directory named by the user MUST NOT be modified — it is copied into the workspace.
- **FR-CORE-5.** Before installing, the package MUST unpack `core/packages/core.transport.zip` and set `unpacked=1` — this shortens the installation time considerably.

### 4.2 Non-interactive installation (FR-INSTALL)

- **FR-INSTALL-1.** The installation MUST be performed by the command `php setup/index.php --installmode=new --core_path=<abs> --config=<abs>` without any interactive input. Using `setup/cli-install.php` is FORBIDDEN — it is interactive.
- **FR-INSTALL-2.** The `config.xml` manifest MUST be generated from environment parameters and contain a `<modx>` root element.
- **FR-INSTALL-3.** All `context_*_path` and `context_*_url` parameters MUST be set explicitly (path auto-detection in the CLI yields unusable values).
- **FR-INSTALL-4.** `remove_setup_directory` MUST be `0` — the `setup/` directory is needed to reinstall the environment.
- **FR-INSTALL-5.** The package MUST create the test database itself (`CREATE DATABASE IF NOT EXISTS` with the configured charset and collation) BEFORE running the installer. Relying on the MODX installer is not an option: having failed to find the database, the CLI setup does not refuse but silently turns on `OPT_OVERRIDE_TABLE_TYPE = 'MyISAM'` and creates every table with an engine that does not support rollback — transaction isolation then stops working entirely, without giving any sign of it. After the installation the package MUST check that none of the prefixed tables is non-transactional, and refuse with a diagnosable exception if any is. What is required of the environment is a reachable server and an account with the `CREATE` privilege (on the database — during installation; on tables — at runtime: the package creates a bookkeeping isolation table, see FR-ISO-7).
- **FR-INSTALL-6.** On an unsuccessful installation an exception MUST be thrown carrying the installer's full stdout/stderr and the path to the `config.xml` that was used.
- **FR-INSTALL-7.** The installation MUST NOT be performed again if a valid environment with the same parameters already exists.

### 4.3 Reusing the environment (FR-ENV)

- **FR-ENV-1.** The workspace MUST be addressed by a deterministic hash of ALL the inputs that determine the result of the installation, and of those only: the fingerprint of the chosen core provider (`CoreProvider::fingerprint()` — the release version for `zip`, the branch for `git`, the path to the distribution for `local`), the database connection parameters together with the credentials and the table prefix, the charset and collation, and the administrator credentials. Everything that goes into `setup/config.xml` determines the installation and therefore must be part of the hash. Inputs that do not determine the installation (the release cache directory, the environment directory, the force-reinstall flag, the parameters of the NON-chosen provider) MUST NOT be part of the hash — otherwise the environment is reinstalled for nothing. The hash MUST remain usable as a component of a MySQL database name: twelve characters from `[0-9a-f]`.
- **FR-ENV-2.** The workspace state MUST be stored in `testbench.lock.json`: the core version, the provider, the prefix, the configuration fingerprint, the installation time, whether a baseline snapshot exists, the format of the baseline snapshot, the number of prefixed tables, and the revision of the installation behaviour.
- **FR-ENV-3.** The variable `MODX_TESTBENCH_FORCE_INSTALL=1` MUST forcibly destroy and recreate the environment.
- **FR-ENV-4.** A configuration fingerprint that does not match the lock file MUST lead to an automatic reinstallation, not to running tests against the wrong environment.
- **FR-ENV-5.** A reinstallation deletes the environment directory recursively. The deletion MUST be performed only on a directory that belongs to the package: an empty one, one marked with a `.testbench-workspace` file (the marker is written when the directory is created), or one containing `testbench.lock.json`. On any other non-empty directory — including one named in `MODX_TESTBENCH_WORKSPACE` by mistake — the package MUST refuse with a diagnosable exception and delete nothing.
- **FR-ENV-6.** The installation MUST write the number of tables carrying the configured prefix into the lock file. Preparing the environment MUST compare that number with the actual one: on a mismatch the database is restored from the baseline, and if that does not help either, the environment is reinstalled. The presence of the core files and of the lock file is not evidence of a working environment.
- **FR-ENV-7.** The package MUST write the revision of the installation behaviour into the lock file. An environment deployed by a DIFFERENT revision (not merely an earlier one — rolling the package back to a previous version is just as dangerous) MUST NOT be considered installed: there is nothing to migrate it in place with (the table count and the snapshot-completeness marker appear only together with the revision that introduced them), and re-taking the baseline on an unverified database cements any damage. Such an environment is reinstalled automatically under FR-ENV-4.
- **FR-ENV-8.** The default environment directory MUST live in the user's private directory (`$XDG_CACHE_HOME`, otherwise `$HOME/.cache`), in a `workspaces/` subdirectory of its own, and the whole segment of the path created by the package MUST be created with `0700` permissions. The directory name is the fingerprint from FR-ENV-1, and by that same requirement the database password is hashed into it: read by an outsider, it is good enough for an offline password search. A separate `workspaces/` subdirectory is mandatory because the `modx-testbench` directory is shared with the release cache and may already exist with wider permissions. Falling back to the system temporary directory (no `HOME`, no `XDG_CACHE_HOME`) is allowed and does not by itself create exposure — the path segment is created with `0700` there too. If the NAME of the environment directory can nevertheless be read by an outsider — the parent directory is readable by them and every directory above is traversable — the package MUST say so rather than stay silent; it MUST NOT fix the permissions of someone else's directory. `MODX_TESTBENCH_WORKSPACE` overrides the default entirely and does not fall under this requirement: the name of such a directory is the consumer's choice. Tightening permissions is a protective measure, not a success criterion: a failed `chmod` does not fail the installation.

### 4.4 Booting the core in tests (FR-BOOT)

- **FR-BOOT-1.** The core MUST be loaded in `MODX_API_MODE = true` — without sessions, routing or rendering.
- **FR-BOOT-2.** Initialisation MUST guarantee that the `error` and `lexicon` services are present in `$modx->services`.
- **FR-BOOT-3.** Core logging MUST be redirected to a file rather than to stdout: tests must produce no extraneous output.
- **FR-BOOT-4.** The core MUST be loaded once per PHPUnit process.
- **FR-BOOT-5.** The package MUST provide a bootstrap file for `phpunit.xml` that prepares the environment before the first test runs.

### 4.5 State isolation (FR-ISO)

- **FR-ISO-1.** By default every test method MUST run inside a transaction that is rolled back in `tearDown()`.
- **FR-ISO-2.** The loss of a transaction (an implicit commit after DDL) MUST be detected and lead to an explicit error pointing at the `RefreshesDatabase` trait, rather than to a silent loss of isolation.
- **FR-ISO-3.** The package MUST provide a `RefreshesDatabase` trait that restores the baseline snapshot.
- **FR-ISO-4.** The baseline snapshot MUST be taken automatically right after the installation.
- **FR-ISO-5.** Taking and restoring a snapshot MUST work without `mysqldump` (a PHP fallback), since CI containers may not have the utility.
- **FR-ISO-5c.** DBMS clients MUST be looked up as two pairs of names — `mysqldump`/`mysql` and `mariadb-dump`/`mariadb` — in that order; a pair is taken whole. MariaDB 12.3.2 ships no files under the old names at all, and a runner with such clients would otherwise look like a runner with NO clients: the snapshot silently goes to the PHP fallback, taking views and triggers with it. The chosen strategy MUST be named to the consumer by the `install` command rather than remain an internal detail.
- **FR-ISO-5a.** The package MUST write into the lock file WHICH strategy the baseline snapshot was taken with, and choose the restore strategy from that record rather than from the presence of clients in `PATH`. The formats are not interchangeable: a `mysqldump` dump carries the client-side `DELIMITER` command, views and triggers, and the PHP fallback trips over them AFTER the database has already been wiped. If the snapshot was taken with `mysqldump` and there are no clients in `PATH`, the package MUST refuse with a diagnosable exception without touching the database, rather than silently substituting another strategy. A lock file WITHOUT that record (an environment from a previous revision) is reinstalled under FR-ENV-7: there is nothing to determine the origin of the snapshot on disk from.
- **FR-ISO-5b.** The options file through which the `mysqldump` strategy passes credentials to the clients MUST keep in its `[client]` group only options known to both clients of both builds — Oracle MySQL and MariaDB. The `init-command` option is not one of them: `mariadb-dump` 10.6.28/10.11.18/12.3.2 and `mysqldump` 8.0.46 reject it as unknown (`unknown variable 'init-command=…'`, exit code 7) before even connecting to the DBMS, and taking the snapshot fails entirely; only `mysqldump` 8.4.11 accepts it. `init-command` therefore lives in the `[mysql]` group: the `mysql` client reads it (all five measured builds accept the option and execute it) and no `mysqldump` reads it. Hence a boundary the package acknowledges explicitly: the 30-second cap on waiting for a metadata lock applies to WIPING the database and to RESTORING the snapshot (the `mysql` client), while TAKING the snapshot (`mysqldump`) runs with the server default — 31,536,000 seconds, that is, a year. The scenario the cap was introduced for (someone else's unclosed transaction) does not obstruct taking a snapshot: measured — `mysqldump --single-transaction` under such a transaction completed in 0.5 seconds. Competing DDL holding an exclusive metadata lock would delay the snapshot for the server default; that is NOT MEASURED.
- **FR-ISO-6.** The xPDO result cache (`xPDO::OPT_CACHE_DB`) MUST be switched off when the core is loaded: a cached object survives a transaction rollback and breaks isolation.
- **FR-ISO-7.** To detect the loss of a transaction the package MAY create a bookkeeping table `testbench_isolation_guard` in the test database (one marker row per test, InnoDB engine). The table name carries NO core prefix: it does not belong to the MODX installation, is not counted among the tables under FR-ENV-6 and is not removed by the cleanup before a reinstallation. The package MUST refuse with a diagnosable exception if a table with that name is occupied and cannot accept the marker.

### 4.6 Registering the extra under test (FR-PKG)

- **FR-PKG-1.** The extra MUST be described declaratively through `PackageDefinition`: namespace, paths, xPDO model, table classes, system settings, services, plugins/events.
- **FR-PKG-2.** Registration MUST create a `modNamespace`, call `addPackage()` and create the tables through `xPDOManager::createObjectContainer()`.
- **FR-PKG-3.** The package MUST be able to register the extra's services in `$modx->services` before the test method runs.
- **FR-PKG-4.** The package MUST provide an alternative path: building a transport package through `_build/build.transport.php` and installing it with the `Workspace\Packages\Install` processor.
- **FR-PKG-5.** Installing a transport MUST be invoked explicitly and MUST NOT happen in every `setUp()`.

### 4.7 Test API (FR-API)

- **FR-API-1.** `TestCase` MUST provide `$this->modx` — a fully initialised instance of `MODX\Revolution\modX`.
- **FR-API-2.** Entity factories MUST be provided: resource, user, chunk, snippet and system setting. Other object types are created with the regular `newObject()` — dedicated factories for them are added on request, not in advance.
- **FR-API-3.** `actingAs(modUser)`, `runProcessor()` and `triggerEvent()` MUST be provided.
- **FR-API-4.** Assertions MUST be provided: `assertObjectExists()`, `assertObjectMissing()`, `assertProcessorSuccess()`, `assertProcessorFailure()`, `assertSettingEquals()`.
- **FR-API-5.** Every entity created by the helpers MUST disappear after the test by the means of the chosen isolation strategy.

### 4.8 Stub level (FR-STUB)

- **FR-STUB-1.** The `$modx` stub MUST pass an `instanceof MODX\Revolution\modX` check, so that it can be passed into code typed against the core.
- **FR-STUB-2.** Creating the stub MUST NOT open a database connection and MUST NOT require an installed MODX.
- **FR-STUB-3.** The stub MUST support: `getOption`/`setOption` with `xPDO::getOption()` semantics (reading `$modx->config`, `$skipEmpty`, an array of keys), `lexicon`, `log`, `invokeEvent` (the call is recorded; the answer is `false`, as in the core for an event with no active plugins), `getObject`/`getCollection` over an in-memory store (a scalar criterion is the primary key, as in `xPDO::sanitizePKCriteria()`), a real `services` container (`MODX\Revolution\Services\Container`) and the `error` collector (`MODX\Revolution\Error\modError`).
- **FR-STUB-4.** The assertions `assertEventInvoked()`, `assertLogged()` and `assertLexiconUsed()` MUST be provided.
- **FR-STUB-5.** Reaching for core functionality the stub does not have MUST produce a package exception rather than `null`, a plausible answer or a fatal error. The list of such points is finite (the stub inherits the whole of `modX`) and is enumerated in `docs/DX_GUIDE.md`; it MUST cover at least the constructor, `newObject`, `runProcessor`, query building (`newQuery`, `getCount`, `getIterator`, `getObjectGraph`, `getCollectionGraph`, `removeCollection`, `updateCollection`) and reading the class map (`getFields`, `getPK`, `getService`, `getCacheManager`).

### 4.9 CLI (FR-CLI)

- **FR-CLI-1.** The package MUST ship an executable `bin/modx-testbench` with the commands `install`, `status`, `destroy` and `snapshot`.
- **FR-CLI-2.** The commands MUST return correct exit codes for use in CI.
- **FR-CLI-3.** `status` MUST show the environment path, the core version, the provider, the snapshot state, the number of prefixed tables against the one recorded in the lock file, and the connection parameters (with the password hidden).

### 4.10 CI (FR-CI)

- **FR-CI-1.** The package MUST ship a `ci/docker-compose.yml` with MySQL 8 and MariaDB 10.11 for local development. The delivery method is the **distribution**: the file is part of `git archive` and sits at the consumer as `vendor/modxkit/testbench/ci/docker-compose.yml`.
- **FR-CI-2.** The package MUST ship a reusable GitHub Actions workflow that a third-party extra wires in with a single line. The delivery method is a **reference to the repository** (`uses: modxkit/testbench/.github/workflows/testbench.yml@v1`), not `vendor/`: the `.github/` directory is not part of the distribution, and GitHub allows `workflow_call` only from the `.github/workflows/` directory of the called repository.
- **FR-CI-3.** The package's own CI MUST run the matrix PHP 8.2/8.3/8.4 × MODX 3.1/3.2 × MySQL 8/MariaDB 10.11. The 3.0 line is excluded from the matrix together with the declaration that it is unsupported (see "What the package does NOT do").
- **FR-CI-4.** The core release cache MUST be reused between CI runs.

## 5. Non-functional requirements

- **NFR-1. Speed.** A repeat run against an existing environment — no more than 2 seconds of overhead before the first test. The initial installation — a target of up to 60 seconds.
- **NFR-2. Determinism.** Test order does not affect the result; every test sees the same initial state.
- **NFR-3. Diagnosability.** Every environment-preparation failure comes with a concrete cause and a hint about what to do. There must be no silent failures.
- **NFR-4. No side effects.** The package touches nothing outside its own workspace, the release cache and the configured test database.
- **NFR-5. Code quality.** `declare(strict_types=1)` in every file, PSR-12, PHPStan at the maximum level with no baseline, no `echo`/`print` in the library at runtime.
- **NFR-6. Security.** Credentials are read from the environment only; passwords are masked in logs and in CLI output. Hardcoding credentials is forbidden.
- **NFR-7. Self-testing.** The package is covered by its own tests at both levels; a fixture extra proves the whole chain works end to end.

## 6. Architecture

```
Level 1 — Unit (no database)
  ModxKit\Testbench\Unit\UnitTestCase
    └─ Stubs\{TestbenchModx, LexiconStub, ErrorStub, LogRecorder, ObjectStore}
       ← Support\CoreAutoloader (core files only, no installation)

Level 2 — Integration
  ModxKit\Testbench\TestCase
    ├─ Isolation\TransactionIsolation   (the default strategy)
    ├─ Concerns\RefreshesDatabase       (switches the strategy to SnapshotIsolation)
    ├─ Concerns\InteractsWithModx       (factories, assertions, processors, events)
    └─ Package\PackageDefinition → Package\PackageRegistrar
         ↓
  Environment\TestbenchKernel  (one per PHPUnit process)
    ├─ Environment\Workspace                 — directory + testbench.lock.json
    ├─ Environment\Provider\CoreProvider     — ZipRelease | GitClone | LocalPath
    ├─ Installer\HeadlessInstaller           — ConfigXmlWriter + setup/index.php
    ├─ Bootstrap\KernelBootstrapper          — MODX_API_MODE + require index.php
    └─ Database\SnapshotManager              — baseline dump and restore
```

> **Recorded implementation deviation.**
> The `Stubs\LexiconStub` and `Stubs\ErrorStub` classes shown in the diagram above do not exist in
> the code and will not. The `Stubs\TestbenchModx` stub inherits the real `MODX\Revolution\modX`
> and overrides `lexicon()` and `log()` in itself, recording the calls into `Stubs\LogRecorder`;
> separate objects for the lexicon and for errors would add a third layer on top of the inheritance
> that already exists, without adding anything to the contract. The actual composition of level 1
> is `Stubs\{TestbenchModx, LogRecorder, ObjectStore}` plus `Support\CoreAutoloader`.
>
> **A clarification to "level 1 needs no database" (`§2`).** The invariant stands: level 1 does not
> need a DBMS. But it does need the core files on disk — `TestbenchModx` inherits the real `modX`.
> That is why `bootstrap.php` registers the core autoloader even when `prepare()` failed at the
> database installation step.

### 6.1 Components

#### `Environment\TestbenchKernel`
The single entry point for tests, split in two: an idempotent `prepare(): Workspace` — checks the workspace → obtains and installs the core if needed → takes the baseline — and `modx(): modX`, which loads the core through `Bootstrap\KernelBootstrapper::boot(Workspace): modX` and returns `$modx`. Both hold their result for the whole PHPUnit process; `TestCase::setUp()` calls `TestbenchKernel::instance()->modx()`.

#### `Environment\Workspace`
Owns the directory `$XDG_CACHE_HOME/modx-testbench/workspaces/<hash>` (otherwise `$HOME/.cache/...`, otherwise `sys_get_temp_dir()/...`), where `<hash>` is the configuration fingerprint; see FR-ENV-8. Reads and writes `testbench.lock.json`, provides `isInstalledWith(string $fingerprint): bool` and `destroy()`. Answers the question "can what is already installed be reused?".

#### `Environment\Provider\CoreProvider`
```php
interface CoreProvider
{
    public function fingerprint(): string;          // takes part in the workspace hash
    public function provide(string $targetDir): CoreLocation;
}
```
- `ZipReleaseProvider` — downloading and caching a release, unpacking it into the workspace.
- `GitCloneProvider` — `git clone --depth=1 --branch=<ref>` + `composer install --no-dev` in `core/`.
- `LocalPathProvider` — copying a ready distribution from `MODX_TESTBENCH_CORE_PATH`.

`CoreLocation` is a DTO of absolute paths: the root, `core/`, `manager/`, `connectors/`, `setup/`, and the version that was determined.

#### `Installer\InstallConfig` and `Installer\ConfigXmlWriter`
`InstallConfig` is an immutable DTO of all the installation parameters. `ConfigXmlWriter` renders the `<modx>` manifest, setting all the context paths and URLs explicitly, `inplace=1`, `unpacked` according to the provider, and `remove_setup_directory=0`. The details and the pitfalls are in [MODX_HEADLESS_INSTALL.md](MODX_HEADLESS_INSTALL.md).

#### `Installer\HeadlessInstaller`
Runs the installer through `symfony/process` and validates the result by three signs: the exit code, the appearance of `core/config/config.inc.php`, and the absence of error markers in the output. On failure — `InstallationFailedException` with the full output.

#### `Bootstrap\KernelBootstrapper`
Defines `MODX_API_MODE`, `MODX_CORE_PATH` and `MODX_CONFIG_KEY`, includes `index.php` inside an output buffer, loads the `error`/`lexicon` services, redirects logging to a file and lowers the log level.

#### `Isolation\IsolationStrategy`
```php
interface IsolationStrategy
{
    public function begin(modX $modx): void;
    public function end(modX $modx): void;
}
```
The strategy is chosen by the `TestCase::isolationStrategy()` method. The default is `TransactionIsolation`. The `Concerns\RefreshesDatabase` trait overrides that method to `SnapshotIsolation`. A strategy as an object rather than two conflicting traits — so that switching is one line and needs no `insteadof`.

#### `Database\SnapshotManager`
`capture()` / `restore()` on top of two implementations: `MysqlDumper` (an external utility, fast) and `PhpDumper` (`SHOW TABLES` → `SHOW CREATE TABLE` → a chunked `SELECT`, works everywhere). The strategy is chosen by the FORMAT that the installation recorded in `testbench.lock.json` (FR-ISO-5a). The preference for the external utility applies where there is no record to choose by: when installing the environment, and when re-taking a baseline that an otherwise whole environment no longer has as a usable snapshot — the file vanished from disk or was left unfinished (the recorded format described a snapshot that is gone). A snapshot lying on disk is never handed to a different strategy.

#### `Package\PackageDefinition` / `PackageRegistrar`
A declarative description of the extra and its application to a live core. The order of application is fixed: `modNamespace` → `addPackage()` → creating the tables → system settings → services → plugins and events.

#### `Package\TransportInstaller`
Building the transport with the extra's own script and installing it with the core processor. Exercises the real user-facing scenario of installing a package.

#### `Stubs\TestbenchModx`
A descendant of the real `modX`, created through `ReflectionClass::newInstanceWithoutConstructor()`: the core constructor is not executed and no connection is opened, yet `instanceof modX` holds. The public properties (`config`, `services`, `context`, `lexicon`) are substituted by hand, and the data-access methods are overridden onto `ObjectStore`. The service container is the real one — `MODX\Revolution\Services\Container` (a descendant of `Pimple\Container`) — an implementation of our own is not needed. The approach was verified on MODX 3.2.3 in practice, not only by reading the sources.

### 6.2 Usage contract

```php
final class ImportJobTest extends \ModxKit\Testbench\TestCase
{
    // Mandatory for any extra that declares its own tables through ->tables():
    // creating them is DDL, and in MySQL DDL performs an implicit commit that breaks
    // the test transaction. Without the trait the very first test dies with
    // ModxKit\Testbench\Exception\TransactionLostException.
    use \ModxKit\Testbench\Concerns\RefreshesDatabase;

    protected function packageDefinition(): PackageDefinition
    {
        return PackageDefinition::make('myextra')
            ->corePath(dirname(__DIR__, 2) . '/core/components/myextra/')
            ->model('myextra', __DIR__ . '/../src/Model/', 'mex_')
            ->tables(ImportJob::class, ImportRun::class)
            ->settings(['myextra_chunk_size' => 500])
            // The factory is called WITHOUT arguments (`PackageRegistrar::registerServices()`):
            // a `fn (modX $modx) => …` signature gives `ArgumentCountError` at the first `get()`.
            ->service(ImportService::class, fn (): ImportService => new ImportService($this->modx));
    }

    public function testJobPersists(): void
    {
        $job = $this->modx->newObject(ImportJob::class);
        $job->set('name', 'test');

        self::assertTrue($job->save());
        $this->assertObjectExists(ImportJob::class, ['name' => 'test']);
    }
}
```

## 7. Configuration through the environment

| Variable                       | Purpose                                                                                                  | Default                                                                                                                             |
| ------------------------------ | -------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `MODX_TESTBENCH_VERSION`       | Core version for the zip provider                                                                        | the latest known stable 3.x release                                                                                                 |
| `MODX_TESTBENCH_PROVIDER`      | `zip` \| `git` \| `local`                                                                                | `zip`                                                                                                                               |
| `MODX_TESTBENCH_GIT_REF`       | Branch or tag for the git provider                                                                       | `3.x`                                                                                                                               |
| `MODX_TESTBENCH_CORE_PATH`     | Path to a ready distribution for `local`                                                                 | —                                                                                                                                   |
| `MODX_TESTBENCH_WORKSPACE`     | Overrides the environment directory (the directory is deleted in full on a reinstallation, see FR-ENV-5) | `$XDG_CACHE_HOME/modx-testbench/workspaces/<hash>`, otherwise `$HOME/.cache/...`, otherwise `sys_get_temp_dir()/...` (see FR-ENV-8) |
| `MODX_TESTBENCH_CACHE_DIR`     | Release cache directory                                                                                  | `~/.cache/modx-testbench`                                                                                                           |
| `MODX_TESTBENCH_FORCE_INSTALL` | Force a clean reinstallation                                                                             | `0`                                                                                                                                 |
| `MODX_TESTBENCH_DB_HOST`       | DBMS host                                                                                                | `127.0.0.1`                                                                                                                         |
| `MODX_TESTBENCH_DB_PORT`       | DBMS port                                                                                                | `3306`                                                                                                                              |
| `MODX_TESTBENCH_DB_NAME`       | Test database name                                                                                       | `modx_testbench`                                                                                                                    |
| `MODX_TESTBENCH_DB_USER`       | DBMS user                                                                                                | `root`                                                                                                                              |
| `MODX_TESTBENCH_DB_PASS`       | DBMS password                                                                                            | empty                                                                                                                               |
| `MODX_TESTBENCH_DB_PREFIX`     | Table prefix                                                                                             | `modx_`                                                                                                                             |
| `MODX_TESTBENCH_DB_CHARSET`    | Connection charset                                                                                       | `utf8mb4`                                                                                                                           |
| `MODX_TESTBENCH_DB_COLLATION`  | Collation                                                                                                | `utf8mb4_general_ci`                                                                                                                |
| `MODX_TESTBENCH_ADMIN_USER`    | Administrator login                                                                                      | `testbench`                                                                                                                         |
| `MODX_TESTBENCH_ADMIN_PASS`    | Administrator password                                                                                   | `TestbenchPass123!`                                                                                                                 |
| `MODX_TESTBENCH_ADMIN_EMAIL`   | Administrator email                                                                                      | `testbench@example.com`                                                                                                             |

Order of precedence: explicit arguments in code → environment variables → defaults.

## 8. Error handling

Every exception inherits `ModxKit\Testbench\Exception\TestbenchException`. The message is required to contain the cause and the next action.

| Exception                           | When                                                            | What it reports                                                                                                                                                                                                                                                                                                 |
| ----------------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CoreDownloadFailedException`       | the release is unreachable or the archive is corrupt            | the version, the reason, the URLs tried, the release cache path, a hint to delete the cache directory                                                                                                                                                                                                           |
| `InstallationFailedException`       | the installer returned an error                                 | the installer's stdout and stderr with the password masked, the path to `config.xml`, the full command                                                                                                                                                                                                          |
| `TransactionLostException`          | the transaction is lost (DDL, a hidden commit or MyISAM tables) | the cause of the loss and a hint about `RefreshesDatabase`                                                                                                                                                                                                                                                      |
| `SnapshotFailedException`           | taking or restoring the dump failed                             | the strategy used and the reason (`because()`); for an unclosed transaction on the core connection (`openTransactionOnKernelConnection()`) — that the transaction has been rolled back, the database restored from the baseline, and why opening a transaction by hand under `RefreshesDatabase` is not allowed |
| `PackageRegistrationException`      | the extra could not be registered                               | which step failed and why: declarative registration — `atStep()`, building or installing the transport — `atTransportStep()`                                                                                                                                                                                    |
| `UnsupportedStubOperationException` | an unsupported stub operation was reached                       | what exactly is unsupported — the method name (`forMethod()`), the constructor being forbidden (`forConstructor()`), the class of the seeded object and the type it returned (`forSeededObject()`) or the criterion type (`forCriteria()`) — and a recommendation to move to level 2                            |

> **Recorded implementation deviation.** There is no `DatabaseUnavailableException` class in the
> package: `src/Exception/` holds 13 files — the base `TestbenchException`, 11 of its descendants
> and the `SecretFreeMessage` marker interface. An unreachable DBMS arrives as
> `InstallationFailedException` (during installation), `TransactionNotStartedException` (at the
> start of a test) or the base `TestbenchException` (on bookkeeping connections); the text names
> the host, the port, the user and the variables `MODX_TESTBENCH_DB_HOST/PORT/USER/PASS`. No
> exception text contains a hint about `ci/docker-compose.yml` — there are zero runtime string
> literals about `docker`/`compose` in `src/`, and the three matches across 65 files fall in
> docblocks.
>
> The table above does not list every class: the full list, with what to check for each, is in
> [DX_GUIDE.md](DX_GUIDE.md), the "Diagnostics" section.

## 9. Testing strategy for the package itself

- **The unit suite** (`tests/Unit`) — rendering `config.xml`, hashing the workspace, reading the lock file, parsing env, stub behaviour. It runs with the database switched off; that is a separate CI job proving the invariant from section 2.
- **The integration suite** (`tests/Integration`) — really downloading and installing the core, transactional isolation, snapshots, registering an extra, building and installing a transport.
- **The fixture extra** (`tests/Fixtures/SampleExtra`) — a minimal but complete extra: a namespace, one xPDO model, one system setting, one service, one plugin on a system event. Serves as end-to-end proof of the whole chain.
- **Dogfooding** — the recipes in `docs/DX_GUIDE.md` are exercised by running them: on a copy of a real extra and in a consumer configuration (a separate project that installs the package through `composer require --dev` and has only `vendor/`). The real extra keeps no permanent dependency on the package — the run goes over a copy, so a divergence is caught by running the recipes, not by the presence of a line in someone else's `composer.json`.

## 10. Known limitations

| Limitation                                         | Cause                                                                                           | Workaround                                                                        |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| One core version per PHPUnit process               | `MODX_CORE_PATH` is a PHP constant                                                              | The version matrix is split across CI jobs                                        |
| MySQL/MariaDB only                                 | The MODX 3 installer is in practice tied to MySQL ([ADR-0002](adr/0002-no-in-memory-sqlite.md)) | —                                                                                 |
| MyISAM tables are not rolled back by a transaction | An engine property                                                                              | The `RefreshesDatabase` trait                                                     |
| DDL causes an implicit commit in MySQL             | A MySQL property                                                                                | The `RefreshesDatabase` trait; the loss of the transaction is detected explicitly |
| The first installation takes tens of seconds       | A real CMS installation                                                                         | The release cache, `unpacked=1`, reusing the workspace                            |
| Level 1 stubs do not cover the whole core API      | A conscious YAGNI                                                                               | An uncovered operation gives an explicit exception with a hint                    |

## 11. Related documents

- [MODX_HEADLESS_INSTALL.md](MODX_HEADLESS_INSTALL.md) — a reference on the non-interactive installation
- [DX_GUIDE.md](DX_GUIDE.md) — recipes for an extra developer
- [adr/](adr/) — the architecture decision log

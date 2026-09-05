# ModxKit Testbench

> Russian version: [README.ru.md](README.ru.md) — a translation, it may lag behind this document.

An automated test environment for MODX Revolution 3 extras — what `orchestra/testbench` is for Laravel.

> **Status:** the implementation is complete. The API described below matches the code in `src/` and has been checked against it by running a real extra — see the "FAQ" section of [docs/DX_GUIDE.md](https://github.com/modxkit/testbench/blob/main/docs/DX_GUIDE.md) and the "Known limitations" section of [docs/SPEC.md](https://github.com/modxkit/testbench/blob/main/docs/SPEC.md).

The package deploys a real MODX 3 into a temporary directory, installs it non-interactively, boots it in API mode and gives you base test classes with state isolation. Not a single manual step: add it to `require-dev` and run `composer test`.

## Why

In MODX 3 the integration layer of an extra — xPDO models, processors, plugins, system settings — is in practice not tested: bringing an environment up by hand is expensive, and the browser installer cannot be run in CI. The package removes that barrier.

## Requirements

- PHP 8.2–8.4, the `json`, `mbstring`, `pdo`, `pdo_mysql`, `zip` extensions — all declared in the package `composer.json`, nothing to install separately
- MySQL 8 or MariaDB 10.11 (for the integration level only)
- MODX Revolution 3.1+ (the default version is `3.2.3-pl`). **The 3.0.x line is not supported:** its core does not boot fully in API mode — `modX::reloadConfig()` includes one and the same model file twice and kills the process with `Cannot redeclare class`, and before that `getOption('core_path')` returns `null`. The cause lies inside the 3.0.5 core, and the package does not fix it. 3.1.2-pl and 3.2.3-pl are the versions that are checked
- PHPUnit 10.5 / 11 / 12 — comes along with the package (`require`), no need to require it separately

## Installation

```bash
composer require --dev modxkit/testbench
```

There is no need to require PHPUnit separately: `ModxKit\Testbench\TestCase` extends
`PHPUnit\Framework\TestCase`, so PHPUnit is declared in the package `require` and arrives with
it (verified by resolving in a clean project: `vendor/bin/phpunit` appears).

## Quick start

`phpunit.xml`:

```xml
<phpunit bootstrap="vendor/modxkit/testbench/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         beStrictAboutOutputDuringTests="true">
    <testsuites>
        <testsuite name="unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="integration"><directory>tests/Integration</directory></testsuite>
    </testsuites>
</phpunit>
```

Run the suites in **separate processes** (`phpunit --testsuite unit`, then
`phpunit --testsuite integration`). A bare `vendor/bin/phpunit` without `--testsuite` runs both
suites in one PHP process, and the levels load the MODX core along different paths: **if those paths
diverge**, the package refuses to boot the second core instead of mixing the two
(`KernelBootstrapper::assertSingleCorePerProcess()`). While they agree, a bare run is green —
measured — so the separation is a precaution, not a failure you are guaranteed to meet. See the
"CI" section.

An integration test:

```php
use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\Package\PackageDefinition;
use ModxKit\Testbench\TestCase;
use MyVendor\MyExtra\Model\Job;

final class JobTest extends TestCase
{
    // Mandatory for any extra that declares its own tables through ->tables():
    // creating them is DDL, and in MySQL DDL performs an implicit commit that breaks
    // the test transaction.
    use RefreshesDatabase;

    protected function packageDefinition(): PackageDefinition
    {
        $core = dirname(__DIR__) . '/';

        return PackageDefinition::make('myextra')
            ->corePath($core)
            ->model('MyVendor\\MyExtra\\Model', $core . 'src/', 'mex_', 'MyVendor\\MyExtra\\')
            ->tables(Job::class)
            ->settings(['myextra_chunk_size' => 500]);
    }

    public function testJobPersists(): void
    {
        $job = $this->modx->newObject(Job::class);
        $job->set('name', 'nightly');

        self::assertTrue($job->save());
        $this->assertObjectExists(Job::class, ['name' => 'nightly']);
    }
}
```

A fast test without a database:

```php
use ModxKit\Testbench\Unit\UnitTestCase;

final class PriceFormatterTest extends UnitTestCase
{
    public function testEmitsEvent(): void
    {
        (new PriceFormatter($this->modx))->format(100.0);

        $this->assertEventInvoked('OnMyExtraPriceFormatted');
    }
}
```

## Two levels

|                           | Level 1 — Unit                                           | Level 2 — Integration                                     |
| ------------------------- | -------------------------------------------------------- | --------------------------------------------------------- |
| Base class                | `ModxKit\Testbench\Unit\UnitTestCase`                    | `ModxKit\Testbench\TestCase`                              |
| Database needed           | no                                                       | yes                                                       |
| Core files on disk needed | yes (the `modX`/`xPDO` classes are loaded for real)      | yes                                                       |
| MODX installation needed  | no                                                       | yes, performed automatically                              |
| What it checks            | business logic, contracts, work with settings and events | xPDO models, the schema, processors, plugins, permissions |

## State isolation

Every test runs inside a transaction and is rolled back. If a test performs DDL or installs a transport package, the transaction is lost — the package detects this and points you at a trait:

```php
use ModxKit\Testbench\Concerns\RefreshesDatabase;
```

It restores the database from the baseline snapshot taken right after the core was installed.

The isolation-loss detector catches four ways of losing it: an implicit commit after DDL (the
cleared `SERVER_STATUS_IN_TRANS` flag), `START TRANSACTION`/`BEGIN` as raw SQL and `commit()` with
a new `beginTransaction()` (a guard marker that survived the rollback), and MyISAM tables (an
engine check before the test).

**A caveat to read right away rather than later.** A write made from ANOTHER connection or from a
subprocess is invisible to the detector, and cannot be otherwise: it is not subject to the test
transaction in principle. `RefreshesDatabase` is what that case needs; `TransportInstaller` inside
a test transaction refuses to work on its own.

Besides the database, the core file cache `core/cache/` (except `logs/`) and the MySQL session
variables are restored after every test; `$modx->error` is reset before every test. But
`$modx->services`, `$xpdo->packages` and the files installed by a transport package live until the
end of the PHPUnit process — the exhaustive list is in `docs/DX_GUIDE.md`, section 4.

## Names

The package is `modxkit/testbench` and its PHP namespace is `ModxKit\Testbench\`. Several names
around it are not spelled `modxkit/testbench`:

- `vendor/bin/modx-testbench` — the console binary; there is no `vendor/bin/testbench`
- `MODX_TESTBENCH_…` — the prefix of every variable in the first column of the table below
- `modx-testbench` — a segment that appears in the default cache and environment paths; the
  `MODX_TESTBENCH_CACHE_DIR` and `MODX_TESTBENCH_WORKSPACE` rows of that table give those defaults
- `modx_testbench` — the default database name (`MODX_TESTBENCH_DB_NAME`)
- `modx-testbench: ` — the opening of some of the package's own messages, such as
  `modx-testbench: the Composer autoloader was not found …`

These are two kinds of name, and they are not interchangeable. `modxkit/testbench` is an ADDRESS —
something resolves it: Composer in `require`, the directory under `vendor/`, a workflow's `uses:`.
(`ModxKit\Testbench\` is an address too, in the spelling the autoloader resolves.) The first four
names in the list are the other kind: something is found by them — the executable by its name, the
variables out of the environment, the database and the directories on disk. What the values of
those variables determine is the subject of "What exactly causes a reinstall" below.

## Environment variables

| Variable                       | Purpose                                                                                                                   | Default                                                                                                                                                                          |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `MODX_TESTBENCH_VERSION`       | Core version for the zip provider                                                                                         | `3.2.3-pl` (`TestbenchConfig::DEFAULT_VERSION`)                                                                                                                                  |
| `MODX_TESTBENCH_PROVIDER`      | `zip` \| `git` \| `local`                                                                                                 | `zip`                                                                                                                                                                            |
| `MODX_TESTBENCH_GIT_REF`       | Branch or tag for the git provider                                                                                        | `3.x`                                                                                                                                                                            |
| `MODX_TESTBENCH_CORE_PATH`     | Root of a ready distribution (a directory with `index.php` and `setup/`) for `local`; level 1 takes `<path>/core` from it | —                                                                                                                                                                                |
| `MODX_TESTBENCH_WORKSPACE`     | Override of the environment directory (**the directory is deleted in full** on reinstall — see below)                     | `$XDG_CACHE_HOME/modx-testbench/workspaces/<hash>`, else `$HOME/.cache/modx-testbench/workspaces/<hash>`, else `sys_get_temp_dir()/modx-testbench/workspaces/<hash>` — see below |
| `MODX_TESTBENCH_CACHE_DIR`     | Release cache directory                                                                                                   | `$XDG_CACHE_HOME/modx-testbench`, else `$HOME/.cache/modx-testbench`, else `sys_get_temp_dir()/.cache/modx-testbench`                                                            |
| `MODX_TESTBENCH_FORCE_INSTALL` | Force a clean reinstall                                                                                                   | `0`                                                                                                                                                                              |
| `MODX_TESTBENCH_DB_HOST`       | DBMS host                                                                                                                 | `127.0.0.1`                                                                                                                                                                      |
| `MODX_TESTBENCH_DB_PORT`       | DBMS port                                                                                                                 | `3306`                                                                                                                                                                           |
| `MODX_TESTBENCH_DB_NAME`       | Test database name (created automatically if absent)                                                                      | `modx_testbench`                                                                                                                                                                 |
| `MODX_TESTBENCH_DB_USER`       | DBMS user                                                                                                                 | `root`                                                                                                                                                                           |
| `MODX_TESTBENCH_DB_PASS`       | DBMS password                                                                                                             | empty                                                                                                                                                                            |
| `MODX_TESTBENCH_DB_PREFIX`     | Table prefix                                                                                                              | `modx_`                                                                                                                                                                          |
| `MODX_TESTBENCH_DB_CHARSET`    | Connection charset                                                                                                        | `utf8mb4`                                                                                                                                                                        |
| `MODX_TESTBENCH_DB_COLLATION`  | Collation                                                                                                                 | `utf8mb4_general_ci`                                                                                                                                                             |
| `MODX_TESTBENCH_ADMIN_USER`    | Administrator login                                                                                                       | `testbench`                                                                                                                                                                      |
| `MODX_TESTBENCH_ADMIN_PASS`    | Administrator password                                                                                                    | `TestbenchPass123!`                                                                                                                                                              |
| `MODX_TESTBENCH_ADMIN_EMAIL`   | Administrator email                                                                                                       | `testbench@example.com`                                                                                                                                                          |

### What exactly causes a reinstall

The environment directory is addressed by a configuration fingerprint — a hash of every variable
that determines the outcome of the installation: the provider and its own parameters (the release
version for `zip`, the branch for `git`, the path to the distribution for `local`), all
`MODX_TESTBENCH_DB_*` (including the user, the password and the collation) and all
`MODX_TESTBENCH_ADMIN_*`. Change any of them and you get a different environment, installed from
scratch; the environment installed with the previous values stays where it is and is reused if you
put them back.

Parameters that do not determine the installation do not change the fingerprint:
`MODX_TESTBENCH_CACHE_DIR`, `MODX_TESTBENCH_WORKSPACE`, `MODX_TESTBENCH_FORCE_INSTALL`, and the
parameters of the provider that is not currently selected (`MODX_TESTBENCH_GIT_REF` with
`provider=zip` and vice versa).

> **Upgrading from an earlier version of the package to this one.** Both the composition of the
> fingerprint and the directory in which the package keeps environments have changed (see the next
> section), so the first run after the upgrade addresses a DIFFERENT directory and installs the
> environment anew. The previous directory
> (`sys_get_temp_dir()/modx-testbench/<old hash>`) is not deleted: the package no longer knows its
> name. It is harmless but takes up space — orphans of the previous layout can be removed like
> this:
>
> ```bash
> find "$(php -r 'echo sys_get_temp_dir();')/modx-testbench" \
>      -mindepth 1 -maxdepth 1 -type d ! -name workspaces -exec rm -rf {} +
> ```
>
> The `! -name workspaces` exclusion is mandatory, and it is not an over-precaution: if neither
> `HOME` nor `XDG_CACHE_HOME` is set, the LIVE environment sits in exactly
> `<temporary directory>/modx-testbench/workspaces/<hash>` — that is, inside what is being deleted.
> Without the exclusion the command would wipe the working environment of precisely the audience
> the section above says ends up in the shared temporary directory. None of this concerns those who
> set `MODX_TESTBENCH_WORKSPACE`: the path does not change, and the directory is reused by an
> ordinary reinstall.

### The environment directory lives in the user's private directory

The name of the environment directory is a configuration fingerprint, and the DBMS password and the
administrator password are hashed into it (that is requirement FR-ENV-4: an environment installed
with different credentials must not be reused). As long as such a name sat in the shared temporary
directory of the system, any user of the machine could read it — and knowing the remaining
ingredients of the fingerprint (host, port, database name, MODX version) one can brute-force a weak
password offline against it. That is why the environment directory lives in `$XDG_CACHE_HOME`
(else `$HOME/.cache`), next to the release cache, in a `workspaces/` subdirectory of its own. The
package creates that whole stretch of the path itself and straight away with mode `0700` — and the
`workspaces/` subdirectory is load-bearing here, not decorative: the `modx-testbench` directory is
shared with the release cache and may well already exist with wide permissions.

If neither `HOME` nor `XDG_CACHE_HOME` is set — that happens in a container started with `--user`,
or in cron with an empty environment — the package has nowhere else to go and falls back to the
shared temporary directory of the system. That does not by itself leak the name: the stretch of the
path is created with mode `0700` there as well, and an outsider gets `Permission denied` already at
`modx-testbench` (measured in a Linux container where `/tmp` has mode `1777`).

But if the `.../modx-testbench/workspaces` directory **already existed** and is open to outsiders
for reading and traversal — for instance, somebody else created it in advance in a shared `/tmp` —
the name of the environment directory really is readable, and the package says so: it prints a
warning (`E_USER_WARNING`) and repeats it in the voice of `bin/modx-testbench install`, because the
visibility of PHP warnings depends on the ini. The package does not fix somebody else's
permissions meanwhile — it is not its directory. The cure is `chmod 700` on the named directory, or
`MODX_TESTBENCH_WORKSPACE` followed by a reinstall of the environment.

### After a package upgrade the environment is reinstalled once

`testbench.lock.json` records the revision of the installation behaviour. If a package upgrade
changed what the installation puts into the database or into the snapshot file, the revision is
incremented, and the very first `install` (or the first test run) deploys the environment anew —
there is nothing to migrate the old one in place with. It looks like a dozen or two seconds of
pause out of the blue; that is expected and happens once. **There is no need to interrupt it** —
and it is not dangerous either: the directory is marked with a `.testbench-workspace` file for the
whole cleanup, and an interrupted reinstall is finished by the next run.

### The baseline is captured and read by one and the same strategy

The database snapshot is made either by the `mysqldump` client (it carries over both views and
triggers) or by the built-in PHP fallback — whatever is available is what it is captured with. The
clients are looked up in pairs and in this order: first `mysqldump`/`mysql`, then
`mariadb-dump`/`mariadb` (MariaDB clients live under the second pair of names starting roughly with
12.x, where the old symlinks are gone). What the snapshot ended up being captured with is printed
by `bin/modx-testbench install` on a separate line — the fallback to the PHP strategy used to
happen silently. The package records what exactly in `testbench.lock.json` and uses that record to
choose what to read the snapshot with: the formats are not interchangeable, and a `mysqldump` dump
handed to the PHP fallback stumbles over the client-side `DELIMITER` command after the database has
already been wiped for the restore.

So if the baseline was captured with `mysqldump` and there are no clients in `PATH` in this run (a
common case: the terminal, the IDE and `make` see different `PATH`s), the package refuses — without
touching the database — and offers either to put the clients back into `PATH` or to recreate the
environment. Environments deployed by a previous version of the package do not carry that record,
so they are reinstalled once (see the section above): there is nothing to guess the provenance of a
snapshot already lying on disk with, and the price of a mistake is a wiped database.

### What changed in the public interfaces

Changes marked "internal layer" concern only those who extend the package with their own classes;
the rest are visible from a consumer's tests.

- **Internal layer.** `ModxKit\Testbench\Database\Dumper` gained a `format(): string` method — the name of the
  snapshot format that the installation writes into `testbench.lock.json` (`php`, `mysql` or your
  own). A third-party implementation of the interface will not compile without it.
- **Internal layer.** `ModxKit\Testbench\Environment\LockFile::__construct()` gained a `$snapshotFormat`
  parameter as the EIGHTH one, before `$installRevision` (which became the ninth). Named calls —
  the only kind inside the package itself — are unaffected, and so is a call with seven positional
  arguments. What breaks is a call with an EIGHTH positional argument, which used to be the
  installation revision, and it breaks differently: under `declare(strict_types=1)` it is an
  immediate `TypeError`, without it things are worse — the `int` is silently cast to a string, the
  revision lands in the format field, and the revision itself is taken from the default. Verified by
  executing both cases.
- The `mysqldump` strategy no longer puts `init-command` into the `[client]` group of the temporary
  options file. The previous placement killed the snapshot capture with a failure before the DBMS
  connection was even made, that is, `modx-testbench install` did not go through at all. Measured on
  five client builds: `mariadb-dump` 10.6.28, 10.11.18 and 12.3.2 and `mysqldump` 8.0.46 refused,
  only `mysqldump` 8.4.11 accepted it. The package asserts nothing about unmeasured builds of these
  lines. The failure hits both lines at once: under the names `mysql`/`mysqldump` sit both MariaDB
  clients (Debian's `default-mysql-client`) and Oracle clients (Ubuntu's `mysql-client`). The
  message differs by vendor meanwhile: Oracle prints `mysqldump: [ERROR] unknown variable
  'init-command=…'.`, MariaDB — `mysqldump: unknown variable 'init-command=…'`, without the prefix
  and the full stop. On the GitHub Actions runners it is the Oracle client that gets installed —
  measured on the `ubuntu:24.04` image, where `apt-get install mysql-client` gives
  `mysqldump Ver 8.0.46-0ubuntu0.24.04.4` — and that is where taking a snapshot failed in CI before
  this fix. The option now sits in the `[mysql]`
  group, and the metadata lock wait limit (30 seconds instead of the server default of a year) is
  received by the database cleanup and by the snapshot restore. CAPTURING a snapshot goes with the
  server default: if you capture the baseline with an Oracle 8.4+ client and were counting on the
  limit during capture — it is no longer there. Details and measurements are in FR-ISO-5b in
  `docs/SPEC.md`.
- `ModxKit\Testbench\Database\PhpDumper::load()` (that is, a restore on any machine without the
  `mysql` clients) now removes VIEWS from the database — both those created after the baseline was
  captured and those that existed before it. That is what the `Dumper` contract requires: a restore
  returns the database exactly to the contents of the snapshot, and the php strategy's snapshot
  carries no views and cannot bring them back. If your tests rely on a view living in the test
  database, create it in the test itself (`setUp()`), or install the `mysql`/`mysqldump` clients so
  that the baseline is captured by the strategy that does carry views over.
- Level 1 (`ModxKit\Testbench\Unit\UnitTestCase`) no longer ignores a criterion that is not an array.
  A scalar means the `id` primary key — that is how the core understands it too
  (`xPDO::sanitizePKCriteria()`); `getObject()` with no criterion at all returns `null`; a query
  object (`xPDOQuery`, `xPDOCriteria`) is rejected by the stub with an
  `UnsupportedStubOperationException` instead of silently searching "without criteria". A test that
  relied on the previous behaviour — the stub handing back the first seeded object it found for any
  scalar — will turn red; that is the point of the change. Address a model whose primary key is not
  `id` with an explicit array.
- Processors left level 1. `$modx->runProcessor()` on the stub fails with an
  `UnsupportedStubOperationException` instead of the previous plausible `success => false` with the
  message `Requested processor not found`. A level 1 test that "checked" a processor failure was
  vacuous — the processor was never executed; move it to `ModxKit\Testbench\TestCase`. The line in
  `docs/SPEC.md` about the scope of level 1 has been corrected accordingly.
- The store of seeded objects (`seed()`) now sees the private fields of a double — a criterion on
  such a field used to never match — and `null` in a criterion now matches only `null` (it used to
  be that `['field' => null]` found an object holding `0`, `''` or `false`). A double whose
  `toArray()` returned something other than an array fails with an exception of the package instead
  of a `TypeError` from its internals.
- `$modx->invokeEvent()` at level 1 returns `false`, not an empty array: in the core an empty array
  means "the event exists, the plugins ran and stayed silent", while the stub has no event map at
  all. A test that wrote `foreach ($this->modx->invokeEvent(...) as ...)` will turn red; check the
  fact of the call with `assertEventInvoked()`.
- `$modx->getOption()` at level 1 became a port of `xPDO::getOption()`: it reads `$modx->config`
  (previously — an array of its own, so an edit to `$modx->config` was invisible), understands
  `$skipEmpty` and an array of keys. The previous behaviour differed on empty strings and on an
  array of keys (`Array to string conversion`).
- `new TestbenchModx(...)` fails with an `UnsupportedStubOperationException`. The stub was created
  only through `TestbenchModx::create()` before this too; the constructor inherited from `modX`
  produced an object whose every method died with an `Error` on an uninitialised property.
- The composition of the package dependencies has changed: `phpunit/phpunit` moved from
  `require-dev` into `require` (the public API of the package extends
  `PHPUnit\Framework\TestCase`, without PHPUnit it does not load at all), and `ext-pdo_mysql` (the
  package DSN is always `mysql:`) and `ext-mbstring` were added to the declared extensions.
  Requiring PHPUnit separately is no longer needed; an explicit requirement does no harm. A missing
  driver is now caught during resolution rather than by a `PDOException: could not find driver` in
  the middle of a run.
- `ModxKit\Testbench\Concerns\InteractsWithModx::runProcessor()` gained a third parameter
  `array $options = []` — it goes into `modX::runProcessor()` as is, for the sake of
  `$options['processors_path']`. It is compatible with every existing call; an override of the
  method in your own base test class needs to be extended with the same signature. Without this
  path an extra's processor addressed by a string was never found, and the response looked like an
  ordinary processor failure.
- `InteractsWithModx::createUser()` gained a second parameter `array $profile = []` — the
  `modUserProfile` fields (`email`, `fullname`). Such fields passed in the first argument used to
  disappear silently: `modUser::fromArray()` ignores fields it does not know, and the profile got a
  generated address.
- `ModxKit\Testbench\TestCase` gained an extension point `afterPackageRegistered()` — it is called
  from `setUp()` right after the `PackageDefinition` is applied, inside an already open isolation.
  If your base test class already has a method with that name, it becomes an override of the hook
  and starts being called by itself.
- `PackageDefinition::make('')` now fails instead of creating a description with an empty
  `modNamespace`, whose registration looked successful while giving the core not a single path of
  the extra.
- `Env::int()` (`MODX_TESTBENCH_DB_PORT` and other numeric variables) rejects a non-numeric value
  instead of silently truncating: `330a` no longer turns into `330`.
- `ModxKit\Testbench\Support\CoreAutoloader::register()`, called a SECOND time with a DIFFERENT path
  to the core, now throws a `TestbenchException` instead of being a silent no-op. The caller used to
  get "success" while the core from the first directory stayed in the process — the test worked with
  one distribution believing it worked with another (two distributions in one PHP process are
  incompatible in principle: identically named core classes and a `ComposerAutoloaderInit…`
  collision). A repeated call with the same path (including one with a trailing slash) remains a
  no-op. If your tests deliberately switch `MODX_TESTBENCH_CORE_PATH` between distributions, split
  them across processes (`#[RunInSeparateProcess]`).
- The installation manifest `setup/config.xml` is deleted after a SUCCESSFUL installation, and
  `core/config/config.inc.php` gets mode `0600`: both files carry passwords in clear text, and the
  default working directory sits in a shared `/tmp`. On a failure the manifest stays — it is the
  main diagnostic artefact, and the exception text refers to it. A script that read
  `setup/config.xml` of the working directory after the installation will no longer find the file.
  Tightening the permissions is a protective measure, not a criterion of success: if the `chmod`
  does not go through, the installation is not cancelled. This is exactly the case of "the file was
  written but narrowing the permissions did not work": by the general description that is how some
  network and container mounts behave — we have not measured that ourselves. The `chmod` failures
  measured on macOS — a file with the immutable flag (`chflags uchg`) and a volume mounted
  read-only — do not fall here: writing fails on those too, so the installation dies earlier. And a
  FAT32 image, contrary to the common explanation "a volume without POSIX permissions", does not
  reject `chmod` at all — it returns `true`, the volume is mounted with `noowners`, and the mode
  stays 0700. Neither does an ACL `deny writesecurity` on the owner.

  `bin/modx-testbench install` reports that a file with a password is accessible to more than its
  owner with a separate warning, leaving the exit code successful. The check is live rather than a
  memory of the installation, so it is honest on a repeated run over an already prepared
  environment as well; the same list is returned by the new method
  `ModxKit\Testbench\Environment\Workspace::exposedSecretFiles()`. There is a second channel too — an
  `E_USER_WARNING` from `ModxKit\Testbench\Support\FilePermissions` — but it depends on the ini (with
  `display_errors=0` together with `log_errors=0` it reaches nowhere), so it was not left as the
  only one. A third: `bin/modx-testbench status` prints the same live answer on its own
  `Password exposure` line — either the list of files, or `none — the environment files and the
  directory name are private to you`.

  And a fourth, for the path where no command is run at all — the consumer's own
  `vendor/bin/phpunit`: the opt-in PHPUnit extension
  `ModxKit\Testbench\PHPUnit\ExposureWarningExtension`. One line of `phpunit.xml` registers it:

```xml
<extensions>
    <bootstrap class="ModxKit\Testbench\PHPUnit\ExposureWarningExtension"/>
</extensions>
```

  It writes the warning to STDERR once per run, before the first test, and stays silent when
  nothing is exposed. It is opt-in on purpose: a consumer who wants no word out of their test run
  does not add the line. `bootstrap.php`, which prepares the environment on that same path, stays
  SILENT — see below for why.

  The environment directory is created with mode `0700` by default, and that removes most of the
  question: without the right to enter the directory the mode of a file inside plays no role
  (measured on `debian:stable` — a foreign user gets `Permission denied` both on reading a `0644`
  file and on listing a `0700` directory). What remains is a directory set by hand through
  `MODX_TESTBENCH_WORKSPACE`: there the permissions are chosen by the consumer, and they mean
  exactly what they used to.

  One more channel could have been `bootstrap.php` — it is what prepares the environment on the
  first `vendor/bin/phpunit`, and the hole "the bootstrap prepared the environment, nobody called
  `install`" can only be closed there. It stays SILENT about unprotected files, and that is a
  decision, not an omission. Printing indiscriminately breaks tests that run in a separate process —
  PHPUnit treats a non-empty STDERR of the child process as an error; measured: on mode `0644` both
  `fwrite(STDERR, …)` and `trigger_error(…, E_USER_WARNING)` turn eight tests with
  `#[RunInSeparateProcess]` into errors. A working condition does exist (`ob_get_level() === 0` —
  measured: the warning gets through, the run is green), but it fails SILENTLY: a consumer bootstrap
  that starts with `ob_start()` swallows the warning without a trace. The full analysis is in the
  comment at the end of `bootstrap.php`.

### `MODX_TESTBENCH_WORKSPACE` — the directory is deleted in full

The package owns the environment directory outright: on a reinstall (fingerprint mismatch,
`--force`, `MODX_TESTBENCH_FORCE_INSTALL=1`) and on the `destroy` command it is deleted
recursively.

So that a typo such as `MODX_TESTBENCH_WORKSPACE=$PWD` does not cost you your working directory,
deletion is allowed only for directories the package created itself: empty ones, or ones marked
with a `.testbench-workspace` file, or ones containing `testbench.lock.json`. Any other non-empty
directory produces a failure with a non-zero exit code and is left untouched.

Point this variable at a directory of its own — non-existent or empty.

The package claims one more path: the **sibling** `<workspace>.new`. A replacement core is delivered
there first and takes the place of the current environment only once the delivery has succeeded, so
an interrupted reinstall does not leave a half-written environment behind. The same ownership guard
covers it: a `<workspace>.new` the package did not create is not deleted — the command fails with
`WorkspaceOwnershipException`, its contents stay where they are, and the message names a path you
never set yourself. Keep that neighbouring name free.

## CLI

```bash
vendor/bin/modx-testbench install [--force]   # deploy the environment (--force: wipe and install again)
vendor/bin/modx-testbench status              # where the environment is, which version, is there a snapshot, is the database intact, is the password exposed
vendor/bin/modx-testbench snapshot [capture]  # capture the baseline (the default action)
vendor/bin/modx-testbench snapshot restore    # restore the baseline
vendor/bin/modx-testbench destroy [--force]   # delete the environment
```

`destroy` asks for confirmation. Without a TTY (in CI, in a script) the question is not asked and
the answer is taken to be "no" — in a non-interactive environment pass `--force` explicitly, or the
command will finish successfully having deleted nothing.

`destroy` deletes the directory, not the database: measured on a real extra, the test database held
70 tables before the command and the same 70 after, while the workspace directory was gone. If you
need the database empty as well, drop it yourself.

If not everything could be removed (no permissions on part of the tree, a file in use), `destroy`
returns `1` and says so: the directory stays marked as belonging to the package meanwhile, so a
repeated run will try to finish removing it.

`destroy` without variables addresses the environment of the CURRENT configuration. Environments
installed with different values (a different MODX version, a different DBMS, different credentials)
sit in neighbouring directories with different fingerprints, and the package no longer has their
names. Such an environment is deleted by the same command if the directory is addressed explicitly:

```bash
MODX_TESTBENCH_WORKSPACE=~/.cache/modx-testbench/workspaces/<fingerprint> \
  vendor/bin/modx-testbench destroy --force
```

This is not `rm -rf`: the command checks that the directory belongs to the package (a lock or a
marker) and answers a foreign directory with a refusal rather than a wipe. The `find` recipe above
is about something ELSE: it removes orphans of the previous layout that sat outside `workspaces/`,
and deliberately does not descend into `workspaces/`.

The `Database` line shows how many tables with the prefix are in the database now and how many
there were at install time (`70/70 tables`). A discrepancy means the environment is damaged:
`install` will repair it from the baseline or reinstall it.

The DBMS password is replaced with `***` in the output of every command; an empty password is shown
as `empty`.

## CI

```yaml
jobs:
  testbench:
    uses: modxkit/testbench/.github/workflows/testbench.yml@v1
    with:
      php-versions: '["8.2","8.3","8.4"]'
      modx-versions: '["3.1.2-pl","3.2.3-pl"]'
      working-directory: core/components/myextra
```

The workflow runs `composer test` in your repository, so these scripts **must** be present in your
`composer.json` — without them the job fails with `Command "test" is not defined`:

```json
"scripts": {
    "test": ["@test:unit", "@test:integration"],
    "test:unit": "phpunit --testsuite unit",
    "test:integration": "phpunit --testsuite integration"
}
```

**If your `composer.json` already defines `test`, that block replaces it, and your own suite then
disappears from CI without a word** — the workflow runs exactly `composer test` and gets only the two
suites above. Measured on an extra whose own suite holds 1879 tests: after the block was copied
literally, `composer test` ran 4. Keep your own entry point and compose instead, naming the suite you
already have:

```json
"scripts": {
    "test": ["@test:default", "@test:unit", "@test:integration"],
    "test:default": "phpunit --testsuite default",
    "test:unit": "phpunit --testsuite unit",
    "test:integration": "phpunit --testsuite integration"
}
```

Here `default` is the name of your existing suite in `phpunit.xml`, whatever it is; `test:default` is
a name of your own choosing, and the package neither knows nor requires it.

Two processes instead of one `vendor/bin/phpunit` is not decoration: level 1 and level 2 load the
MODX core along different paths, and in one process that used to return the fatal
`Cannot redeclare class ComposerAutoloaderInit…`. Details are in
[docs/DX_GUIDE.md](https://github.com/modxkit/testbench/blob/main/docs/DX_GUIDE.md), the "Wiring up CI" section.

## Limitations

- One core version per PHPUnit process — a matrix of MODX versions is split across CI jobs.
- The package creates a bookkeeping table `testbench_isolation_guard` in the test database (the
  guard marker of the isolation-loss detector): the account needs the `CREATE TABLE` privilege, not
  only `CREATE` on the database. It deliberately has no core prefix — see `docs/DX_GUIDE.md`,
  section 4.
- MySQL/MariaDB only: the MODX 3 installer is in practice tied to MySQL ([ADR-0002](https://github.com/modxkit/testbench/blob/main/docs/adr/0002-no-in-memory-sqlite.md)).
- MyISAM tables and DDL are not rolled back by a transaction — use `RefreshesDatabase`; the detector names both causes before a test trusts the rollback.
- An extra that declares its own tables through `PackageDefinition::tables()` is tested **only**
  under `RefreshesDatabase`: creating them is DDL, and DDL breaks the test transaction.
- The first installation takes tens of seconds; after that the environment is reused.
- The `local` provider refuses to copy a distribution if there is a symbolic link to a directory
  inside it: `RecursiveIteratorIterator` does not descend into it, and the link would silently
  arrive as an empty directory. Replace the link with a real directory.
- The package creates the test database itself if it is absent: the MODX 3 CLI installer, having
  failed to find the database, cannot read the server version and writes
  `'override_table' => 'MyISAM'` into `core/config/config.inc.php` — all 70 core tables would be
  created as MyISAM, and MyISAM does not support transactions. After the installation the package
  checks the table engines and refuses if any of them is non-transactional. The requirement for the
  account is the `CREATE` privilege.
- Files installed by a transport package (`core/components/`, `assets/components/`,
  `core/packages/`) are not rolled back by a snapshot: it returns the database, not the disk. See
  [docs/DX_GUIDE.md](https://github.com/modxkit/testbench/blob/main/docs/DX_GUIDE.md), section 4, "What is rolled back and what is not".
- **A known hole, not closed: on the consumer's main path nothing reports an exposed password by
  itself.** `bootstrap.php` prepares the environment on the first `vendor/bin/phpunit`, and if
  `chmod` did not go through, the files carrying the database password stay readable by more than
  their owner — while `bootstrap.php` deliberately stays silent (printing from there turns every
  `#[RunInSeparateProcess]` test into an error; the full analysis is in the comment at the end of
  that file). The warning exists in `install`, in `status`, in `Workspace::exposedSecretFiles()`
  and in the opt-in `ExposureWarningExtension` — every one of them has to be asked for. What
  narrows the hole, measured: the regular installation creates the directory with mode `0700` and
  `core/config/config.inc.php` with `0600`, on a normally installed environment
  `exposedSecretFiles()` returns an empty list, and the password lives in that single file. The
  `chmod` failure this is left open for is a form we have not been able to reproduce — see the
  section on `install` above.

## Developing the package itself

`composer qa` — cs-fixer, Rector, PHPStan and both suites. The Rector stage is declared as
`rector process --dry-run --clear-cache`, and clearing the cache is mandatory: an incremental
`rector:check` rescans only changed files, which in this project made a green result hide a real
regression three times in a row.

Tasks that touch static state of the process must additionally be run with `--order-by=random` and
`--order-by=reverse`: a dependency on test order arose three times in this project.

**What the integration suite requires from the DBMS account.** A consumer of the package needs only
`CREATE` on the test database (FR-INSTALL-5). The package's own suite needs more: `DatabaseCleanerTest`
and `PhpDumperTest` create a temporary account with reduced privileges (a server refusal in the
middle of a cleanup and in the middle of the wipe before a restore cannot be reproduced otherwise
without touching the global settings of a shared server) — they need the global `CREATE USER` and
`GRANT`. `FixtureDatabaseUserTest`, which checks that fixture itself, is not assembled in
`composer test` by default at all (the `mysql-user-table` group, see below) — the `SELECT`
privilege on `mysql.user` is NOT mandatory for a green `composer test`.

That privilege still matters for TWO OTHER classes: `createFixtureUser()`
(`tests/Support/FixtureDatabaseUser.php`), used by both `DatabaseCleanerTest` and `PhpDumperTest`,
sweeps away foreign orphans through `dropStaleFixtureUsers()` before creating ITS OWN account — that
method reads `mysql.user` and SWALLOWS a `PDOException` silently. Without the privilege the suite
stays green (the cleanup failure does not surface), but the sweep does not happen and orphaned
accounts accumulate on the server. The container root from `ci/docker-compose.yml` has both
`CREATE USER`/`GRANT` and that privilege.

The names of such accounts start with `modx_tb_`, they are deleted in `tearDown()`, and the ones
left over from a killed run are wiped on the next creation of a fixture of the same class — if the
current account has the privilege on `mysql.user`.

**The bookkeeping databases of the package's own suite.** Six integration classes keep a separate
database for their checks; its name is computed from the environment fingerprint and the pid of the
run (`ModxKit\Testbench\Tests\Support\RunScopedDatabaseName`) rather than hardcoded — otherwise two
runs on one DBMS server would wipe each other's databases in the middle of somebody else's test
(found live by three reviewers independently). A database left over from a run killed by Ctrl-C or
by a CI timeout is not cleaned up automatically (unlike the fixture accounts above, which have an
orphan collector by prefix) — it is removed by hand with `DROP DATABASE`, but ONLY by the exact
name FORM `<base>_<12 hex>_<pid>` (for example, `modx_testbench_snapshot_test_4170f4b0fbac_51234`),
NOT by the common prefix `modx_testbench_`: that prefix also covers the working databases of other
runs of the same branch living at the same time — measured on the development server of this
branch, where all 12 databases under the prefix (`modx_testbench_t26`, `_v26`, `_r1`..`_r4`,
`_t23`..`_v25`) were NOT orphans, and a wipe by the common prefix would have destroyed them too.

**`failOnSkipped` and the groups that depend on the environment.** The suite allows no routine
skips: `SnapshotFormatTest` (needs real `mysqldump`/`mysql` clients in PATH) and
`FixtureDatabaseUserTest` (needs the `SELECT` privilege on `mysql.user`) are not skipped
conditionally but excluded by the `mysql-client-tools` and `mysql-user-table` groups in
`phpunit.xml` (next to `mariadb-client`, introduced earlier for the tests on real MariaDB clients).
By default (`composer test:integration`) all three groups are excluded — that is what keeps an
environment without clients and without the needed privilege green. Where both are provided by the
job's own regular steps (CI — `.github/workflows/tests.yml` installs the clients in an explicit step
and connects as `root`), `composer test:integration:with-clients` is run: the same suite with the
`exclude-group mariadb-client` option instead of the exclusion list of `phpunit.xml` — it enables
both groups that depend on the environment rather than on docker; docker for `mariadb-client` is
still not brought up. The job where the clients are installed only under the names
`mariadb`/`mariadb-dump` runs the ordinary `composer test:integration`: the `mysql-client-tools`
group needs the `mysqldump`/`mysql` pair of names, and there it must stay outside along with the
rest. Details and a warning about the fragility of this construct are in the comment in
`phpunit.xml`.

## Documentation

- [docs/SPEC.md](https://github.com/modxkit/testbench/blob/main/docs/SPEC.md) — architectural specification
- [docs/DX_GUIDE.md](https://github.com/modxkit/testbench/blob/main/docs/DX_GUIDE.md) — recipes for an extra developer
- [docs/MODX_HEADLESS_INSTALL.md](https://github.com/modxkit/testbench/blob/main/docs/MODX_HEADLESS_INSTALL.md) — reference on the non-interactive installation of MODX 3
- [docs/adr/](https://github.com/modxkit/testbench/tree/main/docs/adr/) — architecture decision log

## License

MIT

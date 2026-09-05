# DX guide: testing MODX 3 extras with ModxKit Testbench

> This document describes the package's public API as fixed in [SPEC.md](SPEC.md). The implementation is complete; the recipes below have been exercised by running them — with one exception: the workflow block of section 9, "Wiring up CI", which needs GitHub Actions. The rough edges that running them uncovered are collected in the "FAQ" section.

## 1. Choosing a level

The first question to ask of any new test: does it need a database?

| What is under test                                                                            | Level                   | Base class                            |
| --------------------------------------------------------------------------------------------- | ----------------------- | ------------------------------------- |
| Calculations, validation, formatting, DTOs, state machines                                    | 1                       | `ModxKit\Testbench\Unit\UnitTestCase` |
| Code that reads settings, fires events and writes to the log, but does not go to the database | 1                       | `ModxKit\Testbench\Unit\UnitTestCase` |
| xPDO models, the table schema, migrations                                                     | 2                       | `ModxKit\Testbench\TestCase`          |
| Processors, plugins, permissions, system settings in the database                             | 2                       | `ModxKit\Testbench\TestCase`          |
| Installing a transport package                                                                | 2 + `RefreshesDatabase` | `ModxKit\Testbench\TestCase`          |

The rule is simple: start at level 1 and move up to level 2 only when the check loses its meaning without a real database. Level 1 is orders of magnitude faster and **needs no DBMS**.

It does depend on the environment nonetheless, and that is worth knowing in advance: `TestbenchModx`
inherits the real `MODX\Revolution\modX`, so the core files must be present on disk. The package's
`bootstrap.php` puts them there; if unpacking the distribution failed (no network and an empty
release cache), level 1 fails with `ModxKit\Testbench\Exception\TestbenchException`, "The MODX core was
not found…". Verified by running it: with the database switched off but the directory prepared, the
suite is green; with the distribution unreachable, every level 1 test fails with that exception.

## 2. Quick start

```bash
composer require --dev modxkit/testbench
```

PHPUnit comes along with the package: `ModxKit\Testbench\TestCase` inherits
`PHPUnit\Framework\TestCase`, so it is declared in the package's `require`, not in its `require-dev`.
Requiring it separately is possible (to pin a major version, say), but not necessary.

The `phpunit.xml` of your extra:

```xml
<phpunit bootstrap="vendor/modxkit/testbench/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         beStrictAboutOutputDuringTests="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="ModxKit\Testbench\PHPUnit\ExposureWarningExtension"/>
    </extensions>
</phpunit>
```

The `<extensions>` block is optional and opt-in. `ExposureWarningExtension` writes one line to
STDERR, before the first test, when the files of the test environment that carry the database
password are readable by more than their owner; when nothing is exposed it says nothing. It exists
for the path on which no command of the package is ever run: `bootstrap.php` prepares the
environment on the first `vendor/bin/phpunit`, and it deliberately stays silent itself — a warning
raised from a bootstrap is raised again inside every `#[RunInSeparateProcess]` child, where PHPUnit
treats a non-empty STDERR as a test error. An extension is loaded once, in the runner process only.
Drop the block if you want no word out of your test run; `bin/modx-testbench status` answers the
same question on demand.

A local DBMS:

```bash
docker compose -f vendor/modxkit/testbench/ci/docker-compose.yml up -d
export MODX_TESTBENCH_DB_PASS=testbench
vendor/bin/modx-testbench install
```

Compose brings up MySQL 8 on `3306` and MariaDB 10.11 on `3307` (inside the containers both use the
regular 3306). Both create the `modx_testbench` database in advance — that is not cosmetic, see the
"FAQ": if the database is absent by the time of the installation, MODX creates MyISAM tables and
transaction isolation stops working silently.

Then two processes, not one:

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
```

## 3. Describing the extra through `PackageDefinition`

`PackageDefinition` is the single place where you explain to testbench what your package is. The `packageDefinition()` method is called before every test.

```php
use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\Package\PackageDefinition;
use ModxKit\Testbench\TestCase;
use MyVendor\MyExtra\Model\Job;
use MyVendor\MyExtra\Service\JobService;

abstract class ExtraTestCase extends TestCase
{
    // Here — because ->tables(Job::class) is declared below: creating tables is DDL,
    // and DDL breaks the test transaction. See the callout right under the example.
    use RefreshesDatabase;

    protected function packageDefinition(): PackageDefinition
    {
        $core = dirname(__DIR__) . '/';

        return PackageDefinition::make('myextra')
            ->corePath($core)
            ->assetsPath(dirname(__DIR__, 4) . '/assets/components/myextra/')
            ->model('MyVendor\\MyExtra\\Model', $core . 'src/', 'mex_', 'MyVendor\\MyExtra\\')
            ->tables(Job::class)
            ->settings(['myextra_chunk_size' => 500])
            ->service(JobService::class, fn (): JobService => new JobService($this->modx));
    }
}
```

The arguments of `model()` mirror `xPDO::addPackage($pkg, $path, $prefix, $namespacePrefix)`. `$path` is the PSR-4 root (`src/`), not the directory with the model classes; a `metadata.<dbtype>.php` must sit next to the classes. The third argument (`$tablePrefix`) is optional: pass `null` to give the extra's tables the shared core prefix (`modx_`), the way `addPackage()` does in an extra's production `bootstrap.php`.

The order of application is fixed and not configurable: `modNamespace` → `addPackage()` → creating the tables → system settings → services.

Your own step after registration is `afterPackageRegistered()`. It is called from `setUp()` right
after the `PackageDefinition` has been applied, that is, inside an isolation that is already open:
everything it writes is rolled back together with the test.

```php
protected function afterPackageRegistered(): void
{
    // The service from PackageDefinition::service() is already in the container, the model tables already created.
    $this->modx->services->get(JobService::class)->warmUp();
}
```

The hook is called only if `packageDefinition()` returned a definition. Anything that does not depend
on the registration still belongs in your own `setUp()` after `parent::setUp()`.

> **Declared `->tables(...)` — then you must add `RefreshesDatabase`.** Tables are created through
> `xPDOManager::createObjectContainer()`, that is, DDL, and DDL in MySQL performs an implicit commit
> and breaks the test transaction. The failure is treacherously intermittent: `createObjectContainer()`
> does nothing if the table already exists, so only the test that creates it first fails, while its
> neighbours in the same run pass — and leave committed rows behind them. That is exactly why the
> caveat "add the trait where it is needed, not in a shared parent" (section 4) does not apply to an
> extra with tables of its own: there it is needed in every class this `PackageDefinition` applies to
> — that is, in `ExtraTestCase` itself, as in the example above. All the recipes of section 5 are
> written as methods of that class and inherit the trait.
>
> An extra WITHOUT tables of its own (`->tables()` is not called) does not need the trait at all: the
> package registration then performs no DDL, and a transaction is enough.

## 4. Isolation: when a transaction is not enough

By default every test is wrapped in a transaction and rolled back. That is almost always enough, but not when:

- the test performs DDL (`CREATE`/`ALTER`/`DROP TABLE`) — MySQL performs an implicit commit;
- the extra's model uses MyISAM — there are no transactions there at all;
- the test installs a transport package — there is DDL inside the installation.

The sign by which this is recognised is not a flaky failure but a specific exception:

```
ModxKit\Testbench\Exception\TransactionLostException:
The test transaction was implicitly ended — isolation is lost. The usual cause: DDL
(CREATE/ALTER/DROP TABLE) or MyISAM tables, for which MySQL performs an implicit commit.
Add the ModxKit\Testbench\Concerns\RefreshesDatabase trait to the test. There is nothing left to
roll back: the changes the test made before the implicit commit are committed to the database,
and the remaining tests of the run go over a polluted environment — recreate it
(MODX_TESTBENCH_FORCE_INSTALL=1 or `bin/modx-testbench destroy`) before trusting their results.
```

The detector consists of three independent checks and catches four ways of losing isolation:

| What happened                             | What catches it                                                                           |
| ----------------------------------------- | ----------------------------------------------------------------------------------------- |
| DDL inside the test — an implicit commit  | `PDO::inTransaction()`: the server flag `SERVER_STATUS_IN_TRANS` is cleared               |
| `START TRANSACTION` / `BEGIN` as raw SQL  | the guard marker: the flag stays raised, but a different transaction is being rolled back |
| `commit()` + `beginTransaction()`         | the same                                                                                  |
| MyISAM tables (`rollBack()` to no effect) | the engine check of the core-prefixed tables in `begin()`                                 |

**What the detector does not catch and cannot: a write from a SECOND connection.** Everything written
by another process or another connection (the extra's build script started as a subprocess; an
external daemon; your own `new PDO(...)`) is not subject to the test's transaction in principle, and
seeing it would only be possible by comparing the whole database before and after the test — that is,
by a snapshot. For the one regular case — `TransportInstaller` — the protection sits inside it: it
refuses to work inside a test transaction and names `RefreshesDatabase`. For subprocesses of your own,
add the trait yourself.

The cure is one line. If the shared parent does NOT yet add the trait (an extra without tables of its
own — see the callout in section 3), add it to the class that needs it:

```php
use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\TestCase;

final class SchemaTest extends TestCase
{
    use RefreshesDatabase;
}
```

If, however, the parent is the `ExtraTestCase` from section 3, the trait is already there, and there
is no need to repeat it in a descendant: a `use` in the child adds nothing, and it hints to the reader
that neighbouring classes somehow lack the trait.

The trait restores the database from the baseline snapshot after every test. That is noticeably slower
than a transaction, so add it where it is needed — in specific test classes, not in a shared parent.
**The exception is an extra with tables of its own:** there the trait must sit in the shared parent,
because the DDL happens during the package registration, that is, in every test this
`PackageDefinition` applies to (see the callout in section 3).

### The bookkeeping table `testbench_isolation_guard`

The isolation-loss detector keeps the test's guard marker in a separate table
`testbench_isolation_guard` (one column, `marker VARCHAR(64) NOT NULL PRIMARY KEY`, InnoDB engine).
The package creates it itself at the first `begin()`, so the account needs the `CREATE TABLE`
privilege in the test database, not only `CREATE` on the database itself.

The name deliberately carries NO core prefix: the table does not belong to the MODX installation, is
not counted among the tables the integrity check compares, and does not fall under the cleanup before
a reinstallation. Two things follow from that, which are better known in advance: the table survives
`install --force` and gets into the baseline if the snapshot is taken after the first test — the
completion marker at the end of `testbench-baseline.sql` then reads `-- testbench:complete tables=71`
rather than 70, because that marker counts the tables the dump actually contains. The
table count in `testbench.lock.json` is unaffected: it is written only at install time, by
`SchemaInventory::countTablesWithPrefix()`, which counts prefixed tables and cannot see this one.
And if the name is already taken on your side, the package refuses to work and says so directly
rather than silently losing the marker.

### What is rolled back and what is not

The list is exhaustive: anything not in it is not touched by testbench.

**Rolled back after every test:**

| What                                                                                                     | By what                                             |
| -------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| The database as a whole                                                                                  | the transaction, or restoring the baseline snapshot |
| `$modx->user` and the `$modx->config` keys touched by `setSetting()` and `PackageDefinition::settings()` | `TestCase::tearDown()`                              |
| The core file cache `core/cache/` (except `logs/`)                                                       | both isolation strategies                           |
| The MySQL connection session: `sql_mode`, `autocommit`, `foreign_key_checks`, `unique_checks`            | both strategies, in `begin()`                       |
| `$modx->error`                                                                                           | `TestCase::setUp()` — before the test, not after    |

**NOT rolled back — look after these yourself:**

| What                                                                                                                           | Why, and what to do                                                                                                                                                                                                                                                                                              |
| ------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Files installed by a transport package: `core/components/<extra>/`, `assets/components/<extra>/`, `core/packages/<signature>/` | The snapshot returns the database, not the disk. After a test with `TransportInstaller` the database says "not installed" while the disk says "installed". Install the transport once per run (FR-PKG-5), and if you need a clean disk — `vendor/bin/modx-testbench destroy` or `MODX_TESTBENCH_FORCE_INSTALL=1` |
| Rows written from another connection or from a subprocess                                                                      | They are not subject to the test's transaction in principle; see the callout about the detector above. The cure is `RefreshesDatabase`                                                                                                                                                                           |
| `$modx->services`, `$xpdo->packages`                                                                                           | They live until the end of the PHPUnit process (the docblock in `src/Package/PackageRegistrar.php:23-36`). Register a service in the test that needs it, and do not count on it being absent in the next one                                                                                                     |
| Other MySQL session variables (`time_zone`, `sql_safe_updates`, …)                                                             | The four listed above are restored. If your code changes anything else — restore it yourself                                                                                                                                                                                                                     |
| The core log `core/cache/logs/error.log`                                                                                       | The cache cleanup deliberately does not touch it: it is your primary diagnostic                                                                                                                                                                                                                                  |

Separately: `modX::deprecated()` writes notes about deprecated API from
`register_shutdown_function()`, that is, after the last `tearDown()` — past any isolation. In the test
environment the deprecated-API log is switched off: `log_deprecated = 0` is written to the database
right after the installation (so it gets into the baseline snapshot too) and is duplicated in memory
when the core is loaded, so rows do not trickle into `modx_deprecated_method`/`modx_deprecated_call` —
not even after `modX::reloadConfig()`, which re-reads the settings from the database.

> Only the INSTALLATION writes to the database. An environment deployed by an earlier version of the
> package will not reinstall itself — the configuration fingerprint is unaffected by this — and its
> database will keep `log_deprecated = 1`. The setting is switched off in memory anyway when the core
> is loaded, so no notes are written; but let some processor call `modX::reloadConfig()`, and the log
> is on again until the end of the run. To close this for good, reinstall the environment once:
> `MODX_TESTBENCH_FORCE_INSTALL=1 vendor/bin/modx-testbench install --force` — although, starting from
> installation revision 1, such an environment reinstalls itself: the revision is recorded in
> `testbench.lock.json`, and a lock from a previous revision makes the environment count as not
> installed.
>
> If you need to VERIFY that your code calls `deprecated()`, switch the setting on in the test itself —
> and remember that what is written at shutdown stays in the database.

## 5. Level 2 recipes

### Entities and assertions

```php
public function testJobBelongsToUser(): void
{
    $user = $this->createUser(['username' => 'importer']);
    $this->actingAs($user);

    $job = $this->modx->newObject(Job::class);
    $job->fromArray(['name' => 'nightly', 'user_id' => $user->get('id')]);

    self::assertTrue($job->save());
    $this->assertObjectExists(Job::class, ['name' => 'nightly']);
    $this->assertObjectMissing(Job::class, ['name' => 'weekly']);
}
```

The available factories: `createResource()`, `createUser()`, `createChunk()`, `createSnippet()`, `setSetting()`. Create other objects with the regular `newObject()`.

User profile fields (`email`, `fullname`, any other `modUserProfile` field) are given as the SECOND
argument of `createUser()`, not together with the `modUser` fields:

```php
$user = $this->createUser(
    ['username' => 'importer'],
    ['email' => 'importer@example.invalid']
);
```

In the first argument such an `email` simply vanishes: `modUser::fromArray()` ignores fields it does
not know, and the profile gets a generated address.

### Processors

An extra's processor is addressed by its **fully qualified class name**, not by a string such as `'mgr/job/create'`:

```php
use MyVendor\MyExtra\Processors\Job\Create;

public function testCreateProcessorValidatesName(): void
{
    $response = $this->runProcessor(Create::class, ['name' => '']);

    $this->assertProcessorFailure($response);
}

public function testCreateProcessorAcceptsValidInput(): void
{
    $response = $this->runProcessor(Create::class, ['name' => 'nightly']);

    $this->assertProcessorSuccess($response);
}
```

There are two assertions: `assertProcessorFailure()` and `assertProcessorSuccess()`.

> **A string action for an extra's processor requires a third argument — otherwise a refusal looks
> like a passing test.**
> `modX::runProcessor()` resolves a string either into a core class `MODX\Revolution\Processors\…`
> or into the file `{processors_path}/{action}.class.php`, where `processors_path` defaults to the
> **core** one (`core/src/Revolution/modX.php:1809-1823`). Your own directory is given through
> `$options['processors_path']`, and `InteractsWithModx::runProcessor()` accepts it as the third
> argument:
>
> ```php
> $response = $this->runProcessor(
>     'mgr/job/create',
>     ['name' => 'nightly'],
>     ['processors_path' => dirname(__DIR__, 2) . '/processors/']
> );
> ```
>
> WITHOUT that argument the extra's processor is never found, and a processor that is not found
> returns `success => false` with the message `Requested processor not found`. That is why
> `assertProcessorFailure()` on a string action without a path turns green in a VACUUM: it passes on
> knowingly valid input too, because the extra's validation never ran at all. Verified by mutation on
> a real extra: the same assertion with valid input on `Create::class` turns red ("A processor error
> was expected, but it finished successfully."), while on `'mgr/job/create'` without a path it stays
> green. If you doubt the path was picked up, check the refusal message: for a processor that was not
> found it is always `Requested processor not found`.
>
> The class name (`Create::class`) remains the shortest form and needs no path. The third argument is
> needed exactly when the processor is addressed by a STRING. One more case is a processor that exists
> only as a `*.class.php` file with a class in the global namespace that nothing autoloads:
> `modX::runProcessor()` checks `class_exists()` first (`modX::isProcessorClass()`,
> `core/src/Revolution/modX.php:1865-1868`), so such a processor can either be addressed by a string
> with `processors_path`, or be included by you:
>
> ```php
> require_once dirname(__DIR__) . '/processors/mgr/job/legacy.class.php';
>
> $response = $this->runProcessor(\TbLegacyJobCreateProcessor::class, ['name' => 'nightly']);
> ```
>
> Name the class explicitly rather than taking it from the return of `require_once`: the file returns
> its own class name only on the first inclusion, and in the second test of the same run `require_once`
> returns `true`. Verified by running it on such a processor: the successful path works, and a refusal
> arrives for the real reason (the `name` field) rather than as `Requested processor not found`.

> **`$modx->error` is reset for you — but only at the test boundary.** There is one core per process,
> and the error service lives just as long: neither a transaction nor a snapshot rolls it back, while
> `modX::runProcessor()` builds the `ProcessorResponse` out of the accumulated `$modx->error`
> (`core/src/Revolution/Error/modError.php`). That is why `TestCase::setUp()` calls
> `$modx->error->reset()` before every test: you will not get someone else's errors in your test any
> more.
>
> Inside ONE test the reset is still yours: if a test first checks that a processor refuses and then
> that it succeeds, `$this->modx->error->reset()` is needed between them. Otherwise the second call
> receives the errors of the first. Whether that makes `isError() === true` depends on the processor:
> `ProcessorResponse::isError()` reads only `response['success']`, so the call is turned red only by a
> processor guarded by `Processor::hasErrors()` (`modError::hasError()`) — that is how the core
> processors are built. A processor WITHOUT such a guard returns `success => true`, and the foreign
> errors travel unnoticed inside the raw response — which is worse, not better.

### System settings

```php
public function testChunkSizeIsRespected(): void
{
    $this->setSetting('myextra_chunk_size', 100);

    $this->assertSettingEquals('myextra_chunk_size', '100');
    self::assertSame('100', $this->modx->getOption('myextra_chunk_size'));
}
```

`setSetting()` writes both to the database and to `$modx->config`, so the value is immediately visible through `getOption()`.

### Events

```php
public function testEventIsInvokedOnResourceSave(): void
{
    $this->createResource(['pagetitle' => 'trigger']);

    // On a clean test core the event has no active plugins, so invokeEvent()
    // returns false rather than an array.
    self::assertFalse($this->triggerEvent('OnDocFormSave', ['mode' => 'new']));
}
```

`triggerEvent()` returns `array|bool`, because that is the contract of `modX::invokeEvent()`: an array
of plugin results only if the event is known to the core AND has **active** plugins; `false` in every
other case. The test core is installed clean and has no plugins, so the habitual
`assertIsArray($results)` fails here with "Failed asserting that false is of type array" (verified by
running it on a real extra). An array appears only after the extra's plugin is really installed — that
is, in a test that installs the transport package (see below) and therefore already lives under
`RefreshesDatabase`.

### Transport package

```php
use MODX\Revolution\modNamespace;
use ModxKit\Testbench\Concerns\RefreshesDatabase;
use ModxKit\Testbench\Package\TransportInstaller;

final class InstallationTest extends TestCase
{
    use RefreshesDatabase;

    public function testPackageInstallsCleanly(): void
    {
        (new TransportInstaller($this->modx))
            ->buildAndInstall(dirname(__DIR__, 2) . '/_build/build.transport.php');

        $this->assertObjectExists(modNamespace::class, ['name' => 'myextra']);
    }
}
```

Building a transport takes seconds, so keep such checks in a separate test class rather than in the general run.

## 6. Level 1 recipes

```php
use ModxKit\Testbench\Unit\UnitTestCase;

final class PriceFormatterTest extends UnitTestCase
{
    protected function stubOptions(): array
    {
        return ['myextra_currency' => 'RUB'];
    }

    public function testLogsWarningOnNegativePrice(): void
    {
        (new PriceFormatter($this->modx))->format(-1.0);

        $this->assertLogged('negative price');
        $this->assertLexiconUsed('myextra.price_error');
    }

    public function testEmitsEventAfterFormatting(): void
    {
        (new PriceFormatter($this->modx))->format(100.0);

        $this->assertEventInvoked('OnMyExtraPriceFormatted');
    }
}
```

The stub's `getOption()` is a port of `xPDO::getOption()`: it reads `$modx->config` (which is where
`setOption()` and `stubOptions()` both put things), understands a one-off set of values as the second
parameter, `$skipEmpty` as the fourth, and an array of keys instead of a single one. `invokeEvent()`
records the call (which is what `assertEventInvoked()` checks) and returns `false` — that is how the
core answers an event with no active plugins, and the stub has none for any event.

Objects prepared in advance are handed to the stub through `seed()`. That is **any** object, not
necessarily an `xPDOObject`: criteria are matched against its `toArray()`, and if there is no such
method — against its fields, including private ones (a double with a `private $id` and an accessor
works like any other).

```php
$job = new class {
    public string $name = 'nightly';

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name];
    }
};

$this->modx->seed(Job::class, $job);

self::assertSame($job, $this->modx->getObject(Job::class, ['name' => 'nightly']));
```

At level 1 a criterion is an array of fields **or a scalar**. The stub understands a scalar the way
the core does: as the primary key (`xPDO::sanitizePKCriteria()`). The core takes the key name from the
class map, which the stub does not have, so level 1 works by the `xPDOSimpleObject` convention — the
primary key is called `id`:

```php
// $job->toArray() === ['id' => 7, 'name' => 'nightly']
$this->modx->seed(Job::class, $job);

self::assertSame($job, $this->modx->getObject(Job::class, 7));
self::assertNull($this->modx->getObject(Job::class, 999999));
```

Address a model with a different primary key (for `modSystemSetting` it is `key`) with an explicit
array: `getObject(modSystemSetting::class, ['key' => 'myextra.limit'])`.

The stub reproduces two edge branches after the core literally: `getObject()` with no criterion at all
returns `null` even when the store is not empty, and `getCollection()` with no criterion returns
everything that was seeded. Level 1 does not accept a query object (`xPDOQuery`, `xPDOCriteria`) at
all: the stub has nothing to build SQL from, so it refuses with `UnsupportedStubOperationException`
rather than silently searching "with no criteria". Both behaviours are compared against the live core
in the package's own test `tests/Integration/Stubs/StubCoreParityTest.php`.

Do not try to seed a REAL model object: `new Job()` fails with `ArgumentCountError` (the `xPDOObject`
constructor requires `$xpdo`), and `new Job($this->modx)` never reaches the stub's store at all. Here
is what you will see — measured, not predicted (locked in by the test
`tests/Unit/Stubs/TestbenchModxTest.php::testSeedingARealModelObjectWarnsTwiceAndThenRefuses`):

```
Warning: Undefined array key "dbname"
Warning: Undefined array key "dbtype"
ModxKit\Testbench\Exception\UnsupportedStubOperationException:
    The core stub does not support getFields(). …
```

The `xPDOObject` constructor (`xPDOObject.php:618-640`) first reads `config['dbname']` and
`config['dbtype']` — the stub has neither, and `stubOptions()` does not supply them: it has no
database connection, and a made-up `dbtype` would lead the core into building SQL for a driver that
does not exist. Only then does the same constructor read the class map (`$xpdo->getFields()`) — and
that is where the package's exception arrives. `failOnWarning="true"` does not change that order: the
PHPUnit error handler publishes an event and returns control — its `__invoke()` is declared `: false`
(`Runner/ErrorHandler.php:109`, the `E_WARNING` and `E_USER_WARNING` branches at `:166` and `:178`,
`return false` at `:234`) — and the single level it throws on is `E_USER_ERROR` (`:228`,
PHPUnit 12.5.33). So you get BOTH warnings AND the exception, and the test ends in an error — verified
by running it with `failOnWarning="true"`: `Tests: 1, Errors: 1, Warnings: 2`.

Previously the object was constructed in full and the failure came later and with a foreign type — a
`TypeError` from the package's internals on a `toArray()` that returned `null`. The same package
exception — now with the class name and with what `toArray()` returned instead of an array — is given
by any double whose `toArray()` returned something other than an array. Level 1 does not need a real
model object anyway: it exists for the code around the model, not for the model itself — that is
checked by level 2.

Criteria are matched NON-strictly (`['id' => 1]` finds a stored `'1'`: values come from the database
as strings) — except for `null`. `null` matches only `null`, so `['deleted_at' => null]` means "the
field is not filled in" and does not fire on a stored `0` or an empty string.

### The price of `seed()`: static analysis is no safety net here

`seed()` accepts **any** object under any `class-string` — therein lies the whole convenience of
level 1. There is exactly one price for it, and it is worth knowing in advance:
`getObject()`/`getCollection()` are declared as `@return T|null`, where `T of xPDOObject` (without
that, code under PHPStan max could not call `get()` on the result at all), so the analyser believes
the object found has the whole `xPDOObject` API — regardless of what is really in the store:

```php
$this->modx->seed(Job::class, new class {
    public string $name = 'nightly';
});

$job = $this->modx->getObject(Job::class, ['name' => 'nightly']);

$job->get('name'); // PHPStan is silent; at runtime — Error: Call to undefined method …::get()
```

The practice is simple: give the double the methods the code under test uses (`get()`, `toArray()`)
rather than relying on the analyser's silence. That price could only be removed by forbidding `seed()`
to accept anything but an `xPDOObject` — that is, by taking away level 1's main convenience: writing a
real model object in order to check the code AROUND the model is neither needed nor sensible.

The boundary of level 1 runs like this:

- **The stub can do**: `getOption()`/`setOption()`, `log()`, `lexicon()`, `invokeEvent()`,
  `getObject()`/`getCollection()` over the seeded objects, the `$modx->services` container and the
  `$modx->error` collector (a real `modError`: it works without a database too, so
  `$modx->error->hasError()` in the extra's code does not fail).
- **The stub honestly refuses** with `UnsupportedStubOperationException` where it is easiest to reach
  out of habit: `newObject()`, `runProcessor()`, `newQuery()`, `getCount()`, `getIterator()`,
  `getObjectGraph()`, `getCollectionGraph()`, `removeCollection()`, `removeObject()`,
  `updateCollection()`, `getFields()`, `getPK()`, `getService()`, `getCacheManager()`.
  Processors are deliberately unavailable at level 1: the stub used to answer them with a plausible
  `success => false` ("Requested processor not found"), and a test with `assertProcessorFailure()`
  would turn green without executing a single line of the processor. Processors are checked by level 2.
- **The rest of the core is inherited as is.** The stub is a `modX` without a constructor, and a
  method that is in neither of the two lists above will really execute: without a connection and a
  class map it will most likely fail with an `\Error` from the core's internals. That is not a bug of
  the stub but the boundary of the level: do not try to stretch it into a full core — move the test to
  level 2.

## 7. Speed

| Technique                                                                                     | What it gives                                                      |
| --------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| Do not delete the workspace between runs                                                      | The installation happens once, after which the start takes seconds |
| `MODX_TESTBENCH_CORE_PATH` pointing at a local installation (`MODX_TESTBENCH_PROVIDER=local`) | Downloading the distribution is skipped                            |
| The release cache (`MODX_TESTBENCH_CACHE_DIR`) in CI                                          | The core archive is not downloaded on every run                    |
| `RefreshesDatabase` where it is needed, not globally                                          | Restoring the dump is not paid for where a transaction is enough   |
| Splitting the `unit` / `integration` suites                                                   | The fast development loop runs over the unit suite                 |

To rebuild the environment from scratch: `MODX_TESTBENCH_FORCE_INSTALL=1 vendor/bin/phpunit` or `vendor/bin/modx-testbench install --force`.

Two limitations of the `local` provider that are easy to trip over:

- the source must be the **root of a MODX installation** (containing `index.php` and `setup/`), not
  the `core/` directory. Level 1 appends `/core` to that path itself;
- there must be no symbolic links to directories inside the source. The provider refuses to copy such
  a source deliberately: `RecursiveIteratorIterator` does not descend into a link, and instead of the
  contents an empty directory would end up in the working directory — silently.

## 8. Diagnostics

All the package's exceptions live in `ModxKit\Testbench\Exception\` and inherit `TestbenchException`.
The directory holds 13 files: the base `TestbenchException`, 11 of its descendants and the
`SecretFreeMessage` marker interface. Every descendant is listed below:

| Exception                                     | What to check                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `CoreDownloadFailedException`                 | The value of `MODX_TESTBENCH_VERSION`, network access; if a corrupt archive is suspected, delete the cache directory (the exception text names it and lists the URLs that were tried)                                                                                                                                                                                                                                                                                                                                                      |
| `CoreTransportUnpackException`                | The integrity of `core.transport.zip`; if the file is suspected of having been replaced — delete `core/packages` and fetch the core again                                                                                                                                                                                                                                                                                                                                                                                                  |
| `InstallationFailedException`                 | The installer output in the exception text — it lists the environment checks that did not pass; the availability of the DBMS and the user's `CREATE` privilege                                                                                                                                                                                                                                                                                                                                                                             |
| `KernelBootFailedException`                   | The integrity of the working directory; recreate it (`MODX_TESTBENCH_FORCE_INSTALL=1` or `bin/modx-testbench destroy`)                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `TransactionNotStartedException`              | Two causes. `create()` — the transaction **could not be opened**: is the DBMS container running, do `MODX_TESTBENCH_DB_*` match the real parameters. `guardTableUnusable()` — the bookkeeping table of the guard marker cannot be prepared: the name is taken by someone else's table, which needs renaming or deleting. This is NOT `TransactionLostException` — there a transaction existed and was lost; here there was none at all                                                                                                     |
| `TransactionLostException`                    | Add `RefreshesDatabase` to this test class and recreate the environment: before the implicit commit the test had already committed data                                                                                                                                                                                                                                                                                                                                                                                                    |
| `SnapshotFailedException`                     | The database user's read and write privileges; whether `mysqldump` works, if it is being used                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `PackageRegistrationException`                | The paths in `PackageDefinition`, the presence of `metadata.<dbtype>.php` next to the model. Building/installing a transport has a text of its own: the build script, write permissions on `core/packages/`, the subprocess output                                                                                                                                                                                                                                                                                                         |
| `UnsupportedStubOperationException`           | The test has outgrown the stub level — move it to `ModxKit\Testbench\TestCase`                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `WorkspaceLocationException`                  | The value of `MODX_TESTBENCH_WORKSPACE`: the filesystem root cannot be the working directory — name a separate directory or do not set the variable at all                                                                                                                                                                                                                                                                                                                                                                                 |
| `WorkspaceOwnershipException`                 | Two causes. `notOurs()` — the directory was not created by the package (there is no ownership marker and no `testbench.lock.json`), the deletion is cancelled: point `MODX_TESTBENCH_WORKSPACE` at an empty or non-existent directory. `cannotMark()` — the ownership marker could not be written: write permissions on the directory. The path in the message may be the **sibling** `<workspace>.new` rather than the value you set: a replacement core is staged there before it replaces the environment, and the same guard covers it |
| `TestbenchException` (the base one, directly) | General refusals: a factory object did not save (`createUser()`/`createResource()`/…), no core was found for level 1, a path is unreadable. The text always names both the cause and the next action                                                                                                                                                                                                                                                                                                                                       |

There is no `DatabaseUnavailableException` class in the package — an unreachable DBMS arrives as
`InstallationFailedException` (during installation), `TransactionNotStartedException` (at the start of
a test) or the base `TestbenchException` (on the bookkeeping connections of the cleaner and of the
schema inventory). The text names the host, the port, the user and the `MODX_TESTBENCH_DB_*` variables.

The current state of the environment can always be inspected: `vendor/bin/modx-testbench status`.

## 9. Wiring up CI

```yaml
name: tests

on: [push, pull_request]

jobs:
  testbench:
    uses: modxkit/testbench/.github/workflows/testbench.yml@v1
    with:
      php-versions: '["8.2","8.3","8.4"]'
      modx-versions: '["3.1.2-pl","3.2.3-pl"]'
      working-directory: core/components/myextra
```

The workflow runs `composer test` on your side, so those scripts **must** be in your
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
suites listed above. Measured on an extra whose own suite holds 1879 tests: after the block was
copied literally, `composer test` ran 4. Keep your own entry point and compose instead, naming the
suite you already have:

```json
"scripts": {
    "test": ["@test:default", "@test:unit", "@test:integration"],
    "test:default": "phpunit --testsuite default",
    "test:unit": "phpunit --testsuite unit",
    "test:integration": "phpunit --testsuite integration"
}
```

Here `default` is the name of your existing suite in `phpunit.xml`, whatever it is; `test:default`
is a name of your own choosing, and the package neither knows nor requires it. The mandatory part is
`test`, `test:unit` and `test:integration` — the first because the workflow calls it, the other two
because the first refers to them.

Two separate processes are not decoration. A single `vendor/bin/phpunit` without `--testsuite` runs
both suites in ONE PHP process, and level 1 and level 2 load the MODX core by different paths: if the
paths diverge, the process dies with the fatal `Cannot redeclare class ComposerAutoloaderInit…`
(see `KernelBootstrapper::assertSingleCorePerProcess()`).

What the workflow does in addition:

- it installs the `mysql`/`mysqldump` clients on the runner. That step is not decorative and cannot be
  dropped with impunity: the snapshot strategy is chosen not by PATH but by the format the
  installation recorded in `testbench.lock.json`. An environment installed on a runner WITH the
  clients does not degrade to `PhpDumper` on a runner WITHOUT them — it REFUSES: the formats are not
  interchangeable, and the PHP fallback cannot parse a `mysqldump` dump containing a trigger. In
  practice that means: either the clients are present everywhere the suite runs, or they are present
  nowhere (then the installation picks `PhpDumper` and everything works — but views and triggers will
  not get into the snapshot). If the runner has already changed, the environment is recreated once:
  `MODX_TESTBENCH_FORCE_INSTALL=1 vendor/bin/modx-testbench install --force`. MariaDB clients will do
  just as well as Oracle ones. Under the old names (`mysql`, `mysqldump`) they occur as symlinks to
  `mariadb`/`mariadb-dump` — that is how the MariaDB 10.6 and 10.11 images and the
  `default-mysql-client` package in debian:stable (11.8.6) are built. On 12.3.2 those symlinks are
  gone (measured: `/usr/bin/mysqldump` is absent, only `mariadb-dump` is there), and where exactly
  between 11.8.6 and 12.3.2 they disappear was not measured. The package looks for both pairs of
  names: first `mysqldump`/`mysql`, then `mariadb-dump`/`mariadb` — and the full capture-and-restore
  cycle was measured on 10.6.28, 10.11.18 and 12.3.2. Previously a runner with only
  `mariadb`/`mariadb-dump` looked to the package like a runner with NO clients, and the snapshot was
  silently taken by the PHP fallback, losing views and triggers;
- it caches the downloaded MODX distribution keyed by the version (`MODX_TESTBENCH_CACHE_DIR`), so a
  second run on the same version is noticeably faster than the first.

## 10. FAQ

This section was assembled from the experience of wiring the package into a real extra.
Every item is not a guess but a reproduced run.

### `save()` returned `false` and did not say why

On failure `xPDOObject::save()` returns `false` and throws no exceptions. The real cause goes into the
core log of the **working directory**, not of your project:

```bash
vendor/bin/modx-testbench status          # the first table row is "Workspace directory"
tail -40 <environment-directory>/core/cache/logs/error.log
```

The most frequent cause on an extra's models is a `NOT NULL` column with no `default` in
`metadata.<dbtype>.php`: MySQL answers `Field '<name>' doesn't have a default value`, and such a field
has to be filled in explicitly in every test. A quick way to see the whole list:

```php
foreach (\YourExtra\Model\mysql\YourClass::$metaMap['fieldMeta'] as $field => $meta) {
    if (($meta['null'] ?? true) === false && !array_key_exists('default', $meta)) {
        echo $field, PHP_EOL;
    }
}
```

Run it **inside a level 2 test**, where the core autoloader is already registered. A bare `php -r`
over your project's own `vendor/autoload.php` will not do: the model class extends
`xPDO\Om\xPDOSimpleObject`, and without the core it dies with
`Class "xPDO\Om\xPDOSimpleObject" not found`. Both halves are measured on a real extra — the bare
run fails, the same loop inside a level 2 test prints the list.

### The tests are "green", but the database is dirty after the run

This no longer happens silently: transaction isolation checks the table engines before every test, and
the installation creates the database itself and makes sure the tables came out transactional. If you
do still see a dirty database after a run, check the table engines:

```bash
php -r '$p = new PDO("mysql:host=127.0.0.1;dbname=modx_testbench", "root", getenv("MODX_TESTBENCH_DB_PASS"));
foreach ($p->query("SELECT engine, COUNT(*) c FROM information_schema.tables
    WHERE table_schema = DATABASE() GROUP BY engine") as $row) {
    echo $row["ENGINE"], " ", $row["c"], PHP_EOL;
}'
```

(through PDO rather than through the `mysql` client: the machine may not have it — the package itself
can take snapshots without `mysqldump` too)

If the answer is `MyISAM` — there are no transactions there at all: `beginTransaction()` succeeds,
`rollBack()` succeeds, and the data stays; `PDO::inTransaction()` honestly answers `true` all the
while, because the server flag `SERVER_STATUS_IN_TRANS` remains set. That is exactly why the detector
looks not only at the flag but at the engine as well: `TransactionLostException` arrives before the
test body and names the specific tables.

Where MyISAM comes from: in new-installation mode the MODX 3 CLI installer calls
`verifyServerVersion()` BEFORE the target database exists (`checkDatabase()` in
`setup/includes/request/modinstallclirequest.class.php`). If the database is not there yet, there is
nothing to run `SELECT VERSION()` against, the version is read as empty, and MODX writes
`'override_table' => 'MyISAM'` into `core/config/config.inc.php` — after which **all** the core tables
are created as MyISAM.

Verified by a direct comparison on one and the same server (MySQL 8.4.11) and one and the same core
version (3.2.3-pl):

| State of the database before `modx-testbench install` | `config_options`               | Engine of the 70 tables | Transaction rolls back |
| ----------------------------------------------------- | ------------------------------ | ----------------------- | ---------------------- |
| the database is absent                                | `'override_table' => 'MyISAM'` | MyISAM                  | no (silently)          |
| the database was created in advance, empty            | empty                          | InnoDB                  | yes                    |

The package now creates the database before the installation itself (`CREATE DATABASE IF NOT EXISTS`
with the configured charset and collation) and after the installation checks that none of the prefixed
tables is non-transactional. So MyISAM in a fresh environment can mean only one thing: the tables came
from an EARLIER installation made before that check existed. Install the environment anew:

```bash
MODX_TESTBENCH_FORCE_INSTALL=1 vendor/bin/modx-testbench install --force
```

If the MyISAM tables belong to your extra and that is a deliberate choice — add `RefreshesDatabase`:
restoring a snapshot does not depend on the table engine.

### The first test fails with `TransactionLostException` while the rest pass

That is what an extra with tables of its own and without `RefreshesDatabase` looks like.
`createObjectContainer()` runs `CREATE TABLE` only if the table is not there yet, so the DDL (and the
implicit commit) happens in exactly the test that runs first after a clean database. The neighbouring
tests already find the table ready, pass — and leave committed rows behind them. The cure is section 3.

### `Requested processor not found` — although the processor is right there

The action is given as a string and the processors directory was not passed. A string resolves either
into a core class or into a file inside `processors_path`, and by default that path is the CORE one.
Pass your own directory as the third argument:

```php
$response = $this->runProcessor(
    'mgr/job/create',
    ['name' => 'nightly'],
    ['processors_path' => dirname(__DIR__, 2) . '/processors/']
);
```

The fully qualified class name (`Create::class`) is still the shortest form, and it needs no path:
section 5, "Processors".

Separately, review your "green" tests that use `assertProcessorFailure()` on string actions WITHOUT a
path: a processor that is not found returns `success => false`, so such an assertion passes having
checked nothing. The sign that the path was not picked up after all is that same
`Requested processor not found` message in the response; a genuine processor refusal looks different.

### The processor refuses on knowingly correct input

Most likely you are reading the errors of the previous call IN THE SAME test. `$modx->error` is a core
service, there is one core for the whole PHPUnit process, and `runProcessor()` assembles the
`ProcessorResponse` from the accumulated list of errors; neither a transaction nor a snapshot rolls
back the core's memory. At the test boundary the service is reset by `TestCase::setUp()`, so you will
not inherit ANOTHER test's errors — but your own, you will.

```php
$this->modx->error->reset();
$response = $this->runProcessor(\MyVendor\MyExtra\Processors\Job\Create::class, ['name' => 'nightly']);
```

The sign is that `getFieldErrors()` names fields that are not in your call at all. But that sign does
not always work: `getFieldErrors()` is filled only when the response is marked as an error, and that
is done only by a processor guarded by `Processor::hasErrors()`. For a processor without such a guard
the response arrives with `success => true`, and the foreign errors lie unnoticed in the raw
`$response->response`. If the processor behaves oddly while `getFieldErrors()` is empty — look at
`var_export($response->response, true)` and `$this->modx->error->hasError()`.

The same class of defect applies to `$modx->services` and `$xpdo->packages`: they too live until the
end of the run (the docblock in `src/Package/PackageRegistrar.php:23-36`). The full list of what is
rolled back and what is not is in the "What is rolled back and what is not" callout in section 4.

### `Command "test" is not defined`

The reusable workflow runs `composer test` on your side. The `test`, `test:unit` and
`test:integration` scripts must be in your `composer.json` — section 9.

### `vendor/bin/phpunit: No such file or directory`

Since the version in which PHPUnit moved into the package's `require`, it is installed together with
it. If `vendor/bin/phpunit` is still missing, the project holds an old `composer.lock`:
`composer update modxkit/testbench`.

### `vendor/bin/testbench: No such file or directory`

The binary is `vendor/bin/modx-testbench`; `vendor/bin/testbench` does not exist. The package is
named `modxkit/testbench`, but several names around it are spelled otherwise — the binary, the
`MODX_TESTBENCH_*` variables, the `modx-testbench` segment of the default cache and environment
paths, the default database `modx_testbench` and the `modx-testbench: ` that opens some of the
package's own warnings. `modxkit/testbench` is the address form and stands where something resolves
it: `require`, `vendor/modxkit/testbench/…` and the workflow's `uses:`; the namespace
`ModxKit\Testbench\` is the address in the spelling the autoloader resolves. The "Names" section of
[README.md](../README.md) lists both sides.

### `Cannot redeclare class ComposerAutoloaderInit…`

Both suites were run in a single process. Run `--testsuite unit` and `--testsuite integration`
separately — section 9.

### How to find out where testbench put everything

```bash
vendor/bin/modx-testbench status
```

Prints the environment directory, the MODX version, the provider, the table prefix, the connection
parameters (the password as `***`) and the path to the baseline snapshot. That is also where
`core/cache/logs/error.log` lives and where to look on any "MODX rejected the write".

# AGENTS.md — writing MODX 3 tests with testbench

**Which of the two are you?** If you are writing tests for an extra that *uses* this package, read
on. If you are changing this package itself, this file is not for you —
[CONTRIBUTING.md](https://github.com/modxkit/testbench/blob/main/CONTRIBUTING.md) is.

Everything here is short on purpose and points at the full text rather than repeating it. The
authoritative documents are the
[DX guide](https://github.com/modxkit/testbench/blob/main/docs/DX_GUIDE.md) and the
[spec](https://github.com/modxkit/testbench/blob/main/docs/SPEC.md); they are not shipped into
`vendor/`, so follow the links rather than looking for the files next to this one.

## Before the first test

Ask the environment what it thinks, rather than assuming:

```bash
vendor/bin/modx-testbench status
```

It prints where the environment is, whether it is installed and what it was installed with. The
other commands are `install`, `destroy` and `snapshot`.

Level 2 needs a DBMS. A container is shipped with the package:

```bash
docker compose -f vendor/modxkit/testbench/ci/docker-compose.yml up -d
export MODX_TESTBENCH_DB_HOST=127.0.0.1
export MODX_TESTBENCH_DB_USER=root
export MODX_TESTBENCH_DB_PASS=testbench
```

**Give this project a database of its own, once, and never think about it again:**

```bash
export MODX_TESTBENCH_DB_NAME=modx_testbench_<project>
```

Two projects that both leave this variable alone share one database, one `modx_` table prefix and
one environment directory — the fingerprint that names the environment is built out of the DBMS
coordinates, and nothing in it tells one project from another. Since 1.3.0 the second run is refused
with `ConcurrentRunException` instead of quietly dropping the other one's tables, but the refusal is
the symptom; the database name is the cure. It also buys the project its own environment, installed
once. See "Two projects on one DBMS" in the DX guide.

If the suite runs through a Composer script, add `COMPOSER_PROCESS_TIMEOUT=0`: Composer kills a
child at 300 seconds, and installing the environment the first time takes longer.

## Choose the level before you choose the assertions

| What is under test                                                                          | Level                   | Base class                            |
| ------------------------------------------------------------------------------------------- | ----------------------- | ------------------------------------- |
| Calculations, validation, formatting, DTOs                                                  | 1                       | `ModxKit\Testbench\Unit\UnitTestCase` |
| Code that reads settings, fires events, writes to the log — but does not touch the database | 1                       | `ModxKit\Testbench\Unit\UnitTestCase` |
| xPDO models, the schema, migrations                                                         | 2                       | `ModxKit\Testbench\TestCase`          |
| Processors, plugins, permissions, settings stored in the database                           | 2                       | `ModxKit\Testbench\TestCase`          |
| Installing a transport package                                                              | 2 + `RefreshesDatabase` | `ModxKit\Testbench\TestCase`          |

Start at level 1 and go up only when the check loses its meaning without a real database. Level 1
needs no DBMS and is orders of magnitude faster.

**Do not mock `modX`.** Level 1 already hands you `$this->modx` — a `TestbenchModx` that inherits the
real `MODX\Revolution\modX`. A hand-rolled mock of the core tests your mock, and this one comes with
`assertEventInvoked()`, `assertLogged()`, `assertLexiconUsed()` and `stubOptions()` for the options
the code under test reads.

## The five that bite hardest

**1. DDL breaks the test transaction.** Isolation is a transaction rolled back after every test. In
MySQL a `CREATE TABLE` commits implicitly, so a test that creates tables — anything with
`->tables(...)` in its `PackageDefinition` — must `use ModxKit\Testbench\Concerns\RefreshesDatabase`.
`TransactionLostException` means exactly this and nothing else. DX guide, section 4. It is not free:
it restores the baseline after **every** test of the class — 70 `CREATE TABLE` on a stock MODX,
0.7 s at best and several seconds against a DBMS configured for durability. Put it on the classes
that need it, never on a base class for the whole suite, and bring up the DBMS with the package's
own `ci/docker-compose.yml`.

**2. Permissions are not checked unless you ask.** `modX::checkPolicy()` evaluates a policy only with
an initialised session, and under PHPUnit there is none: without help it answers "allowed" to
everybody, including for a permission that does not exist. Call `enforcePermissions()` in the test
that is about access, and only there. The default is unchanged for every other test.

**3. Group membership needs the right role.** `joinUserGroup($user, $group)` defaults the role to
`Super User` deliberately: `Member` has authority 9999 and satisfies no context ACL of a default
install, so a membership under it grants nothing and the test goes green for the wrong reason. The
group and the role are looked up by name and never created — an unknown name is refused with the
names that do exist.

**4. MODX 3.0.x is not supported.** Its core does not come up properly in API mode. `3.1.2-pl` and
`3.2.3-pl` are the lines that are checked.

**5. `save()` returning `false` says nothing by itself.** The reason is in the log, and the DX guide's
FAQ has the recipe. The factory helpers below already refuse loudly instead of returning `false`.

## What you get in a level 2 test

From `ModxKit\Testbench\Concerns\InteractsWithModx` (already used by `TestCase`):

- fixtures — `createResource()`, `createUser()`, `createChunk()`, `createSnippet()`, `setSetting()`
- identity and access — `actingAs()`, `joinUserGroup()`, `enforcePermissions()`
- exercising the core — `runProcessor()`, `triggerEvent()`
- assertions — `assertObjectExists()`, `assertObjectMissing()`, `assertProcessorSuccess()`,
  `assertProcessorFailure()`, `assertSettingEquals()`

Everything they write is rolled back by the default isolation. Reach for a raw `$this->modx` only
when no helper fits.

## Declaring the extra

`PackageDefinition` is the one place that tells testbench what your package is; `packageDefinition()`
is called before every test:

```php
protected function packageDefinition(): PackageDefinition
{
    $core = dirname(__DIR__) . '/';

    return PackageDefinition::make('myextra')
        ->corePath($core)
        ->model('MyVendor\\MyExtra\\Model', $core . 'src/', 'mex_', 'MyVendor\\MyExtra\\')
        ->tables(Job::class)
        ->settings(['myextra_chunk_size' => 500]);
}
```

`model()` mirrors `xPDO::addPackage($pkg, $path, $prefix, $namespacePrefix)`: `$path` is the PSR-4
root, not the directory of the model classes, and a `metadata.<dbtype>.php` must sit next to them.
The order of application is fixed: namespace → `addPackage()` → tables → settings → services.

## Do not

- **Do not mock the core.** See above.
- **Do not delete `~/.cache/modx-testbench/workspaces/`** to "start clean". Reinstalling costs
  minutes. `MODX_TESTBENCH_FORCE_INSTALL=1` exists for when you really do need a clean install.
- **Do not point `MODX_TESTBENCH_WORKSPACE` at a directory you care about.** A reinstall deletes that
  directory in full.
- **Do not assume `$_SESSION` exists.** There is no session under PHPUnit; code that reads one must
  cope, and a test that assumes one passes only in some orders.
- **Do not set `MODX_TESTBENCH_ALLOW_CONCURRENT=1` to make a refusal go away.** It removes the guard,
  not the collision. The database name is the answer unless several processes over one environment
  are what you actually meant.

## Before you report the work done

- Run the suite. "The tests should pass" is not a result; the runner's output is.
- Run it in another order too — `phpunit --order-by=random` and `--order-by=reverse`. A test that
  passes only in declaration order is a defect that will surface in someone else's CI, and in this
  package's own history that check has caught real ones.
- If a run is red, say what failed and quote it. Do not summarise a failure as a success.
- If something is impossible to check, say "not measured" rather than writing a plausible sentence
  about it.

## When something throws

Every exception of the package inherits `ModxKit\Testbench\Exception\TestbenchException`, and each
one names both the cause and the next action. The full table — which exception means what to check —
is in the DX guide, section 8 "Diagnostics".

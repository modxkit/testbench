# ADR-0001. Headless install instead of virtualising the core

- **Status:** Accepted
- **Date:** 2026-08-20

## Context

`orchestra/testbench` works because the Laravel kernel can be brought up in memory: the service container does not depend on a DBMS, and configuration is given as arrays. The natural wish is to repeat that for MODX 3 and get a "virtual modX" without an installation.

Reading the MODX 3.2.3 sources shows that this is impossible without forking the core:

- `modX::initialize()` calls `getCacheManager()`, `getConfig()`, `_initNamespaces()`, `_initContext()`, `_loadExtensionPackages()` in sequence (`core/src/Revolution/modX.php:592-604`) — every step reads data from tables.
- System settings, contexts, namespaces and events exist only as rows in the database; the code contains neither a default set nor a fixture.
- The file `core/config/config.inc.php` is created exclusively by the installer and is a mandatory entry point for `config.core.php` → `index.php`.

In other words, an "empty" MODX 3 core is a core without settings, without a context and without a namespace, in which almost nothing that needs testing works.

## Options considered

1. **A virtual core in memory.** Replace xPDO with an in-memory implementation and hardcode the default metadata. Rejected: it requires maintaining a copy of the core schema and defaults, which will drift with every minor MODX version. The maintenance cost is out of proportion to the benefit.
2. **A ready dump of an installed database in the package repository.** Rejected: the dump is tied to the core version and the table prefix, requires manual updating for every MODX release and does not exercise the installation process itself.
3. **An automatic non-interactive install of the real core.** Chosen.

## Decision

Testbench automates a real MODX 3 installation: it fetches the distribution, generates `config.xml`, runs `php setup/index.php --installmode=new --core_path=… --config=…` and loads the installed core in `MODX_API_MODE`. The details and the pitfalls are recorded in [MODX_HEADLESS_INSTALL.md](../MODX_HEADLESS_INSTALL.md).

## Consequences

**Positive.** Tests run against the real core — including the database schema, processors, events and permissions. A new MODX version is supported by changing one environment variable rather than by editing a copy of the metadata. The installation process itself ends up covered by tests as well.

**Negative.** A working MySQL/MariaDB server is required. The initial preparation of the environment takes tens of seconds — offset by the release cache, by unpacking the core in advance and by reusing the workspace across runs.

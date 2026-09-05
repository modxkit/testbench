# ADR-0002. No in-memory SQLite

- **Status:** Accepted
- **Date:** 2026-08-20

## Context

In-memory SQLite is the standard way to speed up integration tests in other ecosystems. Formally the ground is there: xPDO ships with an SQLite driver (`core/vendor/xpdo/xpdo/src/xPDO/Om/sqlite/`, `metadata.sqlite.php`), so the ORM layer is able to work on SQLite.

The problem is not the ORM but the installer and the core itself:

- The MODX 3 installer targets MySQL: it checks the server version, creates the database through `createSourceContainer()` with MySQL parameters (`setup/includes/request/modinstallclirequest.class.php:285-292`), and the sample configuration shipped with the distribution offers `mysql` only.
- The core transport package `core.transport.zip` contains data and a schema built for MySQL types.
- Extras across the ecosystem widely use MySQL-specific SQL in their schemas and queries; a test on SQLite would produce a falsely green build.

## Options considered

1. **In-memory SQLite for all tests.** Rejected: installing the core on SQLite is unsupportable, and the dialect divergence makes the results untrustworthy.
2. **SQLite as an optional second driver.** Rejected on YAGNI grounds: maintaining two branches of behaviour is expensive, and the SQLite variant has no real consumers in the MODX ecosystem.
3. **MySQL/MariaDB only.** Chosen.

## Decision

The integration level supports MySQL and MariaDB exclusively. Speed is achieved not by switching the DBMS but by reusing the installed environment, by transactional rollback and by baseline snapshots ([ADR-0003](0003-transactions-with-snapshot-fallback.md)).

The need for "tests with no database at all" is met not by SQLite but by the stub level ([ADR-0006](0006-two-testing-levels.md)), which is faster than any embedded DBMS.

## Consequences

**Positive.** One trustworthy dialect, no false positives, half as much code and half the testing matrix.

**Negative.** Integration tests require a database server — locally it is brought up from `ci/docker-compose.yml`, in CI it is taken as a service container.

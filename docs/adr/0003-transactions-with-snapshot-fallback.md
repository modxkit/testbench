# ADR-0003. Transactions with a snapshot fallback

- **Status:** Accepted
- **Date:** 2026-08-20

## Context

Tests must see the same initial state regardless of execution order. Reinstalling MODX before every test is ruled out by the time it takes, and cleaning tables by hand is unreliable: an extra creates related entities, and the core creates rows in logs, in the registry and in the cache.

xPDO exposes transactions directly on the `$modx` instance (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2474,2484,2594`), and rolling a transaction back is the fastest way to restore state.

The limitation: in MySQL, DDL causes an implicit commit, and MyISAM tables do not support transactions at all. A test that creates the extra's tables or installs a transport package loses the transaction — silently, without a single error. That is the worst possible scenario: isolation disappears while the tests keep "passing", until they start failing in a random order.

## Options considered

1. **Transactions only.** Fast, but breaks silently on DDL. Rejected as a source of hard-to-catch flaky failures.
2. **Snapshot restore only.** Reliable and always works, but adds noticeable time to every test class. As the single strategy it is prohibitively expensive.
3. **Transactions by default + an explicit snapshot fallback + a lost-transaction detector.** Chosen.

## Decision

- The default strategy is the `TransactionIsolation` class, chosen by the `TestCase::isolationStrategy()` method: `beginTransaction()` in `setUp()`, `rollBack()` in `tearDown()`.
- `tearDown()` checks that the transaction is still active. If it is gone, the test fails with `TransactionLostException` and a direct hint to switch to `RefreshesDatabase`. A silent loss of isolation is unacceptable.
- The `RefreshesDatabase` trait restores the baseline snapshot taken right after the core was installed.
- Snapshots can work without `mysqldump`: the PHP fallback builds the dump through `SHOW TABLES`, `SHOW CREATE TABLE` and a chunked `SELECT`, because minimal CI images often lack the utility.

## Consequences

**Positive.** An ordinary test pays a fraction of a millisecond for isolation. Tests that work with the schema are isolated correctly. The wrong choice of strategy is discovered immediately and with a clear explanation, rather than as a flaky failure a week later.

**Negative.** Two strategies instead of one — a developer needs to understand when each is needed; that is described in `DX_GUIDE.md`. The baseline snapshot takes space in the workspace.

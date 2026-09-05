# ADR-0006. Two independent testing levels

- **Status:** Accepted
- **Date:** 2026-08-20

## Context

The integration level requires a DBMS and an installed core. But a substantial part of an extra's code — calculations, validation, formatting, DTOs, state machines — needs neither. Running it through the full environment means paying for the environment setup where the benefit is zero.

Moving CMS dependencies behind interfaces is the right pattern, and the package should support it rather than break it.

The difficulty is that some code is still typed against `MODX\Revolution\modX`, and an arbitrary stub cannot be passed there.

## Options considered

1. **The integration level only.** Rejected: it forces you to pay seconds for tests that need milliseconds, and it nudges you towards testing pure logic through the database.
2. **Stubs only.** Rejected: it does not exercise xPDO schemas, processors and events — that is, exactly what breaks most often in extras.
3. **Two independent levels.** Chosen.

## Decision

- **Level 1 (`Unit\UnitTestCase`)** — no database and no installation. The `Stubs\TestbenchModx` stub is created from the real `modX` class through `ReflectionClass::newInstanceWithoutConstructor()`: the core constructor is not executed and no connection is opened, yet `instanceof modX` holds and the stub can be passed into typed code. Core classes are taken from the downloaded distribution through `Support\CoreAutoloader` — the files are needed, the installation is not.
- **Level 2 (`TestCase`)** — the real core and a real database.
- The levels are independent: level 1 loads not a single level 2 class. The invariant is checked by a separate CI job that runs the unit suite with the DBMS switched off.
- Any stub operation we deliberately did not implement throws `UnsupportedStubOperationException` with a recommendation to move to level 2 — instead of returning `null` and producing a mysterious error later.

## Consequences

**Positive.** Fast feedback where it is possible, and trustworthy verification where it is needed. The stubs do not drift away from the real core API, because they inherit from it.

**Negative.** Two sets of base classes to maintain. The stubs cover the core API partially — the boundary is explicit and visible thanks to the exception, but it will have to be widened as requests come in.

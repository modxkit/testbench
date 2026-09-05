# ADR-0004. Three core providers and a release cache

- **Status:** Accepted
- **Date:** 2026-08-20

## Context

A MODX distribution is needed in three fundamentally different situations:

- **CI and everyday development** — a specific published version is needed, reproducibly and fast.
- **Checking compatibility with a future core** — an unpublished branch of the `modx/revolution` repository is needed.
- **Debugging testbench itself** — a distribution already sitting on disk is needed, so as not to wait for a download.

Downloading a release takes seconds to tens of seconds and is fully determined by the version, which makes it an ideal candidate for caching across runs and projects.

## Options considered

1. **`composer create-project modx/revolution` only.** Rejected as the primary path: it pulls in its own dependency tree, is slower and adds one more layer of failure (the Packagist network, version conflicts) without a gain.
2. **Downloading a zip only.** Does not cover testing against a core branch.
3. **A provider interface with three implementations.** Chosen.

## Decision

```php
interface CoreProvider
{
    public function fingerprint(): string;
    public function provide(string $targetDir): CoreLocation;
}
```

- `ZipReleaseProvider` (the default) — downloads the release archive, caches it in `~/.cache/modx-testbench/releases/`, unpacks it into the workspace.
- `GitCloneProvider` — `git clone --depth=1 --branch=<ref>` plus `composer install --no-dev` inside `core/`.
- `LocalPathProvider` — copies the distribution from `MODX_TESTBENCH_CORE_PATH`. The original is never modified: tests have no right to damage a developer's working installation.

Each provider's `fingerprint()` takes part in the workspace hash, so changing the version or the branch automatically leads to a separate environment rather than to a run against the wrong core.

A corrupt archive in the cache is invalidated and re-downloaded exactly once — there are no endless retries.

## Consequences

**Positive.** A fast repeat start, support for tests against dev branches of the core, instant local debugging. The cache is shared by all projects on the machine.

**Negative.** Three ways of obtaining the core mean three sources of failure; each must raise a diagnosable exception (`CoreDownloadFailedException`, which names the version, the reason, the URLs it tried and the release cache path). `composer create-project` is deliberately not supported — should it be needed, it is added as a fourth implementation without changing the rest of the code.

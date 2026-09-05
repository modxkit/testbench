# Architecture Decision Record (ADR) log

Every entry records one decision: the context, the options considered, the choice and its consequences. Entries are immutable — an obsolete decision is not edited but marked with the status "Superseded" and a link to the new entry.

| №                                                    | Decision                                          | Status   |
| ---------------------------------------------------- | ------------------------------------------------- | -------- |
| [0001](0001-headless-install-over-virtual-kernel.md) | Headless install instead of virtualising the core | Accepted |
| [0002](0002-no-in-memory-sqlite.md)                  | No in-memory SQLite                               | Accepted |
| [0003](0003-transactions-with-snapshot-fallback.md)  | Transactions with a snapshot fallback             | Accepted |
| [0004](0004-core-providers-and-caching.md)           | Three core providers and a release cache          | Accepted |
| [0006](0006-two-testing-levels.md)                   | Two independent testing levels                    | Accepted |

`0005` and `0007` are absent by intent: both record how the package named itself — a decision about the project's own work, not about the product — and they are not part of the public log. Numbers are not reused, so the gap stays.

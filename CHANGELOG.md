# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.5.0 — 2026-09-20

### Added

- **Stale lock files are swept.** The guard added in 1.3.0 left a file per environment
  behind, and where configurations are one-off — a suite that mixes a throwaway database
  name into the fingerprint, for instance — the directory grew without bound: 91 files in
  a single day on the machine this package is developed on. A file that nobody holds and
  that nobody has touched for a week is now deleted by the next run that passes through
  the directory. Nothing is lost by it: the next run that needs the file creates it again.
  Both conditions are required — age alone would delete the file of a long-running run,
  and "nobody holds it" alone would churn the directory for every environment that happens
  to be idle this second.

### Changed

- **The shipped `ci/docker-compose.yml` stops paying for durability nobody uses.** The
  data directory of both services is now a tmpfs, the binary log and the doublewrite
  buffer are off and the log is no longer flushed on every commit. A test database is
  rebuilt constantly and outlives nothing on purpose, so those guarantees were bought and
  thrown away. Measured on this package's development machine (macOS, Docker Desktop,
  `mysql:8.0`, the 168 KB snapshot of a stock 3.2.3-pl, 70 tables verified after every
  restore): **3.4–4.1 s per restore before, 0.72–0.75 s after**. End to end this package's
  own integration suite went from **4 m 38 s to 2 m 08 s** over 194 tests, and the faster
  run additionally installed its environment from scratch — which is the other half of the
  check: MODX installs onto a tmpfs data directory and the suite is green on it. A report
  from a consumer measured the same effect on their own suite, 184 s down to 47 s over 43
  tests. Losing
  the data costs exactly one restore, and that is checked in code rather than assumed —
  `TestbenchKernel::databaseMatchesLock()` reloads the snapshot, which lives on disk in the
  workspace. The MariaDB service gets the same flags; that it starts and reports them
  applied is measured, the speed-up on it is not. Not measured on Linux either: the
  bottleneck on macOS is a virtualised filesystem, and the gap there is likely smaller.

- **The price of `RefreshesDatabase` is written down** in `README.md`, `README.ru.md`,
  `AGENTS.md` and the DX guide. The trait restores the baseline after every test of the
  class, and a stock MODX baseline is 70 `CREATE TABLE` against 28 rows of data — the DDL
  is what costs, not the volume. Documented by what it does but never by what it costs, it
  turned into minutes as a suite grew and looked like a regression of the package until
  somebody measured it.

### Fixed

- **Deleting a lock file can no longer hand one environment to two runs.** A run that
  opened a lock file a moment before a sweep deleted it would have taken the lock on a file
  with no name, while the next run created a new one and locked that — two holders of one
  environment, the exact failure the guard exists to prevent. Taking the lock now checks
  that the file it holds is still the file the path names, and retries if it is not. The
  defect was introduced together with the sweep and never shipped on its own.

## 1.4.0 — 2026-09-20

### Added

- **`AGENTS.md`, and it ships with the package.** Tests for MODX extras are now largely
  written by AI agents, and the things that cost them the most are not in the API: the
  level to start at, that the core is not to be mocked, that DDL breaks the test
  transaction, that permissions answer "allowed" to everybody until `enforcePermissions()`
  is called, and that a project wants a database of its own. The file is short and points
  at the DX guide rather than repeating it. It is listed in `.gitattributes` on purpose:
  an agent working in a consumer's project sees `vendor/modxkit/testbench/`, never this
  repository, and `docs/` is not shipped. Contributors are sent to `CONTRIBUTING.md` by
  its first line.

### Changed

- **The tables in the documentation are aligned again.** Rows added over the last releases
  were wider than the column they went into, and the tables of `README.md` and
  `docs/SPEC.md` had drifted out of shape. Formatting only: not a word of their content
  changed.

## 1.3.0 — 2026-09-20

### Added

- **A guard against two runs sharing one environment.** Two projects that both leave
  `MODX_TESTBENCH_DB_NAME` alone share more than a DBMS server: the environment fingerprint
  is built out of the DBMS coordinates and the admin account, so they get one database, one
  table prefix and one environment directory. Run at the same time, they dropped each
  other's tables — the installation removes everything with the prefix, and
  `RefreshesDatabase` reloads the snapshot over data another run is in the middle of using.
  The second run is now refused with `ConcurrentRunException`, and the message names the
  holder and both ways out: a database of its own, or the opt-out below. The hold lasts the
  whole run rather than the installation alone, because the damaging case is two runs that
  both find the environment ready; it is an open descriptor, so the operating system
  releases it — a run killed with `kill -9` leaves no stale lock behind. Subprocesses of the
  run itself are let through: the build script of a transport package boots the very same
  environment, and refusing it would break a documented feature. Where the guard cannot be
  taken at all, the run proceeds unguarded rather than failing. See "Two projects on one
  DBMS" in the DX guide.

- **`MODX_TESTBENCH_ALLOW_CONCURRENT=1`** turns the guard off, for the case where several
  processes over one environment are deliberate. It does not make such a run safe; it stops
  refusing it.

## 1.2.1 — 2026-09-20

**A reissue of 1.2.0 under a reachable commit. The only difference from 1.2.0 is this
changelog entry.**

After 1.2.0 was tagged, the two commits behind it were squashed into one and the tag was
recreated on the squashed commit. Packagist refuses to change the source reference of a
published stable version — by design, so that moving a tag cannot swap the contents of a
release people have already installed. Its metadata for 1.2.0 therefore still points at the
pre-squash commit, which is no longer reachable from any branch or tag here.

Nothing is broken today: the files are byte for byte the same, because squashing did not
change the tree. But an unreachable commit is not something to leave a release standing on.
1.2.1 is the same code under a reference that is reachable from `main`, which is the way
Packagist itself prescribes for publishing such a change.

Use `^1.2` and you get it automatically.

## 1.2.0 — 2026-09-20

### Added

- **`joinUserGroup()` on level 2 test cases.** Writing the "user who IS allowed" half of a
  permission test meant assembling a `modUserGroupMember` by hand and knowing that the
  role decides whether the membership grants anything at all. The helper looks the group
  and the role up by name, refuses an unknown name with the names that do exist instead of
  creating one, defaults the role to `Super User` because `Member` satisfies no context ACL
  of a default install, and reloads the user's access attributes so that a membership added
  after the first permission check counts at once.

## 1.1.0 — 2026-09-20

### Added

- **`enforcePermissions()` on level 2 test cases.** Until now no test could prove that a
  processor asks for the permission it ought to: outside an initialised session
  `modAccessibleObject::checkPolicy()` evaluates no policy and answers `true`, and under
  PHPUnit the session state is always `SESSION_STATE_UNAVAILABLE` because `XPDO_CLI_MODE`
  is true. A user belonging to no group was granted `save_document` — and a permission that
  exists nowhere just the same. After the call the same questions are answered from the
  database, and the mode is undone at the end of the test. See "Permissions" in
  [docs/DX_GUIDE.md](docs/DX_GUIDE.md), which also records the two MODX traps on the way to
  an authorised user: the role authority that decides whether a group membership grants
  anything, and `sudo`, which cannot be mass-assigned and proves less than it looks.

## 1.0.0 — 2026-09-05

First public release. Everything below is new — there is no earlier published version to
compare against.

### Added

- **A disposable MODX Revolution 3 environment, built automatically.** The package
  downloads a core release, installs it non-interactively, boots it in `MODX_API_MODE`
  and isolates state between tests. No manual step and no visit to the browser installer.
- **Two test levels.** Level 1 works with the database switched off and is backed by
  stubs; level 2 runs against a real MODX on MySQL or MariaDB.
- **Declarative registration of the extra under test** — models, processors, elements —
  plus base test classes, entity helpers and assertions on processors and events.
- **A console binary, `modx-testbench`,** with `install`, `status`, `snapshot` and
  `destroy`.
- **A reusable GitHub Actions workflow**, wired into a consumer's CI with a single
  `uses:` line.
- **`ci/docker-compose.yml`**, shipped in the distribution: MySQL 8.0 and MariaDB 10.11
  on separate ports so a working tree can be checked against both at once.
- Documentation: [README.md](README.md) and its Russian translation
  [README.ru.md](README.ru.md), an architectural specification, a guide for extra
  developers, a reference on the non-interactive installation of MODX 3, and an
  architecture decision log.

### Supported versions

- PHP 8.2, 8.3, 8.4.
- PHPUnit 10.5, 11, 12.
- MODX Revolution 3.1.2-pl and 3.2.3-pl are the versions checked in CI.
- MySQL 8 and MariaDB 10.11.

### Known limitations

- **MODX 3.0.x is not supported.** Its core does not boot fully in API mode:
  `modX::reloadConfig()` includes one and the same model file twice and kills the process
  with `Cannot redeclare class`, and before that `getOption('core_path')` returns `null`.
  The cause is inside the 3.0.x core and the package does not work around it.
- **MySQL and MariaDB only.** The MODX 3 installer is in practice tied to MySQL — see
  ADR-0002 in the decision log.
- Further limitations are listed under "Known limitations" in the specification and in the
  FAQ of the developer guide.

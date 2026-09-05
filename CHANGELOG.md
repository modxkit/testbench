# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

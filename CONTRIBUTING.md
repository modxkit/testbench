# Contributing

Thank you for considering a contribution. This document is about working on the package
itself; if you are using it to test your own extra, [README.md](README.md) and
`docs/DX_GUIDE.md` are the documents you want.

## What you need

- **PHP 8.2–8.4** with the `json`, `mbstring`, `pdo`, `pdo_mysql` and `zip` extensions
  (declared in `composer.json`), plus `posix` for the test suite (`require-dev`).
- **MySQL 8 or MariaDB 10.11** — for the integration suite only. The unit suite runs with
  the database switched off.

## Running the suites

```bash
composer install
composer test:unit          # no database needed
```

For the integration suite, bring a DBMS up and tell the package where it is:

```bash
docker compose -f ci/docker-compose.yml up -d

export MODX_TESTBENCH_DB_HOST=127.0.0.1
export MODX_TESTBENCH_DB_PORT=3306
export MODX_TESTBENCH_DB_USER=root
export MODX_TESTBENCH_DB_PASS=testbench
export COMPOSER_PROCESS_TIMEOUT=0

composer test:integration
```

`ci/docker-compose.yml` starts two servers so that the same working tree can be checked
against both without shutting either down: **MySQL 8.0 on port 3306** and **MariaDB 10.11
on port 3307**, both `root` / `testbench`. Point `MODX_TESTBENCH_DB_PORT` at 3307 to run
against MariaDB — the port is part of the configuration fingerprint, so the two
environments do not overlap.

`COMPOSER_PROCESS_TIMEOUT=0` is not optional decoration: Composer kills a child process
after 300 seconds by default (`composer config process-timeout` → `300`), and the first
integration run downloads and installs a MODX core before the tests start
(`bootstrap.php`).

**Without the four database variables the integration suite produces an avalanche of
errors.** That is an unconfigured environment, not a finding.

## Before opening a pull request

```bash
composer qa
```

This runs, in order: `cs:check` (PHP-CS-Fixer, dry run), `rector:check` (dry run),
`phpstan` and both suites. CI runs the same tools.

**Test order must not change the result.** The suites are checked with
`--order-by=random` (two different `--random-order-seed` values) and `--order-by=reverse`.
A discrepancy between orders is a finding, not noise.

## Things that will cost you time if you do not know them

- **Inside this repository the binary is `bin/modx-testbench`, not
  `vendor/bin/modx-testbench`.** The latter does not exist here — it is the path the
  package has at a *consumer*, and that is the form the README recipes use.
- **Three test groups are excluded in `phpunit.xml`, not on the command line**:
  `mariadb-client`, `mysql-client-tools`, `mysql-user-table`. This is deliberate.
  `--exclude-group` *replaces* the XML exclusion list wholesale rather than adding to it,
  and it behaves differently across the three supported PHPUnit majors. The reasoning is
  written out in `phpunit.xml` itself.
- **A half-built environment survives an interrupted run** (Ctrl-C, a timeout). Rebuild it
  with `MODX_TESTBENCH_FORCE_INSTALL=1` or remove it with `bin/modx-testbench destroy`.
- **MODX 3.0.x is not supported and will not be made to work** — its core does not boot
  fully in API mode. The reasons are in [README.md](README.md), under "Requirements".
  3.1.2-pl and 3.2.3-pl are the versions that are checked.

## Conventions

- **Every claim is either measured or marked "NOT MEASURED".** This applies to code
  comments, commit messages and documentation alike. A plausible-sounding reason that
  nobody ran is worse than an honest gap.
- **A statement about the state of the filesystem, git or a server is derived by asking
  the system**, not by reading a source file or a config that is supposed to produce it.
- Documentation recipes are executable code: if you change one, run it as written.

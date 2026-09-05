<?php

declare(strict_types=1);

use ModxKit\Testbench\Bootstrap\BootstrapFailure;
use ModxKit\Testbench\Environment\TestbenchKernel;
use ModxKit\Testbench\Support\CoreAutoloader;

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
];

$autoloaded = false;

foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        $autoloaded = true;
        break;
    }
}

// Without this branch the loop failed silently, and what followed was WORSE than a merely
// confusing error: `TestbenchKernel::instance()` below died with `Error: Class … not found`, which
// is caught by the `catch (\Throwable)` in this same file — the bootstrap finished "successfully"
// without having prepared an environment. The consumer learnt about it only from their own failing
// tests.
if (!$autoloaded) {
    throw new RuntimeException(sprintf(
        'modx-testbench: the Composer autoloader was not found — run `composer install` in the '
        . 'project. Looked in: %s',
        implode(', ', $autoloadCandidates)
    ));
}

unset($autoloadCandidates, $candidate, $autoloaded);

// Wrapped in a closure rather than executed at the top level: this file is included as the PHPUnit
// `bootstrap`, so any top-level variable would settle into $GLOBALS for the whole process. For
// strings that was harmless, but `$workspace` is an object; a test declaring
// `#[PreserveGlobalState(true)]` (the default in PHPUnit 12 is `false`, but that is the choice of
// the individual test, not ours) would drag it through global-state serialisation into the child
// process for no reason at all.
(static function (): void {
    // The environment is prepared before the first test so that the install does not land in the
    // timing of an individual test, and the core autoloader is registered straight away against the
    // prepared workspace — see the explanation below and the caveat about try/catch.
    //
    // try/catch wraps ONLY prepare(), not the register() below. `prepare()` hashes the database
    // configuration into the fingerprint of the working directory
    // (TestbenchConfig::fingerprint()) — a CI that prepares the environment with one set of
    // MODX_TESTBENCH_DB_* and then runs the unit job with the database stopped and a different (or
    // absent) set of variables will get a DIFFERENT fingerprint, miss the already prepared
    // workspace and try to reinstall the environment right here — against a dead database, BEFORE
    // the first test, from the bootstrap, where there is nothing to catch that error with. We
    // swallow exactly THIS error quite deliberately: level 1 (Unit\UnitTestCase::setUp()) does not
    // see `CoreAutoloader::isRegistered() === true`, falls back to MODX_TESTBENCH_CORE_PATH and, if
    // that is missing too, throws its own diagnosable exception — while level 2 calls prepare()
    // again itself (TestbenchKernel::modx()) and gets the genuine cause where it is really needed:
    // TestbenchKernel::prepare() does not mark the preparation successful on failure
    // ($this->prepared stays false — TestbenchKernel.php), so nothing is swallowed for the level
    // that really does need the database.
    //
    // register() below is NOT inside the try: on the "already installed" path prepare() has by then
    // already set $this->prepared = true, so a register() failing here (for example a corrupted or
    // not-fully-unpacked core/vendor/autoload.php in an otherwise successfully prepared workspace)
    // would turn level 2's repeated prepare() into a silent no-op that never retries and never
    // shows the genuine cause — KernelBootstrapper::assertEnvironmentIsComplete() rescues only the
    // case of a MISSING file, with an explicit KernelBootFailedException, not of a corrupted
    // present one. Previously (before this narrowing) such an error would break the bootstrap off
    // at once, with a clear parse error naming the specific file — that behaviour is now preserved
    // deliberately.
    //
    // The `catch` branch no longer leaves the bootstrap empty-handed. `prepare()` also fails in the
    // scenario where the core files are ALREADY unpacked into the workspace and only the database
    // install step stumbled (a dead or switched-off DBMS) — and that is exactly what the level 1 CI
    // job looks like, the one docs/SPEC.md:31 declares an invariant: "level 1 … must work with the
    // database switched off". Level 1 does not need the database (docs/SPEC.md:27: "MODX install
    // required: no (only the core files are needed)"), but it does need the `modX`/`xPDO` classes —
    // without registering the autoloader the whole `UnitTestCase` fell into its own diagnosable
    // exception (src/Unit/UnitTestCase.php), and the job meant to prove the invariant could not be
    // green on any runner.
    //
    // Registering here is safe and does NOT dilute the reasoning above: a failed `prepare()` leaves
    // `$this->prepared === false` (TestbenchKernel::prepare() sets the flag only on the successful
    // path), so level 2 with its own `prepare()` call (TestbenchKernel::modx()) retries from
    // scratch and gets the genuine cause where the database really is needed. Exactly one thing
    // changes: level 1 stops being hostage to a step that does not concern it.
    //
    // `registerIfAvailable()` (not `register()`): the absence of a core on disk is a regular outcome
    // here (for example `prepare()` failed inside the provider without unpacking anything), and it
    // must stay quiet so that the `catch` does not become the source of a second, now uncaught
    // exception. A CORRUPTED autoloader still fails loudly — see
    // CoreAutoloader::registerIfAvailable().
    $kernel = null;

    try {
        $kernel = TestbenchKernel::instance();
        $workspace = $kernel->prepare();
    } catch (\Throwable $failure) {
        // The cause of the failure is kept, not erased. We swallow it deliberately (see above),
        // but `Unit\UnitTestCase::setUp()` will attach it as the `previous` of its own exception —
        // otherwise level 1 explains the failure with the single hypothesis "the core is not on
        // disk" and gives two pieces of advice, both wrong when in truth the DBMS did not come
        // up.
        BootstrapFailure::record($failure);

        // `$kernel === null` means `instance()` itself failed (parsing the configuration) — the
        // workspace is then not computed even hypothetically, and calling `instance()` again would
        // throw the same exception from inside the `catch`, where there is nothing to catch it
        // with.
        if ($kernel instanceof TestbenchKernel) {
            // `workspace()` is a pure getter of an already constructed object
            // (Workspace::forConfig() ran inside instance()); it touches neither disk nor database
            // and cannot fail.
            CoreAutoloader::registerIfAvailable($kernel->workspace()->corePath());
        }

        return;
    }

    // prepare() installs the core onto disk but does not load its classes — without this, level 1
    // (Unit\UnitTestCase) does not find modX/xPDO and demands MODX_TESTBENCH_CORE_PATH by hand. The
    // autoloader is registered here, STRAIGHT AWAY against the prepared workspace: then, if level 2
    // later really boots the core (KernelBootstrapper::boot() of the same $workspace), both loaders
    // require LITERALLY the same `vendor/autoload.php` file — the second `require_once` becomes a
    // no-op by path rather than a collision of the identically named class ComposerAutoloaderInit…
    // between two different copies of the distribution (see src/Bootstrap/KernelBootstrapper.php,
    // assertSingleCorePerProcess()).
    CoreAutoloader::register($workspace->corePath());

    // Nothing is reported from here about world-accessible files holding the password. The data is
    // ready (`$workspace->exposedSecretFiles()`), the channel exists — and yet it is not used;
    // below are the measurements this decision is derived from.
    //
    // 1. Printing indiscriminately breaks tests that run in a separate process: PHPUnit treats a
    //    non-empty child STDERR as an error (`PHPUnit\Framework\Exception`). With a shared
    //    environment at mode 0644 the unit suite gives 8 errors instead of a green run — measured
    //    with both ways of printing, `fwrite(STDERR, …)` and `trigger_error(…, E_USER_WARNING)`,
    //    with the same result. PHPUnit's error handler is no rescue: the isolated-process template
    //    removes its own handler BEFORE including the bootstrap and sets `display_errors=stderr`
    //    (`vendor/phpunit/phpunit/src/Framework/TestRunner/templates/method.tpl`).
    //
    // 2. There is a channel that works: `$exposed !== [] && ob_get_level() === 0`. Measured — the
    //    warning reaches STDERR and the run stays green (`exit=0`,
    //    `OK (226 tests, 653 assertions)`) at those same 0644 permissions.
    //
    // 3. That same channel also fails — measured too — and it fails SILENTLY. `ob_get_level()`
    //    answers the question "is somebody buffering my output", not "am I in an isolated PHPUnit
    //    process" — the coincidence of those two things is the entire mechanism. A consumer
    //    bootstrap starting with `ob_start()` extinguishes the warning completely and without a
    //    trace: measured with the same run through `--bootstrap` with a two-line wrapper — `exit=0`,
    //    STDERR empty.
    //
    // A mechanism that quietly stops working when external conditions change is worse than no
    // mechanism. No signal whose failure would be LOUD was found FROM INSIDE THIS FILE: the only
    // candidate here (recognising a child process by `PHP_SELF`) would, on a change of isolation
    // method in PHPUnit, break the consumer's suite with eight errors — and permissions in this
    // package are a protective measure, not a success criterion (on the same grounds the exit code
    // of `install` stays zero under loosened permissions, `src/Console/InstallCommand.php`).
    //
    // 5. That qualification is the whole of the correction: this block used to end with "no signal
    //    whose failure would be LOUD was found", full stop, and the sentence did not survive being
    //    measured. OUTSIDE the bootstrap such a signal exists. A PHPUnit extension
    //    (`ModxKit\Testbench\PHPUnit\ExposureWarningExtension`) is called once, in the RUNNER
    //    process, and the isolated-process template runs no extensions at all — so it cannot be
    //    repeated into a child's STDERR the way a warning from here is. Measured on a probe suite
    //    of two tests, one of them `#[RunInSeparateProcess]`: with the extension registered the
    //    warning appears exactly once and the run is `OK (2 tests, 2 assertions)`; with it not
    //    registered STDERR is 0 bytes (so the warning does come from the extension); and the same
    //    line written from HERE instead gives `Errors: 1` on the isolated test. Pinned by
    //    `ExposureWarningExtensionTest`. The extension is opt-in — a consumer who does not add the
    //    `<extensions>` line gets exactly what they got before.
    //
    // Channels that report this without the consumer opting in: the `bin/modx-testbench install`
    // warning (a live check of the files, honest on a repeated run over a ready environment), the
    // `E_USER_WARNING` from `ModxKit\Testbench\Support\FilePermissions` on a failed `chmod`, and the
    // `Password exposure` row of `bin/modx-testbench status` (`src/Console/StatusCommand.php`). The
    // first two are described in the README. The hole "the bootstrap prepared the environment,
    // nobody called a command" is closed by the extension of point 5 and by nothing else. The
    // silence here is pinned by the test
    // `BootstrapGuardTest::testBootstrapPrintsNothingEvenWhenSecretFilesAreExposed()`.
    //
    // 4. Moving the default directory narrowed the price of this silence but did not close the
    //    hole. The default environment directory moved under the user's private directory and is
    //    created with mode 0700 (`ModxKit\Testbench\Environment\Workspace`), and without the right to
    //    enter the directory the mode of a file inside plays no role — measured on debian:stable: an
    //    outside user gets `Permission denied` both on `cat` of a 0644 file and on listing a 0700
    //    directory. What remains is the case where `exposedSecretFiles()` still speaks of a real
    //    exposure: a directory set by `MODX_TESTBENCH_WORKSPACE` by hand — there the permissions on
    //    the directory are chosen by the consumer. Falling back to a world-accessible temporary
    //    directory (no `HOME`, no `XDG_CACHE_HOME`) does NOT belong here: it is measured that the
    //    whole path segment is created with mode 0700 in the fallback too, so in `/tmp` at mode 1777
    //    an outsider gets `Permission denied` already at `modx-testbench`.
    //
    //    About the directory name being readable by an outsider after all the bootstrap is NOT
    //    silent: `Workspace::ensureExists()` warns with `E_USER_WARNING`, and in isolated processes
    //    that costs the same eight errors. But that price is paid only for a measured fact — a run
    //    without `HOME` and without `XDG_CACHE_HOME` gives `Errors: 0`.
})();

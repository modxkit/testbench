<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Package;

use MODX\Revolution\modX;
use MODX\Revolution\Processors\ProcessorResponse;
use ModxKit\Testbench\Exception\PackageRegistrationException;
use ModxKit\Testbench\Support\CommandRunner;
use ModxKit\Testbench\Support\ProcessRunner;

/**
 * Builds an extra's transport package with its build script in a subprocess and installs it with
 * the regular MODX processors (`workspace/packages/scanlocal`, `workspace/packages/install`) — the
 * installation path by which an extra is really installed at the user's site, unlike the
 * declarative {@see PackageRegistrar}.
 */
final readonly class TransportInstaller
{
    public function __construct(
        private modX $modx,
        private CommandRunner $runner = new ProcessRunner(),
    ) {
    }

    public function buildAndInstall(string $buildScript): void
    {
        $this->install($this->build($buildScript));
    }

    /**
     * The build script runs in a SUBPROCESS with its own connection, while the install goes
     * through the regular MODX processors, which create tables (DDL). Neither obeys the test's
     * transaction: the subprocess in principle, the DDL because MySQL performs an implicit commit
     * on it. The `TransactionIsolation` detector does not see writes made on somebody else's
     * connection and cannot see them, so the refusal is placed here, at the entry point: better to
     * name the required trait before the install than to sort out the consequences afterwards.
     */
    private function assertRollbackIsPossible(string $subject): void
    {
        if ($this->modx->pdo === null || !$this->modx->pdo->inTransaction()) {
            return;
        }

        throw PackageRegistrationException::atTransportStep(
            'transport',
            $subject,
            'the test runs inside a transaction, and installing a transport package does not obey '
            . 'it: the build runs in a subprocess with its own connection, and the install processors '
            . 'create tables (DDL causes an implicit commit). Add the '
            . 'ModxKit\\Testbench\\Concerns\\RefreshesDatabase trait to the test — only a snapshot '
            . 'undoes such changes'
        );
    }

    /**
     * Runs the extra's build script in a subprocess and returns the path to the resulting zip.
     *
     * The package path is extracted from stdout by {@see self::extractPackagePath()} — not with a
     * plain `trim()` of the whole output and not as "the last non-empty line", but by searching
     * from the end for the first line that both ends in `.transport.zip` and exists on disk: PHP
     * may add warnings or `Deprecated` notices to the same stream both before and after the `echo`
     * of the path (in particular during shutdown, after the script has printed everything).
     */
    public function build(string $buildScript): string
    {
        $this->assertRollbackIsPossible($buildScript);

        if (!is_file($buildScript)) {
            throw PackageRegistrationException::atTransportStep('build', $buildScript, 'the build script was not found');
        }

        $result = $this->runner->run([PHP_BINARY, $buildScript], dirname($buildScript), 600);

        if (!$result->isSuccessful()) {
            throw PackageRegistrationException::atTransportStep('build', $buildScript, $result->output());
        }

        $packageFile = $this->extractPackagePath($result->stdout);

        if ($packageFile === '') {
            $rawOutput = trim($result->stdout);

            throw PackageRegistrationException::atTransportStep(
                'build',
                $buildScript,
                "the script returned no path to the built package (output: \"{$rawOutput}\")"
            );
        }

        return $packageFile;
    }

    public function install(string $packageFile): void
    {
        $this->assertRollbackIsPossible($packageFile);

        $signature = basename($packageFile, '.transport.zip');
        $targetDir = $this->corePath($signature) . 'packages/';

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
            throw PackageRegistrationException::atTransportStep(
                'install',
                $signature,
                "directory {$targetDir} does not exist and could not be created"
            );
        }

        $destination = $targetDir . basename($packageFile);

        // The existence of the source is checked BEFORE the `realpath()` comparison: for a
        // non-existent path `realpath()` always returns `false`, and if `$destination` does not
        // exist yet either, `false !== false` is `false`, the `&&` short-circuits, the copy is
        // silently skipped, and execution falls through into `scanlocal`/`install` with an
        // unrelated "Package not found." instead of a diagnosable refusal at this step.
        if (!is_file($packageFile)) {
            throw PackageRegistrationException::atTransportStep(
                'install',
                $signature,
                "package file {$packageFile} was not found"
            );
        }

        // `modPackageBuilder` builds the package straight into `<active workspace>/packages/`
        // (`core/src/Revolution/Transport/modPackageBuilder.php:79`), and by default the workspace
        // points at the same directory as the `core_path` system setting. When the built zip
        // already lies at the destination, `$packageFile` and `$destination` are one and the same
        // file, and in PHP a `copy()` of a source over itself is not a no-op: it returns `false`
        // (verified on PHP 8.4.8). Without this check a successful build would be falsely counted
        // as an install failure. The existence of the source is already confirmed above, so here
        // `realpath()` can return `false` only for a `$destination` that does not exist yet — the
        // comparison stays correct without an additional fallback.
        if (realpath($packageFile) !== realpath($destination) && !@copy($packageFile, $destination)) {
            throw PackageRegistrationException::atTransportStep(
                'install',
                $signature,
                "failed to copy the package into {$targetDir}"
            );
        }

        // The "ScanLocal" casing is mandatory here, and it is not cosmetics. `modX::runProcessor()`
        // derives the processor class name from the action by applying `ucfirst()` to every segment
        // (`core/src/Revolution/modX.php:1795-1801`): `…/scanlocal` yields `Scanlocal`, while the
        // core class is named `ScanLocal` — `ucfirst()` does not restore the capital "L" inside a
        // segment. PSR-4 then looks for a `Scanlocal.php` file that is not on disk: on macOS a
        // case-insensitive filesystem substitutes for it, on Linux nothing does, and MODX 3 has no
        // legacy `*.class.php` paths. The answer is "Requested processor not found", and installing
        // an extra does not work for any consumer on Linux (all eight integration jobs of the first
        // CI run). A `ScanLocal` class under that name is present in 3.0.5-pl, 3.1.2-pl and
        // 3.2.3-pl (measured; the 3.0.x line was declared unsupported afterwards, and CI checks
        // 3.1.2-pl and 3.2.3-pl).
        // The regression is caught by
        // {@see \ModxKit\Testbench\Tests\Integration\Package\ProcessorActionResolutionTest}.
        $scan = $this->modx->runProcessor('workspace/packages/ScanLocal');

        if ($scan instanceof ProcessorResponse && $scan->isError()) {
            throw PackageRegistrationException::atTransportStep('scanlocal', $signature, $scan->getMessage());
        }

        $response = $this->modx->runProcessor('workspace/packages/install', ['signature' => $signature]);

        if (!$response instanceof ProcessorResponse || $response->isError()) {
            throw PackageRegistrationException::atTransportStep(
                'install',
                $signature,
                $response instanceof ProcessorResponse ? $response->getMessage() : 'the processor returned no response'
            );
        }
    }

    /**
     * `xPDO::getOption()` is declared as `@return mixed` (`xPDO.php:711`) — PHPStan at the `max`
     * level considers casting `mixed` to a string potentially unsafe (the value could be an object
     * without `__toString()`), so the type is checked explicitly, and an empty value is caught along
     * the way: a booted core always returns a non-empty `core_path`, but without the check the code
     * would assemble a meaningless path such as `packages/` in the process root instead of a
     * diagnosable refusal.
     */
    private function corePath(string $signature): string
    {
        $corePath = $this->modx->getOption('core_path');

        if (!is_string($corePath) || $corePath === '') {
            throw PackageRegistrationException::atTransportStep(
                'install',
                $signature,
                'the core_path system setting is unavailable or empty'
            );
        }

        return $corePath;
    }

    /**
     * The build script prints the path with an `echo` (see the fixture's `build.transport.php`),
     * but PHP may add `Deprecated`/`Warning` notices to the same stream both before and AFTER it —
     * for example during shutdown, after the script has printed everything. "Take the last non-empty
     * line" without further checks would in that case return such a notice instead of the path. So
     * we scan the output lines from the end and take the first that both ends in `.transport.zip`
     * and exists on disk — the two conditions together cut off unrelated PHP diagnostics regardless
     * of whether they were printed before or after the path.
     */
    private function extractPackagePath(string $output): string
    {
        $lines = preg_split('/\R/', $output) ?: [];

        for ($i = count($lines) - 1; $i >= 0; --$i) {
            $line = trim($lines[$i]);

            if ($line !== '' && str_ends_with($line, '.transport.zip') && is_file($line)) {
                return $line;
            }
        }

        return '';
    }
}

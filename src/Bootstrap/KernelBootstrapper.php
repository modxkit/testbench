<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Bootstrap;

use Closure;
use MODX\Revolution\Error\modError;
use MODX\Revolution\modLexicon;
use MODX\Revolution\modX;
use MODX\Revolution\Services\Container;
use ModxKit\Testbench\Environment\Workspace;
use ModxKit\Testbench\Exception\KernelBootFailedException;
use ModxKit\Testbench\Support\CoreAutoloader;
use xPDO\xPDO;

/**
 * Boots an installed MODX core into the current PHP process in API mode.
 *
 * The entry point is the distribution's `index.php`: with a true `MODX_API_MODE` it creates a
 * `modX`, initialises the `web` context and does not call `handleRequest()` (index.php:14-17, 39-64
 * of the 3.2.3-pl distribution).
 *
 * @internal
 */
final readonly class KernelBootstrapper
{
    /** How many error handlers we remove at most, so that the restoration does not loop. */
    private const MAX_ERROR_HANDLER_DEPTH = 8;

    /** @var Closure(): mixed */
    private Closure $resolveKernel;

    /**
     * @param (Closure(): mixed)|null $resolveKernel How to obtain the booted core; by default
     *                                               `modX::getInstance()`.
     */
    public function __construct(?Closure $resolveKernel = null)
    {
        $this->resolveKernel = $resolveKernel ?? static fn (): mixed => modX::getInstance();
    }

    public function boot(Workspace $workspace): modX
    {
        $this->assertEnvironmentIsComplete($workspace);
        $this->enableApiMode($workspace);
        $this->assertSingleCorePerProcess($workspace);
        $this->requireGateway($workspace);

        $modx = ($this->resolveKernel)();

        if (!$modx instanceof modX) {
            throw KernelBootFailedException::because(
                'modX::getInstance() did not return a kernel instance',
                $workspace->path()
            );
        }

        $this->ensureServices($modx, $workspace);
        $this->silenceLogging($modx);

        // The xPDO result cache keeps objects between tests and survives a transaction rollback —
        // we switch it off so that state does not have to be cleaned after every test.
        $modx->setOption(xPDO::OPT_CACHE_DB, false);

        // `modX::deprecated()` accumulates marks in memory and saves them from a
        // `register_shutdown_function()` — that is, AFTER the last `tearDown()`, past the
        // transaction, past the snapshot and past any other isolation. Any extra built on the MODX
        // 2.x API would drip rows into modx_deprecated_method/modx_deprecated_call on every run and
        // spoil the baseline on a recapture. `log_deprecated = 0` extinguishes the write on the very
        // first line of the method (`core/src/Revolution/modX.php:2482`).
        $modx->setOption('log_deprecated', false);

        return $modx;
    }

    /**
     * We check not only the signs of an install but the core autoloader too: in its absence
     * `index.php:29-34` prints HTML and terminates the process with `exit()`, bypassing any
     * try/finally and exceptions of ours. A diagnosable refusal is possible only BEFORE the
     * inclusion.
     */
    private function assertEnvironmentIsComplete(Workspace $workspace): void
    {
        $required = [
            $workspace->indexFile(),
            $workspace->configFile(),
            $workspace->corePath() . 'vendor/autoload.php',
        ];

        foreach ($required as $file) {
            if (!is_file($file)) {
                throw KernelBootFailedException::because(
                    sprintf(
                        'the environment is not installed or the core is unpacked only in part — file %s is missing',
                        $file
                    ),
                    $workspace->path()
                );
            }
        }
    }

    /**
     * `index.php:14-17` defines `MODX_API_MODE` only if the constant does not exist yet, so it is
     * enough to define it in advance. A false value would mean the core executes `handleRequest()`
     * (index.php:62-64) right inside PHPUnit.
     */
    private function enableApiMode(Workspace $workspace): void
    {
        if (!defined('MODX_API_MODE')) {
            define('MODX_API_MODE', true);

            return;
        }

        if (constant('MODX_API_MODE') !== true) {
            throw KernelBootFailedException::because(
                'the MODX_API_MODE constant is already defined with a value other than true, '
                . 'and the core would have served an HTTP request instead of returning control to the tests',
                $workspace->path()
            );
        }
    }

    /**
     * `MODX_CORE_PATH` and `MODX_CONFIG_KEY` are deliberately NOT defined in advance: they are
     * defined unconditionally by `config.core.php:7-8` of the installed distribution, which is
     * included from `index.php:20` — predefining them would give a "Constant already defined"
     * warning. We check that no core from a different directory is loaded in the process — from TWO
     * sources: the `MODX_CORE_PATH` constant (defined only by `requireGateway()` below, that is, by
     * level 2) and {@see CoreAutoloader::registeredPath()} (level 1,
     * `Unit\UnitTestCase::setUp()`).
     *
     * The second check exists because without it a core booted by level 1 through
     * `CoreAutoloader::register()` from ANOTHER directory would be invisible to this method
     * (`MODX_CORE_PATH` is not defined in that case — `CoreAutoloader` includes only
     * `vendor/autoload.php`, not `index.php`), and `requireGateway()` below would die with an
     * uncatchable "Cannot redeclare class ComposerAutoloaderInit…" instead of a diagnosable
     * `KernelBootFailedException`.
     */
    private function assertSingleCorePerProcess(Workspace $workspace): void
    {
        $expected = rtrim($workspace->corePath(), '/');

        $this->assertCoreSourceMatches('MODX_CORE_PATH', $this->definedCorePath(), $expected, $workspace);
        $this->assertCoreSourceMatches(
            'CoreAutoloader::registeredPath()',
            CoreAutoloader::registeredPath(),
            $expected,
            $workspace
        );
    }

    private function definedCorePath(): ?string
    {
        if (!defined('MODX_CORE_PATH')) {
            return null;
        }

        $defined = constant('MODX_CORE_PATH');

        return is_string($defined) ? $defined : get_debug_type($defined);
    }

    private function assertCoreSourceMatches(string $source, ?string $loadedRaw, string $expected, Workspace $workspace): void
    {
        if ($loadedRaw === null) {
            return;
        }

        $loaded = rtrim($loadedRaw, '/');

        if ($loaded === $expected) {
            return;
        }

        throw KernelBootFailedException::because(
            sprintf(
                'a core from a different directory is already loaded in this process (%s = %s); the '
                . 'core path is fixed for the whole process, so run the tests of another environment '
                . 'in a separate PHPUnit process',
                $source,
                $loaded
            ),
            $workspace->path()
        );
    }

    /**
     * `index.php:37` opens an output buffer of its own and does not close it in API mode, so after
     * the inclusion we return the buffering level to the original one, discarding the contents:
     * otherwise PHPUnit marks the test risky ("did not close its own output buffers").
     */
    private function requireGateway(Workspace $workspace): void
    {
        $level = ob_get_level();
        $errorHandler = $this->currentErrorHandler();

        try {
            require_once $workspace->indexFile();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            $this->restoreErrorHandler($errorHandler, $workspace);
        }
    }

    /**
     * The core installs an error handler of its own (`core/src/Revolution/modX.php:2743`) and never
     * removes it — PHPUnit would mark the test risky ("did not remove its own error handlers"), and
     * PHP errors would go into the MODX log instead of the test report.
     *
     * It is called from a `finally`: if including the core has already failed, the original
     * exception becomes the `previous` of the one thrown here — the cause of the failure is not
     * lost.
     */
    private function restoreErrorHandler(?callable $handler, Workspace $workspace): void
    {
        for ($depth = 0; $depth < self::MAX_ERROR_HANDLER_DEPTH; ++$depth) {
            if ($this->currentErrorHandler() === $handler) {
                return;
            }

            restore_error_handler();
        }

        throw KernelBootFailedException::because(
            sprintf(
                'failed to restore the PHPUnit error handler: the core left more than %d handlers '
                . 'in a row',
                self::MAX_ERROR_HANDLER_DEPTH
            ),
            $workspace->path()
        );
    }

    private function currentErrorHandler(): ?callable
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }

    /**
     * The core adds `error` lazily, only before running processors
     * (`core/src/Revolution/modX.php:1772-1783`), and `lexicon` during culture initialisation
     * (`core/src/Revolution/modX.php:2707-2708`). Tests are entitled to count on both services.
     */
    private function ensureServices(modX $modx, Workspace $workspace): void
    {
        if (!$modx->services instanceof Container) {
            throw KernelBootFailedException::because('the service container is unavailable', $workspace->path());
        }

        $modx->error = $this->service(
            $modx,
            $workspace,
            'error',
            modError::class,
            static fn (): modError => new modError($modx)
        );

        $modx->lexicon = $this->service(
            $modx,
            $workspace,
            'lexicon',
            modLexicon::class,
            static fn (): modLexicon => new modLexicon($modx)
        );
    }

    /**
     * @template TService of object
     *
     * @param class-string<TService> $class
     * @param Closure(): TService    $factory
     *
     * @return TService
     */
    private function service(
        modX $modx,
        Workspace $workspace,
        string $name,
        string $class,
        Closure $factory
    ): object {
        if (!$modx->services->has($name)) {
            $modx->services->add($name, $factory());
        }

        $service = $modx->services->get($name);

        if (!$service instanceof $class) {
            throw KernelBootFailedException::because(
                sprintf('service "%s" in the core container is not an instance of %s', $name, $class),
                $workspace->path()
            );
        }

        return $service;
    }

    /**
     * By default xPDO writes its log to `ECHO` (`vendor/xpdo/xpdo/src/xPDO/xPDO.php:2005`), and any
     * warning from the core would land in stdout, breaking PHPUnit's strict output checks.
     */
    private function silenceLogging(modX $modx): void
    {
        $modx->setLogTarget('FILE');
        $modx->setLogLevel(modX::LOG_LEVEL_ERROR);
    }
}

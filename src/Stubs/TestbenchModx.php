<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Stubs;

use MODX\Revolution\Error\modError;
use MODX\Revolution\modX;
use MODX\Revolution\Services\Container;
use ModxKit\Testbench\Exception\UnsupportedStubOperationException;
use ReflectionClass;

/**
 * Core stub: `instanceof modX` without a constructor, a database connection or an installed CMS.
 *
 * Created exclusively through {@see self::create()}: the `modX`/`xPDO` constructor is never called
 * (`newInstanceWithoutConstructor()`), so the properties it normally fills in (connection, class
 * map, packages) stay in their default state.
 *
 * Exactly what is listed here is emulated: `getOption()`/`setOption()`, `log()`, `lexicon()`,
 * `invokeEvent()`, `getObject()`/`getCollection()` over seeded objects ({@see self::seed()}), the
 * `$modx->services` service container and the `$modx->error` error collector (a real `modError`, it
 * works without a database too).
 *
 * What is easy to reach for out of habit refuses with an explicit
 * {@see UnsupportedStubOperationException}: `newObject()`, `runProcessor()`, query building and
 * reading the class map (the block at the end of the class).
 * That list is FINITE, not universal: the stub inherits the whole of `modX`, and a core method
 * present in neither list will simply run — without a connection and a class map it will most
 * likely die with an `\Error` from the core's internals. That is the boundary of level 1, not a
 * defect: a test that needs such a method moves to the integration `ModxKit\Testbench\TestCase`.
 * An earlier revision of this docblock promised "a fatal error on access to an uninitialised
 * property" for that case — that is impossible: `modX`/`xPDO` have no typed properties at all.
 */
final class TestbenchModx extends modX
{
    /**
     * Name of the primary key by which level 1 decodes a scalar criterion.
     *
     * @see self::criteriaArray()
     */
    private const PRIMARY_KEY_FIELD = 'id';

    private ObjectStore $store;

    private LogRecorder $recorder;

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config = []): self
    {
        /** @var self $stub */
        $stub = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $stub->store = new ObjectStore();
        $stub->recorder = new LogRecorder();
        $stub->config = $config;
        $stub->services = new Container();

        // In a real core `$modx->error` is the `modError` service, and extra code reads it without
        // checks (`$modx->error->hasError()`). A stub that left the property as `null`
        // (`public $error = null;`, modX.php:210) produced a bare `\Error` on that, and the `null`
        // itself was indistinguishable from "there were no errors". A real `modError` fits as is:
        // neither the constructor nor the error collection (`addError()`, `addField()`,
        // `hasError()`, `reset()`) touches the database or the file cache — verified against
        // core/src/Revolution/Error/modError.php.
        self::attachErrorService($stub);

        return $stub;
    }

    /**
     * Extracted from `create()` not for beauty: `modError::__construct()` takes the core BY
     * REFERENCE (`modX &$modx`), and after such a call PHPStan widens the type of the variable to
     * `modX` — `create()` would stop returning the declared `self`. Here it is a local parameter
     * that gets widened, and nobody uses it afterwards.
     */
    private static function attachErrorService(self $stub): void
    {
        $stub->error = new modError($stub);
    }

    /**
     * The stub is created ONLY through {@see self::create()}. With `MODX_CORE_PATH` defined, the
     * public `modX` constructor used to succeed, and then every method of such an object died with
     * `Error: Typed property $store must not be accessed before initialization` — and `$store` here
     * is a property of the stub, not of the core, so there was nothing to diagnose it by.
     * `create()` does not call the constructor (`newInstanceWithoutConstructor()`), so the ban
     * breaks nothing.
     *
     * The original parameters (`$configPath`, `$options`, `$driverOptions`) are deliberately not
     * declared: the body does not read them, PHP does not check constructor compatibility on
     * inheritance and does not object to extra arguments passed to a user-defined function —
     * `new TestbenchModx('/path', [], [])` refuses just the same (this is checked by
     * `testConstructorIsRefused()`, which constructs the stub through reflection with arguments).
     */
    public function __construct()
    {
        throw UnsupportedStubOperationException::forConstructor();
    }

    public function seed(string $class, object $object): void
    {
        $this->store->put($class, $object);
    }

    public function recorder(): LogRecorder
    {
        return $this->recorder;
    }

    /**
     * A port of `xPDO::getOption()` (core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:711-741), including
     * its edge cases. An earlier revision kept its own settings array and diverged from the core in
     * three ways: it did not read `$modx->config` (the only thing `setSetting()` and
     * `PackageRegistrar` write to), ignored `$skipEmpty` and cast an array of keys to a string.
     *
     * The checks are `isset()` rather than `array_key_exists()`, as in the core as well: for
     * `getOption()` a setting whose value is `null` is as good as absent.
     *
     * @param string|array<int, string> $key
     * @param array<string, mixed>|null $options
     * @param mixed $default
     * @param bool $skipEmpty
     *
     * @return mixed
     */
    public function getOption($key, $options = null, $default = null, $skipEmpty = false)
    {
        if (is_array($key)) {
            $option = [];

            foreach ($key as $k) {
                // The core loses the default here too: in this branch it executes
                // `$default = $option;`, where `$option` is knowingly `null` (xPDO.php:728-731).
                // Diverging would be worse than repeating it exactly.
                $option[$k] = $this->getOption($k, $options);
            }

            return $option;
        }

        // `!empty($key)`, as in the core: the key `'0'` also leaves here through the default.
        if (!is_string($key) || empty($key)) {
            return $default;
        }

        $found = false;
        $option = null;

        if (isset($options[$key])) {
            $found = true;
            $option = $options[$key];
        }

        if ((!$found || ($skipEmpty && $option === '')) && isset($this->config[$key])) {
            $found = true;
            $option = $this->config[$key];
        }

        if (!$found || ($skipEmpty && $option === '')) {
            return $default;
        }

        return $option;
    }

    /**
     * @param string $key
     * @param mixed $value
     *
     * @return void
     */
    public function setOption($key, $value)
    {
        $this->config[(string) $key] = $value;
    }

    /**
     * The parent `modX::lexicon()` is declared in `stubs/modx-revolution.php` as
     * `@return string|null` — here the type is narrowed to `string` (the stub never returns
     * `null`), which is covariant and raises no errors.
     *
     * @param string $key
     * @param array<string, mixed> $params
     * @param string $language
     *
     * @return string
     */
    public function lexicon($key, $params = [], $language = '')
    {
        $this->recorder->lexicon((string) $key);

        return (string) $key;
    }

    /**
     * @param int $level
     * @param string $msg
     * @param string $target
     * @param string $def
     * @param string $file
     * @param string $line
     *
     * @return void
     */
    public function log($level, $msg, $target = '', $def = '', $file = '', $line = '')
    {
        $this->recorder->log((int) $level, (string) $msg);
    }

    /**
     * The call is recorded ({@see LogRecorder}, which is what makes `assertEventInvoked()` work),
     * and the answer is `false`: the core answers that way when the event is unknown or has no
     * active plugins (modX.php:1704-1711), and the stub has no event map at all, so all events are
     * unknown. The empty array it used to return means the opposite — "the event exists, the
     * plugins ran and said nothing".
     *
     * @param string $eventName
     * @param array<string, mixed> $params
     *
     * @return false
     */
    public function invokeEvent($eventName, array $params = [])
    {
        $this->recorder->event((string) $eventName, $params);

        return false;
    }

    /**
     * The parent `xPDO::getObject()` is declared in `stubs/modx-revolution.php` as
     * `@template T of Om\xPDOObject` / `@return T|null`; the override must stay covariant, so the
     * same template is repeated here rather than an arbitrary `object`.
     *
     * THESE ANNOTATIONS ARE MANDATORY, NOT A SAFETY MARGIN (checked empirically; the original
     * report claimed the opposite and was wrong). Removing `@template`/`@return T|null` here and
     * returning a plain `object|null` yields, under
     * `vendor/bin/phpstan analyse --memory-limit=1G`:
     *   Method ModxKit\Testbench\Stubs\TestbenchModx::getObject() should return
     *   (T of xPDO\Om\xPDOObject)|null but returns object|null. [return.type]
     * PHPStan inherits the parent's `@template` into an UNannotated override and checks it right
     * there — unlike `newObject()` below, whose body always throws and never actually returns an
     * `object`, which is why the very same check stays silent there even without the template
     * (re-verified the same way).
     *
     * @template T of \xPDO\Om\xPDOObject
     *
     * @param class-string<T> $className
     * @param mixed $criteria
     * @param mixed $cacheFlag
     *
     * @return T|null
     */
    public function getObject($className, $criteria = null, $cacheFlag = true)
    {
        // The core: `sanitizePKCriteria()` leaves `null` as is, and next comes
        // `if ($criteria !== null)` (xPDO.php:839-846) — `getObject($class)` without a criterion
        // finds nothing. `getCollection()` has no such branch: there `null` means "all".
        if ($criteria === null) {
            return null;
        }

        /** @var T|null $object */
        $object = $this->store->first((string) $className, $this->criteriaArray($criteria));

        return $object;
    }

    /**
     * The parent `xPDO::getCollection()` is declared in `stubs/modx-revolution.php` as
     * `@template T of Om\xPDOObject` / `@return array<int, T>` — the same template is repeated here
     * for the same reasons as in `getObject()` above: without it a consuming method on PHPStan max
     * that wrote `foreach ($this->modx->getCollection(Job::class) as $job) { $job->get('x'); }`
     * would get "Call to an undefined method object::get()".
     *
     * @template T of \xPDO\Om\xPDOObject
     *
     * @param class-string<T> $className
     * @param mixed $criteria
     * @param mixed $cacheFlag
     *
     * @return array<int, T>
     */
    public function getCollection($className, $criteria = null, $cacheFlag = true)
    {
        /** @var array<int, T> $collection */
        $collection = $this->store->all(
            (string) $className,
            $criteria === null ? [] : $this->criteriaArray($criteria)
        );

        return $collection;
    }

    /**
     * Coerces a criterion that is untyped (by the parent's signature) into the shape
     * {@see ObjectStore} actually accepts: an array with string keys. It walks the elements
     * explicitly (rather than `(array) $criteria` or an inline `@var`) so that PHPStan infers the
     * `array<string, mixed>` type from the code itself instead of trusting a declaration.
     *
     * A scalar is the primary key, exactly as in the core: `xPDO::sanitizePKCriteria()`
     * (core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2764-2785) turns it into
     * `[primary key => value]`. The core takes the key name from the class map, which the stub does
     * not have (that is why its `getPK()` refuses, see below), so level 1 works by the
     * `xPDOSimpleObject` convention: the primary key is called `id`. A model with a different key
     * (`modSystemSetting` — `key`) is addressed on level 1 by an explicit `['key' => …]` array; see
     * docs/DX_GUIDE.md, "Level 1 recipes".
     *
     * @param mixed $criteria
     *
     * @return array<string, mixed>
     */
    private function criteriaArray($criteria): array
    {
        if (is_scalar($criteria)) {
            return [self::PRIMARY_KEY_FIELD => $criteria];
        }

        if (!is_array($criteria)) {
            // xPDOQuery/xPDOCriteria and other query objects: the stub has nothing to build SQL
            // from (neither a connection nor a class map), and silently searching "with no
            // criteria" is exactly the defect this method was rewritten for.
            throw UnsupportedStubOperationException::forCriteria(get_debug_type($criteria));
        }

        $result = [];

        foreach ($criteria as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * Like `getObject()`, it must stay covariant with the templated parent — but the stub cannot
     * build real model objects (that needs the class map and metadata, which are absent without a
     * constructor), so it always throws an exception explaining the move to level 2.
     *
     * @template T of \xPDO\Om\xPDOObject
     *
     * @param class-string<T> $className
     * @param array<string, mixed> $fields
     */
    public function newObject($className, $fields = []): never
    {
        throw UnsupportedStubOperationException::forMethod('newObject');
    }

    /**
     * What follows is the boundary of the stub, declared explicitly.
     *
     * Every method below is inherited from `xPDO`/`modX` and is doomed without a constructor: some
     * build SQL from an unfilled `config['dbtype']`, others read a class map that does not exist.
     * The former used to produce an UNcatchable fatal `Cannot redeclare class xPDO\Om\xPDOQuery`
     * from the second call on (the first left a half-loaded class), and the latter silently
     * answered wrongly — `getFields()` with an empty array, `getPK()` and `getService()` with
     * `null`, `getCacheManager()` with a real `modCacheManager` that reached straight into the file
     * cache.
     *
     * @param class-string $class
     * @param mixed $criteria
     * @param mixed $cacheFlag
     */
    public function newQuery($class, $criteria = null, $cacheFlag = true): never
    {
        throw UnsupportedStubOperationException::forMethod('newQuery');
    }

    /**
     * @param class-string $className
     * @param mixed $criteria
     */
    public function getCount($className, $criteria = null): never
    {
        throw UnsupportedStubOperationException::forMethod('getCount');
    }

    /**
     * @param class-string $className
     * @param mixed $criteria
     * @param mixed $cacheFlag
     */
    public function getIterator($className, $criteria = null, $cacheFlag = true): never
    {
        throw UnsupportedStubOperationException::forMethod('getIterator');
    }

    /**
     * @param class-string $className
     * @param mixed $graph
     * @param mixed $criteria
     * @param mixed $cacheFlag
     */
    public function getObjectGraph($className, $graph, $criteria = null, $cacheFlag = true): never
    {
        throw UnsupportedStubOperationException::forMethod('getObjectGraph');
    }

    /**
     * @param class-string $className
     * @param mixed $graph
     * @param mixed $criteria
     * @param mixed $cacheFlag
     */
    public function getCollectionGraph($className, $graph, $criteria = null, $cacheFlag = true): never
    {
        throw UnsupportedStubOperationException::forMethod('getCollectionGraph');
    }

    /**
     * @param class-string $className
     * @param mixed $criteria
     */
    public function removeCollection($className, $criteria): never
    {
        throw UnsupportedStubOperationException::forMethod('removeCollection');
    }

    /**
     * Without the override the call went into the real `xPDO::removeObject()` (the method is
     * declared in `stubs/modx-revolution.php` for PHPStan, while the stub inherits the real `modX`)
     * and, with no database connection, returned `false` — "nothing was deleted", a plausible
     * answer, exactly what `docs/SPEC.md:120` forbids: the stub MUST refuse with an exception of
     * the package rather than hand back `null`, a plausible value, or a fatal error.
     *
     * @param class-string $className
     * @param mixed $criteria
     */
    public function removeObject($className, $criteria): never
    {
        throw UnsupportedStubOperationException::forMethod('removeObject');
    }

    /**
     * @param class-string $className
     * @param array<string, mixed> $set
     * @param mixed $criteria
     */
    public function updateCollection($className, array $set, $criteria = null): never
    {
        throw UnsupportedStubOperationException::forMethod('updateCollection');
    }

    /**
     * Processors are unavailable to level 1 in principle: `modX::runProcessor()` resolves an
     * action into a file under `config['processors_path']` (modX.php:1809-1823) and executes it
     * with a real core. The stub used to answer that with a plausible `success => false` carrying
     * the message `Requested processor not found` — that is, `assertProcessorFailure()` would go
     * green VACUOUSLY, on any input, including a knowingly valid one. This is the same class of
     * defect as one already known on level 2, and docs/SPEC.md no longer assigns processors to
     * level 1.
     *
     * @param string $action
     * @param array<string, mixed> $scriptProperties
     * @param array<string, mixed> $options
     */
    public function runProcessor($action = '', $scriptProperties = [], $options = []): never
    {
        throw UnsupportedStubOperationException::forMethod('runProcessor');
    }

    /**
     * @param class-string $className
     */
    public function getFields($className): never
    {
        throw UnsupportedStubOperationException::forMethod('getFields');
    }

    /**
     * @param class-string $className
     */
    public function getPK($className): never
    {
        throw UnsupportedStubOperationException::forMethod('getPK');
    }

    /**
     * @param string $name
     * @param string $class
     * @param string $path
     * @param array<string, mixed> $params
     */
    public function getService($name, $class = '', $path = '', $params = []): never
    {
        throw UnsupportedStubOperationException::forMethod('getService');
    }

    /**
     * The core's `$options` default is `['path' => XPDO_CORE_PATH, 'ignorePkg' => true]`
     * (modX.php:766). The `XPDO_CORE_PATH` constant does not exist on level 1, and PHP allows
     * defaults to be redefined on inheritance, so here it is an empty array.
     *
     * @param string $class
     * @param array<string, mixed> $options
     */
    public function getCacheManager($class = 'xPDO\\Cache\\xPDOCacheManager', $options = []): never
    {
        throw UnsupportedStubOperationException::forMethod('getCacheManager');
    }
}

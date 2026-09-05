<?php

/*
 * Declarations of the MODX Revolution 3 and xPDO classes for static analysis.
 *
 * The MODX core is not a Composer dependency: it is downloaded and installed into the working
 * directory at run time, so PHPStan cannot find its classes on its own. The file is wired in
 * through `scanDirectories` in phpstan.neon — it is ONLY scanned and is never executed or
 * autoloaded (the package's PSR-4 covers `src/` alone).
 *
 * Only the members the package uses are declared; the signatures and docblocks are copied from the
 * MODX 3.2.3-pl originals, with the source referenced on each class.
 */

declare(strict_types=1);

namespace xPDO {
    /**
     * @see core/vendor/xpdo/xpdo/src/xPDO/xPDOIterator.php:28
     */
    class xPDOIterator
    {
    }

    /**
     * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:59
     */
    class xPDO
    {
        /** @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:67 */
        public const OPT_CACHE_DB = 'cache_db';

        /** @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:101 */
        public const LOG_LEVEL_ERROR = 1;

        /** @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:77 */
        public const OPT_CACHE_KEY = 'cache_key';

        /** @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:80 */
        public const OPT_CACHE_MULTIPLE_OBJECT_DELETE = 'multiple_object_delete';

        /**
         * The PDO connection of the current xPDO connection.
         *
         * The original is declared as `public $pdo= null;` with the docblock `@var \PDO`
         * (core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:118-120). Here the type is extended with
         * `null`: before the first `connect()` the property really is `null`, and it is exactly
         * that possibility `TransactionIsolation::end()` checks for.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:120
         *
         * @var \PDO|null
         */
        public $pdo;

        /**
         * The provider of the file cache (and not only file cache) used by `toCache()`/
         * `fromCache()` and by `save()`/`removeObject()` for the query result cache
         * (`OPT_CACHE_DB`).
         *
         * The original is declared as `public $cacheManager= null;` with the docblock
         * `@var Cache\xPDOCacheManager` (xPDO.php:145-148). The `null` here is an ADDITION — for
         * the same reason as `$pdo` above: before the call that initialises the provider
         * (`getCacheManager()`), the property really is `null`.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:148
         *
         * @var Cache\xPDOCacheManager|null
         */
        public $cacheManager;

        /**
         * The configuration of the xPDO instance; `getOption()`/`setOption()` work with exactly
         * this.
         *
         * The original is declared as `public $config= null;` with the docblock `@var array`
         * (core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:122-124). No `null` is needed here: the
         * constructor unconditionally puts an array into the property (xPDO.php:277), and before
         * the constructor no reference to the object exists.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:124
         *
         * @var array<string, mixed>
         */
        public $config;

        /**
         * An array of keys is a documented branch of the original (xPDO.php:728-736): it returns
         * a "key => value" array.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:711
         *
         * @param string|array<int, string> $key
         * @param array<string, mixed>|null $options
         * @param mixed                     $default
         * @param bool                      $skipEmpty
         *
         * @return mixed
         */
        public function getOption($key, $options = null, $default = null, $skipEmpty = false)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:749
         *
         * @param string $key
         * @param mixed  $value
         *
         * @return void
         */
        public function setOption($key, $value)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1974
         *
         * @param int $level
         *
         * @return int
         */
        public function setLogLevel($level = 0)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2005
         *
         * @param string|array<string, mixed> $target
         *
         * @return string|array<string, mixed>|null
         */
        public function setLogTarget($target = 'ECHO')
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:806
         *
         * @template T of Om\xPDOObject
         *
         * @param class-string<T>      $className
         * @param array<string, mixed> $fields
         *
         * @return T|null
         */
        public function newObject($className, $fields = [])
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:839
         *
         * @template T of Om\xPDOObject
         *
         * @param class-string<T> $className
         * @param mixed           $criteria
         * @param mixed           $cacheFlag
         *
         * @return T|null
         */
        public function getObject($className, $criteria = null, $cacheFlag = true)
        {
        }

        /**
         * `TestbenchModx::getCollection()` overrides THIS method in the real core rather than
         * declaring a new one — without an entry here PHPStan does not check the covariance of the
         * override at all (as was the case with `getObject()` earlier, see
         * `TestbenchModx::getObject()`).
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:848-862
         *
         * @template T of Om\xPDOObject
         *
         * @param class-string<T> $className
         * @param mixed           $criteria
         * @param mixed           $cacheFlag
         *
         * @return array<int, T>
         */
        public function getCollection($className, $criteria = null, $cacheFlag = true)
        {
        }

        /**
         * `TestbenchModx::log()` overrides THIS method in the real core. The parameter types are
         * copied from the original's docblock (the signature itself declares no types).
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2018-2030
         *
         * @param int    $level
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
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1015
         *
         * @param class-string<Om\xPDOObject> $className
         * @param mixed                       $criteria
         *
         * @return int
         */
        public function getCount($className, $criteria = null)
        {
        }

        /**
         * Returns `false` if the connection could not be established, otherwise the result of
         * `PDO::beginTransaction()`.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2474
         *
         * @return bool
         */
        public function beginTransaction()
        {
        }

        /**
         * Returns `false` if the connection could not be established, otherwise the result of
         * `PDO::exec()`.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2494
         *
         * @param string $query
         *
         * @return int|false
         */
        public function exec($query)
        {
        }

        /**
         * Returns `false` if the connection could not be established, otherwise the result of
         * `PDO::commit()`.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2484
         *
         * @return bool
         */
        public function commit()
        {
        }

        /**
         * Absolute path to the core cache directory (`core/cache/`).
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1918
         *
         * @return string
         */
        public function getCachePath()
        {
        }

        /**
         * Wraps an identifier in the driver's quotes, trimming any already present beforehand.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2188
         *
         * @param string $string
         *
         * @return string
         */
        public function escape($string)
        {
        }

        /**
         * Returns `false` if the connection could not be established, otherwise the result of
         * `PDO::rollBack()`.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2594
         *
         * @return bool
         */
        public function rollBack()
        {
        }

        /**
         * Returns `false` if the connection could not be established, otherwise the result of
         * `PDO::query()`.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2558
         *
         * @param string $query
         *
         * @return \PDOStatement|false
         */
        public function query($query)
        {
        }

        /**
         * Returns `false` if the connection could not be established, otherwise the result of
         * `PDO::quote()`, additionally trimmed with `trim()` (and, for `PDO::PARAM_INT`, cast to
         * `int`).
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2572
         *
         * @param string $string
         * @param int    $parameter_type
         *
         * @return string|int|false
         */
        public function quote($string, $parameter_type = \PDO::PARAM_STR)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:465
         *
         * @param string      $pkg
         * @param string      $path
         * @param string|null $prefix
         * @param string|null $namespacePrefix
         *
         * @return bool
         */
        public function addPackage($pkg = '', $path = '', $prefix = null, $namespacePrefix = null)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1287
         *
         * @param class-string $className
         * @param bool         $includeDb
         *
         * @return string|null
         */
        public function getTableName($className, $includeDb = false)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:2620
         *
         * @param string $class
         * @param mixed  $criteria
         * @param mixed  $cacheFlag
         *
         * @return Om\xPDOQuery
         */
        public function newQuery($class, $criteria = null, $cacheFlag = true)
        {
        }

        /**
         * Returns `xPDO\xPDOIterator` (not `xPDO\Om\…`, unlike its neighbours in this file).
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:877
         *
         * @param string $className
         * @param mixed  $criteria
         * @param mixed  $cacheFlag
         *
         * @return xPDOIterator
         */
        public function getIterator($className, $criteria = null, $cacheFlag = true)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1062
         *
         * @template T of Om\xPDOObject
         *
         * @param class-string<T> $className
         * @param mixed           $graph
         * @param mixed           $criteria
         * @param mixed           $cacheFlag
         *
         * @return T|null
         */
        public function getObjectGraph($className, $graph, $criteria = null, $cacheFlag = true)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1090
         *
         * @template T of Om\xPDOObject
         *
         * @param class-string<T> $className
         * @param mixed           $graph
         * @param mixed           $criteria
         * @param mixed           $cacheFlag
         *
         * @return array<int, T>
         */
        public function getCollectionGraph($className, $graph, $criteria = null, $cacheFlag = true)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:979
         *
         * @param string $className
         * @param mixed  $criteria
         *
         * @return bool|int
         */
        public function removeCollection($className, $criteria)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:939
         *
         * @param string $className
         * @param mixed  $criteria
         *
         * @return bool
         */
        public function removeObject($className, $criteria)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:889
         *
         * @param string               $className
         * @param array<string, mixed> $set
         * @param mixed                $criteria
         *
         * @return bool|int
         */
        public function updateCollection($className, array $set, $criteria = null)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1420
         *
         * @param string $className
         *
         * @return array<string, mixed>
         */
        public function getFields($className)
        {
        }

        /**
         * The core returns a composite primary key as an array and a missing one as `null`
         * (xPDO.php:1595-1655).
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1595
         *
         * @param string $className
         *
         * @return string|array<string, string>|null
         */
        public function getPK($className)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1250
         *
         * @param string               $name
         * @param string               $class
         * @param string               $path
         * @param array<string, mixed> $params
         *
         * @return object|null
         */
        public function getService($name, $class = '', $path = '', $params = [])
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:1883
         *
         * @return Om\xPDOManager|null
         */
        public function getManager()
        {
        }
    }
}

namespace xPDO\Cache {
    /**
     * @see core/vendor/xpdo/xpdo/src/xPDO/Cache/xPDOCacheManager.php:20
     */
    class xPDOCacheManager
    {
        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/Cache/xPDOCacheManager.php:703
         *
         * @param string               $key
         * @param array<string, mixed> $options
         *
         * @return bool
         */
        public function delete($key, $options = [])
        {
        }
    }
}

namespace xPDO\Om {
    /**
     * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOQuery.php:21
     */
    abstract class xPDOQuery
    {
    }

    /**
     * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOObject.php:32
     */
    class xPDOObject
    {
        /**
         * Declared for the sake of level 1 tests: the real constructor reads `config['dbname']`,
         * `config['dbtype']` and the class map, so on the core stub it does not go through — see
         * `tests/Unit/Stubs/TestbenchModxTest.php` and docs/DX_GUIDE.md §6.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOObject.php:618
         */
        public function __construct(\xPDO\xPDO &$xpdo)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOObject.php:2034
         *
         * @param array<string, mixed> $fldarray
         * @param string               $keyPrefix
         * @param bool                 $setPrimaryKeys
         * @param bool                 $rawValues
         * @param bool                 $adhocValues
         *
         * @return void
         */
        public function fromArray($fldarray, $keyPrefix = '', $setPrimaryKeys = false, $rawValues = false, $adhocValues = false)
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOObject.php:944
         *
         * @param string|array<int, string>       $k
         * @param string|array<string, string>|null $format
         * @param mixed                           $formatTemplate
         *
         * @return mixed
         */
        public function get($k, $format = null, $formatTemplate = null)
        {
        }

        /**
         * Returns `true` only if the value really changed.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOObject.php:775
         *
         * @param string          $k
         * @param mixed           $v
         * @param string|callable $vType
         *
         * @return bool
         */
        public function set($k, $v = null, $vType = '')
        {
        }

        /**
         * In the original the first parameter is declared by reference
         * (`addOne(& $obj, $alias= '')`), so only a variable can be passed.
         *
         * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOObject.php:1200
         *
         * @param mixed  $obj
         * @param string $alias
         *
         * @return bool
         */
        public function addOne(&$obj, $alias = '')
        {
        }

        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOObject.php:1326
         *
         * @param bool|int|null $cacheFlag
         *
         * @return bool
         */
        public function save($cacheFlag = null)
        {
        }
    }

    /**
     * An object with the surrogate primary key `id`; in the original it is an empty subclass.
     *
     * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOSimpleObject.php:22
     */
    class xPDOSimpleObject extends xPDOObject
    {
    }

    /**
     * The original is abstract and has no constructor of its own in the consuming package: the
     * package obtains an instance only through `xPDO::getManager()` and never creates one itself.
     *
     * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOManager.php:26
     */
    abstract class xPDOManager
    {
        /**
         * @see core/vendor/xpdo/xpdo/src/xPDO/Om/xPDOManager.php:82
         *
         * @param class-string $className
         *
         * @return bool
         */
        abstract public function createObjectContainer($className);
    }
}

namespace xPDO\Transport {
    /**
     * The package does not yet build a transport package through xPDOTransport directly — only its
     * named constants, which the fixture's `build.transport.php` passes to `createVehicle()`.
     *
     * @see core/vendor/xpdo/xpdo/src/xPDO/Transport/xPDOTransport.php:22
     */
    class xPDOTransport
    {
        /** @see core/vendor/xpdo/xpdo/src/xPDO/Transport/xPDOTransport.php:27 */
        public const PRESERVE_KEYS = 'preserve_keys';

        /** @see core/vendor/xpdo/xpdo/src/xPDO/Transport/xPDOTransport.php:29 */
        public const UNIQUE_KEY = 'unique_key';

        /** @see core/vendor/xpdo/xpdo/src/xPDO/Transport/xPDOTransport.php:30 */
        public const UPDATE_OBJECT = 'update_object';
    }
}

namespace MODX\Revolution\Services {
    /**
     * @see core/src/Revolution/Services/Container.php:8
     */
    class Container
    {
        /**
         * @param string $id
         * @param mixed  $value
         *
         * @return void
         */
        public function add(string $id, $value)
        {
        }

        /**
         * @return mixed
         */
        public function get(string $id)
        {
        }

        public function has(string $id): bool
        {
        }

        /**
         * Inherited from `Pimple\Container`; it unconditionally clears the frozen flag as well
         * (`core/vendor/pimple/pimple/src/Pimple/Container.php:150-160`), so a repeated `add()` on
         * a previously frozen key after `offsetUnset()` does not throw `FrozenServiceException` —
         * and that is exactly what `PackageRegistrar::registerServices()` uses.
         *
         * @see core/vendor/pimple/pimple/src/Pimple/Container.php:151
         *
         * @param string $id
         *
         * @return void
         */
        public function offsetUnset($id)
        {
        }
    }
}

namespace MODX\Revolution\Error {
    use MODX\Revolution\modX;

    /**
     * @see core/src/Revolution/Error/modError.php:23
     */
    class modError
    {
        /**
         * @see core/src/Revolution/Error/modError.php:54
         *
         * @param string|array<string, mixed> $message
         */
        public function __construct(modX &$modx, $message = '')
        {
        }

        /**
         * @see core/src/Revolution/Error/modError.php:204
         *
         * @param string|array<string, mixed> $msg
         *
         * @return void
         */
        public function addError($msg)
        {
        }

        /**
         * @see core/src/Revolution/Error/modError.php:247
         *
         * @return bool
         */
        public function hasError()
        {
        }

        /**
         * Clears the accumulated errors, fields, message and status.
         *
         * @see core/src/Revolution/Error/modError.php:310
         *
         * @return void
         */
        public function reset()
        {
        }
    }
}

namespace MODX\Revolution\Processors {
    /**
     * Declared for the sake of the `tests/Fixtures/SampleExtra/processors/` fixture: it portrays
     * the processor of a real extra, and PHPStan analyses the whole of `tests/`.
     *
     * @see core/src/Revolution/Processors/Processor.php:21
     */
    abstract class Processor
    {
        /**
         * @see core/src/Revolution/Processors/Processor.php:185
         *
         * @return mixed
         */
        abstract public function process();

        /**
         * @see core/src/Revolution/Processors/Processor.php:221
         *
         * @param string $k
         * @param mixed  $default
         *
         * @return mixed
         */
        public function getProperty($k, $default = null)
        {
        }

        /**
         * @see core/src/Revolution/Processors/Processor.php:126
         *
         * @param string $msg
         * @param mixed  $object
         *
         * @return array<string, mixed>|string
         */
        public function success($msg = '', $object = null)
        {
        }

        /**
         * @see core/src/Revolution/Processors/Processor.php:137
         *
         * @param string $msg
         * @param mixed  $object
         *
         * @return array<string, mixed>|string
         */
        public function failure($msg = '', $object = null)
        {
        }
    }

    /**
     * @see core/src/Revolution/Processors/ProcessorResponse.php:20
     */
    class ProcessorResponse
    {
        /**
         * @see core/src/Revolution/Processors/ProcessorResponse.php:105
         *
         * @return bool
         */
        public function isError()
        {
        }

        /**
         * Returns `$this->response['message']`, or an empty string when it is absent.
         *
         * @see core/src/Revolution/Processors/ProcessorResponse.php:126
         *
         * @return string
         */
        public function getMessage()
        {
        }
    }
}

namespace MODX\Revolution\Transport {
    use MODX\Revolution\modNamespace;
    use MODX\Revolution\modX;
    use xPDO\Om\xPDOObject;

    /**
     * Represents an installed or being-installed transport package; the package uses only its FQCN
     * as a `class-string<xPDOObject>` in `getObject()`/`assertObjectExists()`.
     *
     * @see core/src/Revolution/Transport/modTransportPackage.php:34
     */
    class modTransportPackage extends xPDOObject
    {
    }

    /**
     * Abstracts the building of a transport package; within the package it is used only by the
     * fixture `tests/Fixtures/SampleExtra/_build/build.transport.php`, run in the subprocess
     * {@see \ModxKit\Testbench\Package\TransportInstaller::build()}.
     *
     * @see core/src/Revolution/Transport/modPackageBuilder.php:24
     */
    class modPackageBuilder
    {
        /**
         * @see core/src/Revolution/Transport/modPackageBuilder.php:31
         *
         * @var string
         */
        public $directory;

        /**
         * @see core/src/Revolution/Transport/modPackageBuilder.php:37
         *
         * @var string
         */
        public $signature;

        /**
         * @see core/src/Revolution/Transport/modPackageBuilder.php:68
         */
        public function __construct(modX &$modx)
        {
        }

        /**
         * @see core/src/Revolution/Transport/modPackageBuilder.php:119
         *
         * @param string $name
         * @param string $version
         * @param string $release
         *
         * @return \xPDO\Transport\xPDOTransport
         */
        public function createPackage($name, $version, $release = '')
        {
        }

        /**
         * @see core/src/Revolution/Transport/modPackageBuilder.php:165
         *
         * @param string|modNamespace $ns
         * @param bool|array<int, string> $autoincludes
         * @param bool $packageNamespace
         * @param string $path
         * @param string $assetsPath
         *
         * @return bool
         */
        public function registerNamespace($ns = 'core', $autoincludes = true, $packageNamespace = true, $path = '', $assetsPath = '')
        {
        }

        /**
         * @see core/src/Revolution/Transport/modPackageBuilder.php:239
         *
         * @param mixed $obj
         * @param array<string, mixed> $attr
         *
         * @return modTransportVehicle
         */
        public function createVehicle($obj, $attr)
        {
        }

        /**
         * @see core/src/Revolution/Transport/modPackageBuilder.php:257
         *
         * @param modTransportVehicle $vehicle
         *
         * @return bool
         */
        public function putVehicle($vehicle)
        {
        }

        /**
         * @see core/src/Revolution/Transport/modPackageBuilder.php:286
         *
         * @return bool
         */
        public function pack()
        {
        }
    }

    /**
     * The build payload returned by {@see modPackageBuilder::createVehicle()}. The package does not
     * use its methods directly — `putVehicle()` calls `compile()`/`fetch()` from inside the real
     * MODX, so an empty class is enough here.
     *
     * @see core/src/Revolution/Transport/modTransportVehicle.php:18
     */
    class modTransportVehicle
    {
    }
}

namespace MODX\Revolution {
    use MODX\Revolution\Error\modError;
    use MODX\Revolution\Services\Container;
    use xPDO\Om\xPDOSimpleObject;
    use xPDO\xPDO;

    /**
     * @see core/src/Revolution/modX.php:51
     */
    class modX extends xPDO
    {
        /**
         * @see core/src/Revolution/modX.php:73-75
         *
         * @var Container
         */
        public $services;

        /**
         * Re-reads the configuration from the database, regenerating the core file cache along the
         * way. Returns `$modx->config`.
         *
         * @see core/src/Revolution/modX.php:1462
         *
         * @return array<string, mixed>
         */
        public function reloadConfig()
        {
        }

        /**
         * Marks the calling method as deprecated. With `log_deprecated = 0` it returns immediately,
         * writing nothing.
         *
         * @see core/src/Revolution/modX.php:2480
         *
         * @param string $since
         * @param string $recommendation
         * @param string $deprecatedDef
         *
         * @return void
         */
        public function deprecated($since, $recommendation = '', $deprecatedDef = '')
        {
        }

        /**
         * @see core/src/Revolution/modX.php:210 (`public $error = null;`)
         *
         * @var modError|null
         */
        public $error;

        /**
         * @see core/src/Revolution/modX.php:143 (`public $lexicon = null;`)
         *
         * @var modLexicon|null
         */
        public $lexicon;

        /**
         * The core's current user.
         *
         * The original is declared as `public $user= null;` with the docblock `@var modUser`
         * (core/src/Revolution/modX.php:145-148). Here the type is extended with `null`: the core
         * itself resets the property to `null` when leaving a context (modX.php:2636).
         *
         * @see core/src/Revolution/modX.php:148
         *
         * @var modUser|null
         */
        public $user;

        /**
         * Returns `false` if the event is not set or has no active plugins, otherwise an array of
         * the plugins' results.
         *
         * @see core/src/Revolution/modX.php:1704
         *
         * @param string               $eventName
         * @param array<string, mixed> $params
         *
         * @return array<int, mixed>|bool
         */
        public function invokeEvent($eventName, array $params = [])
        {
        }

        /**
         * `TestbenchModx::lexicon()` overrides THIS method in the real core rather than declaring
         * a new one.
         *
         * @see core/src/Revolution/modX.php:2250-2259
         *
         * @param string               $key
         * @param array<string, mixed> $params
         * @param string               $language
         *
         * @return string|null
         */
        public function lexicon($key, $params = [], $language = '')
        {
        }

        /**
         * The original is declared as `@return ProcessorResponse|mixed` (modX.php:1766): every
         * branch of MODX 3.2.3-pl returns a `ProcessorResponse`, but a processor with an overridden
         * `run()` is free to return anything, so the type stays `mixed`.
         *
         * @see core/src/Revolution/modX.php:1769
         *
         * @param string               $action
         * @param array<string, mixed> $scriptProperties
         * @param array<string, mixed> $options
         *
         * @return mixed
         */
        public function runProcessor($action = '', $scriptProperties = [], $options = [])
        {
        }

        /**
         * The original's `$options` default is `['path' => XPDO_CORE_PATH, 'ignorePkg' => true]`;
         * the `XPDO_CORE_PATH` constant is declared only by a booted core, so here (as in
         * `TestbenchModx`) there is an empty array: defaults do not affect signature
         * compatibility.
         *
         * @see core/src/Revolution/modX.php:766
         *
         * @param string               $class
         * @param array<string, mixed> $options
         *
         * @return modCacheManager|null
         */
        public function getCacheManager($class = 'xPDO\\Cache\\xPDOCacheManager', $options = [])
        {
        }

        /**
         * @see core/src/Revolution/modX.php:452
         *
         * @param string|int|null           $id
         * @param array<string, mixed>|null $config
         * @param bool                      $forceNew
         *
         * @return static
         */
        public static function getInstance($id = null, $config = null, $forceNew = false)
        {
        }
    }

    /**
     * @see core/src/Revolution/modCacheManager.php:39
     */
    class modCacheManager
    {
    }

    /**
     * @see core/src/Revolution/modAccessibleObject.php:16
     */
    class modAccessibleObject extends \xPDO\Om\xPDOObject
    {
    }

    /**
     * @see core/src/Revolution/modAccessibleSimpleObject.php:12
     */
    class modAccessibleSimpleObject extends modAccessibleObject
    {
    }

    /**
     * @see core/src/Revolution/modNamespace.php:29
     */
    class modNamespace extends modAccessibleObject
    {
    }

    /**
     * Used only as an FQCN string in test manipulation of the workspace table
     * (`workspace/packages/scanlocal` without a row with `id = 1` answers `isError()` instead of
     * throwing) — the package calls no methods on it.
     *
     * @see core/src/Revolution/modWorkspace.php:20
     */
    class modWorkspace extends xPDOSimpleObject
    {
    }

    /**
     * The original additionally implements `modResourceInterface`; the interface is not declared
     * because the package does not use it.
     *
     * @see core/src/Revolution/modResource.php:71
     */
    class modResource extends modAccessibleSimpleObject
    {
    }

    /**
     * @see core/src/Revolution/modElement.php:32
     */
    class modElement extends modAccessibleSimpleObject
    {
    }

    /**
     * @see core/src/Revolution/modChunk.php:25
     */
    class modChunk extends modElement
    {
    }

    /**
     * @see core/src/Revolution/modScript.php:24
     */
    class modScript extends modElement
    {
    }

    /**
     * @see core/src/Revolution/modSnippet.php:22
     */
    class modSnippet extends modScript
    {
    }

    /**
     * The original is declared abstract; the package creates only the `modUser` subclass.
     *
     * @see core/src/Revolution/modPrincipal.php:19
     */
    abstract class modPrincipal extends xPDOSimpleObject
    {
    }

    /**
     * @see core/src/Revolution/modUser.php:40
     */
    class modUser extends modPrincipal
    {
    }

    /**
     * @see core/src/Revolution/modUserProfile.php:38
     */
    class modUserProfile extends xPDOSimpleObject
    {
    }

    /**
     * Extends `xPDOObject` rather than `xPDOSimpleObject`: the table's primary key is the `key`
     * field (core/src/Revolution/mysql/modSystemSetting.php:29-37).
     *
     * @see core/src/Revolution/modSystemSetting.php:22
     */
    class modSystemSetting extends \xPDO\Om\xPDOObject
    {
    }

    /**
     * @see core/src/Revolution/modLexicon.php:26
     */
    class modLexicon
    {
        /**
         * @see core/src/Revolution/modLexicon.php:79
         *
         * @param array<string, mixed> $config
         */
        public function __construct(xPDO &$modx, array $config = [])
        {
        }
    }
}

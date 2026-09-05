<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedConstructorParamRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\Set\ValueObject\LevelSetList;

// bin/modx-testbench has no .php extension (it is the executable CLI file declared under
// `bin` in composer.json) — RectorConfigBuilder::withPaths() filters such files out through
// Option::FILE_EXTENSIONS (only 'php' by default; see
// Rector\FileSystem\FilesFinder::findInDirectoriesAndFiles()), and the filter applies even to
// files listed one by one. The only supported way to let it through is to list it explicitly in
// Option::FILES_WITHOUT_EXTENSION; that parameter has no dedicated method on
// RectorConfigBuilder, so SimpleParameterProvider is used directly (the class is marked `@api`,
// that is, it is meant to be used exactly this way).
SimpleParameterProvider::setParameter(Option::FILES_WITHOUT_EXTENSION, [
    __DIR__ . '/bin/modx-testbench',
]);

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/bin',
        // Files in the package root: a directory entry cannot pick them up without dragging
        // vendor/ along. bootstrap.php is the public entry point, which used to be covered by
        // none of the three tools.
        __DIR__ . '/.php-cs-fixer.dist.php',
        __DIR__ . '/bootstrap.php',
        __DIR__ . '/rector.php',
        __DIR__ . '/src',
        __DIR__ . '/stubs',
        __DIR__ . '/tests',
    ])
    // The fixture of the extra under test (tests/Fixtures/SampleExtra) must keep the shape xPDO
    // requires: class names as strings in $xpdo_meta_map (not ::class constants), and a service
    // class without readonly turned on at class level. Rector does not know that and rewrites the
    // fixture to the package's general rules.
    ->withSkip([
        __DIR__ . '/tests/Fixtures',
        // The stream wrappers in the tests implement PHP's protocol for
        // stream_wrapper_register(): the method signatures and the empty stream_close() are
        // dictated by PHP itself, not by our code. Dropping the "unused" parameters would break
        // the wrapper. Exactly two rules are skipped; the rest still apply to these files as
        // usual.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/tests/Integration/Database/ShortWriteStreamWrapper.php',
            __DIR__ . '/tests/Unit/Installer/UnchmodableStreamWrapper.php',
            __DIR__ . '/tests/Unit/Database/LyingSizeStreamWrapper.php',
        ],
        RemoveEmptyClassMethodRector::class => [
            __DIR__ . '/tests/Integration/Database/ShortWriteStreamWrapper.php',
            __DIR__ . '/tests/Unit/Installer/UnchmodableStreamWrapper.php',
            // stubs/ holds declarations of a FOREIGN API (MODX and xPDO); the file is never
            // executed and never autoloaded: its bodies are empty by construction, and the
            // constructors and their parameters exist for exactly one purpose — so that PHPStan
            // can check the CALLS made from src/. Rector's reasoning "the body is empty / the
            // parameter is unused" rests on something a declaration file does not and cannot
            // have.
            __DIR__ . '/stubs',
        ],
        RemoveUnusedConstructorParamRector::class => [
            __DIR__ . '/stubs',
        ],
        // The docblocks in stubs/ are copied verbatim from the MODX 3.2.3-pl originals (see the
        // file header); conforming them to the package's own convention is a separate question
        // about the distribution boundary, not a job for the tool.
        RemoveUselessParamTagRector::class => [
            __DIR__ . '/stubs',
        ],
    ])
    ->withSets([LevelSetList::UP_TO_PHP_82])
    ->withPreparedSets(deadCode: true, codeQuality: true);

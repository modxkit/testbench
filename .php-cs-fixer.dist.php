<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/stubs', __DIR__ . '/tests'])
    // The fixture of the extra under test (tests/Fixtures/SampleExtra) does not follow the
    // package's general style — it is sample code that reproduces an ordinary MODX extra
    // literally (model classes are not final, `$metaMap` has no strict property type), not code
    // of testbench itself. The fixer would rewrite it into a style that is not its own, so the
    // directory is excluded.
    ->exclude('Fixtures')
    ->name('*.php')
    // bin/modx-testbench is the only file of the package without a .php extension (the
    // executable CLI file declared under `bin` in composer.json), so ->name('*.php') does not
    // find it. append() adds a specific path bypassing both ->in() and ->name().
    //
    // The files in the package ROOT are wired in the same way: their extension is ordinary, but
    // they belong to none of the ->in() directories, and the root cannot be added as a whole —
    // vendor/ would come with it. bootstrap.php is the important one here: it is the public entry
    // point that the consumer's phpunit.xml wires in directly, and it used to be checked by none
    // of the three tools.
    ->append([
        __DIR__ . '/bin/modx-testbench',
        __DIR__ . '/bootstrap.php',
        __DIR__ . '/rector.php',
        __DIR__ . '/.php-cs-fixer.dist.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'no_extra_blank_lines' => true,
    ])
    ->setFinder($finder);

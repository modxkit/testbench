<?php

declare(strict_types=1);

use MODX\Revolution\modNamespace;
use MODX\Revolution\Transport\modPackageBuilder;
use xPDO\Transport\xPDOTransport;

/** @var \MODX\Revolution\modX $modx */
$modx = require __DIR__ . '/bootstrap.build.php';

$builder = new modPackageBuilder($modx);
$builder->createPackage('sampleextra', '1.0.0', 'pl');
$builder->registerNamespace('sampleextra', false, true, '{core_path}components/sampleextra/');

$namespace = $modx->newObject(modNamespace::class);

// `xPDO::newObject()` returns `null` if the class is not registered in the model
// (`xPDO.php:806-817`) — PHPStan at level `max` does not allow calling a method on a nullable
// value silently, and the class `MODX\Revolution\modNamespace` is always registered in the core model.
if (!$namespace instanceof modNamespace) {
    throw new RuntimeException('MODX did not create a modNamespace object: the class is not registered in the xPDO model.');
}

$namespace->fromArray([
    'name' => 'sampleextra',
    'path' => '{core_path}components/sampleextra/',
    'assets_path' => '{assets_path}components/sampleextra/',
], '', true, true);

$vehicle = $builder->createVehicle($namespace, [
    xPDOTransport::UNIQUE_KEY => 'name',
    xPDOTransport::PRESERVE_KEYS => true,
    xPDOTransport::UPDATE_OBJECT => true,
]);

$builder->putVehicle($vehicle);
$builder->pack();

echo $builder->directory . $builder->signature . '.transport.zip';

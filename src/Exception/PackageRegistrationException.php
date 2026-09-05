<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

final class PackageRegistrationException extends TestbenchException
{
    public static function atStep(string $step, string $package, string $reason): self
    {
        return new self(sprintf(
            "Failed to register package \"%s\" at step \"%s\": %s\n"
            . 'Check the paths in PackageDefinition and that metadata.<dbtype>.php is present in the model directory.',
            $package,
            $step,
            $reason
        ));
    }

    /**
     * For failures of building or installing a transport package: {@see self::atStep()} advises
     * checking `PackageDefinition` and `metadata.<dbtype>.php`, but
     * {@see \ModxKit\Testbench\Package\TransportInstaller} reads neither — it is a separate install
     * path (the real `modPackageBuilder` / `modTransportPackage`), and that advice would lead away
     * from the real cause.
     */
    public static function atTransportStep(string $step, string $package, string $reason): self
    {
        return new self(sprintf(
            "Failed to run step \"%s\" of transport package \"%s\": %s\n"
            . 'Check the build script (build.transport.php), write permissions on core/packages/ '
            . 'and the output of the build/install subprocess.',
            $step,
            $package,
            $reason
        ));
    }
}

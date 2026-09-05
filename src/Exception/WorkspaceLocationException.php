<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

use LogicException;

/**
 * `MODX_TESTBENCH_WORKSPACE` names a location the package refuses to work in.
 *
 * A type of its own for the same reason as {@see WorkspaceOwnershipException}: the message
 * has to be printed WITHOUT masking, and only a type tells the commands so. Its message is
 * built from the package's own prose plus the rejected path, and it names the replacement to
 * use — `/tmp/modx-testbench`. Under the package's own password (`testbench`) blind masking
 * turned that recommendation into `(/tmp/modx-***, for example)`, telling the user to look
 * for the very name it had just hidden.
 *
 * The {@see SecretFreeMessage} proof for {@see self::filesystemRoot()} is enforced by the
 * factory, on its own argument: it accepts a path only when `rtrim($path, '/')` is empty, so
 * what it interpolates can be nothing but a run of slashes. No password is a run of slashes,
 * and there is nothing else in the message but literals of this file. Because the check sits on
 * the argument rather than on the caller, it holds however the call is written — through
 * `$class::filesystemRoot()`, through reflection — neither of which a scan of the sources can
 * see.
 *
 * {@see WorkspaceOwnershipException} deliberately has no equivalent: the path in its messages IS
 * a consumer-chosen directory name, and no predicate separates that from arbitrary text. Its
 * callers are pinned by a test instead, with the limits that implies.
 */
final class WorkspaceLocationException extends TestbenchException implements SecretFreeMessage
{
    /**
     * Private so that `new` with an arbitrary message is not a way into an exempt type: the
     * named factories are the only entrance, and each of them checks its own argument before
     * building a message that will be printed unmasked. Together those two make the
     * {@see SecretFreeMessage} promise structural for THIS type — which is exactly what
     * {@see WorkspaceOwnershipException} cannot claim, and does not.
     */
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function filesystemRoot(string $path): self
    {
        // The proof this factory rests on is a statement about its own argument, so it is
        // checked here rather than trusted from the caller. That is what makes the exemption
        // independent of how the call happens to be written: `$class::filesystemRoot()` and
        // reflection both arrive at this line, and a scan of the sources catches neither.
        //
        // The rejected value is deliberately NOT quoted in the failure below. This is a
        // LogicException, so it escapes the masking the commands apply — quoting the argument
        // here would reopen, in the guard itself, the hole the guard exists to close.
        if (rtrim($path, '/') !== '') {
            throw new LogicException(
                'WorkspaceLocationException::filesystemRoot() accepts only the filesystem root — '
                . 'the one path whose message needs no masking, because it can consist of nothing '
                . 'but slashes. It was given something else, and the value is not repeated here on '
                . 'purpose. See the class docblock and ModxKit\\Testbench\\Exception\\SecretFreeMessage.'
            );
        }

        return new self(sprintf(
            'The test environment directory "%s" is not allowed: the filesystem root cannot be a '
            . 'working directory. Set MODX_TESTBENCH_WORKSPACE to a directory of its own '
            . '(/tmp/modx-testbench, for example) or do not set it at all.',
            $path
        ));
    }
}

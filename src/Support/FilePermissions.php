<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Support;

/**
 * Tightening the permissions of files that hold passwords (`setup/config.xml`,
 * `core/config/config.inc.php`).
 *
 * There is one policy here and it is deliberately soft: being unable to narrow the permissions is
 * NOT a success criterion. Both callers ({@see \ModxKit\Testbench\Installer\ConfigXmlWriter::write()},
 * {@see \ModxKit\Testbench\Installer\HeadlessInstaller}) call `restrict()` right AFTER a successful
 * write of the file, so the policy is determined by exactly one form of failure: the write went
 * through, the `chmod()` did not. An exception would then declare failed an install that completed
 * in full — with advice the consumer cannot follow in such an environment.
 *
 * That form is, by general description, produced by some network and container mounts. NOT
 * MEASURED — and that is admitted here rather than passed off as a fact.
 *
 * The `chmod()` failures measured on this machine (macOS, PHP 8.4.8) — a file with the
 * `chflags uchg` flag and a volume mounted read-only — never reach that form: on them the write
 * fails too, so there would be nothing left to preserve. As `chmod()` failures they are genuine; as
 * an illustration of the policy they are not.
 *
 * The list of failing write functions (`file_put_contents()`, `fopen()` for writing, `unlink()`) was
 * taken from a run on a file with `chflags uchg`. For a read-only mounted volume the same list was
 * not checked item by item — it follows from the definition of such a volume.
 *
 * The common explanation "a volume without POSIX permissions (exFAT/FAT32)" did NOT survive
 * verification and has been removed from the package: on a FAT32 image under macOS `chmod()` returns
 * `true` (the volume is mounted with `noowners`, and the mode simply stays 0700) — there is no
 * failure there at all. Nor does a `deny writesecurity` ACL on the owner produce one.
 *
 * The failure is therefore not fatal but reporting: `E_USER_WARNING`. Its visibility is decided by
 * the consumer's ini: `display_errors=1` sends it to the output stream, `display_errors=0` together
 * with `log_errors=1` to the error log, and with both switched off nowhere. That last combination is
 * rare (it is in neither the shipped `php.ini-production`, where `log_errors=On`, nor on the
 * development machine of this package, where no ini is loaded at all and `display_errors=1`), but
 * reachable — and the price of silence here is the database password in a world-readable file.
 *
 * That is why this channel is not the only one: the permissions are also checked BY THE FACTS,
 * regardless of whether tightening them ever succeeded —
 * {@see \ModxKit\Testbench\Environment\Workspace::exposedSecretFiles()} — and
 * `bin/modx-testbench install` prints a `SymfonyStyle` warning about it.
 *
 * @internal
 */
final class FilePermissions
{
    /**
     * @return bool Whether the permissions were successfully tightened.
     */
    public static function restrict(string $file, int $mode, string $why): bool
    {
        // The `@` follows the pattern of `Workspace::writeOwnershipMarker()`: the result is checked
        // right here, and a raw "chmod(): Operation not permitted" would get ahead of the package's
        // own message, which names the same cause more clearly.
        if (@chmod($file, $mode)) {
            return true;
        }

        trigger_error(
            sprintf(
                'modx-testbench: failed to restrict the permissions of %s to %04o — %s The file is '
                . 'left as is; if the working directory is accessible to other users, move it with '
                . 'MODX_TESTBENCH_WORKSPACE to where changing permissions is allowed.',
                $file,
                $mode,
                $why
            ),
            E_USER_WARNING
        );

        return false;
    }
}

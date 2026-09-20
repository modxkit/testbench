<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Environment;

use ModxKit\Testbench\Support\Env;

/**
 * The private base directory of the package: `<XDG_CACHE_HOME>/modx-testbench`, otherwise
 * `<HOME>/.cache/modx-testbench`, otherwise the same under `sys_get_temp_dir()`.
 *
 * One place rather than two by a decision already recorded in {@see Workspace::defaultLocation()}:
 * "a second way of choosing the base directory inside one package would diverge from the first at
 * the very first edit". The second consumer — {@see RunLock} — is what turned that warning into a
 * class.
 *
 * `$config->cacheDir` (`MODX_TESTBENCH_CACHE_DIR`) is deliberately NOT the same thing and is not
 * read here: in CI it is pointed at a directory uploaded by `actions/cache`, and everything named
 * by a fingerprint would travel into a build artifact together with it.
 *
 * The directory itself is shared with the release cache and may already exist with a mode open to
 * listing — so whatever is created UNDER it gets 0700 from its own creator, and that segment is
 * what closes the name. See the docblock of {@see Workspace::DEFAULT_SUFFIX}.
 *
 * @internal
 */
final class PrivateCacheDirectory
{
    public static function path(): string
    {
        $base = Env::get('XDG_CACHE_HOME');

        if ($base === null) {
            $home = Env::get('HOME');
            $base = $home === null ? null : $home . '/.cache';
        }

        return rtrim($base ?? sys_get_temp_dir(), '/') . '/modx-testbench';
    }
}

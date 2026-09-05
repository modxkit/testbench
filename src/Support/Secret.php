<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Support;

use ModxKit\Testbench\Exception\SecretFreeMessage;
use Throwable;

/**
 * Replacing secrets with `***` in text a human will see: exception messages, the output of external
 * processes, CLI output.
 *
 * The requirement runs through the whole package, so there is one implementation: three independent
 * copies (the installer and both snapshot strategies) would diverge at the very first edit.
 *
 * Known limit, deliberately kept. `mask()` is a blind `str_replace` and stays blind — see
 * {@see SecretFreeMessage} for why anything cleverer would let a real password through. So
 * whenever the package's own prose contains the password as a substring, `mask()` mangles the
 * prose. The package's standard password is `testbench` (`ci/docker-compose.yml`), and it is a
 * substring of the package's own names and paths: counted with the PHP tokenizer over the string
 * literals of `src/` that read as prose (they hold a space), `testbench` occurs in 17 of them,
 * `core` in 39, `test` in 62. The exact numbers age with every edit of the sources, and no commit
 * is named here for them to be re-taken at — the count is here for its order of magnitude, and
 * that does not age: dozens of the package's own sentences, not a corner case.
 *
 * The escape hatch is {@see self::maskMessage()}, and it applies to exception messages only:
 * a text that can prove it holds no secret is printed raw instead of being masked. Everything
 * else — process output, PDO messages, any text the package did not write itself — keeps going
 * through `mask()` and keeps being mangled where a password happens to be a substring of it.
 * That is the price of never leaking, and it is paid on external text, where the damage is a
 * garbled quotation rather than a wrong instruction to the user.
 *
 * @internal
 */
final class Secret
{
    public static function mask(string $text, string ...$secrets): string
    {
        // An empty password is a valid setting (MODX_TESTBENCH_DB_PASS is empty by default) and
        // needs no branch of its own: str_replace with an empty needle returns the text as is.
        foreach ($secrets as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return $text;
    }

    /**
     * The message of an exception: masked, unless the exception's own type vouches that its
     * message cannot carry a secret ({@see SecretFreeMessage}).
     *
     * The default is to mask, so an exception type nobody has audited behaves exactly as it
     * did before this method existed. Masking never weakens by accident here — it weakens only
     * where someone adds the marker interface to a class, and the marker demands a proof about
     * how that class builds its message.
     */
    public static function maskMessage(Throwable $exception, string ...$secrets): string
    {
        return $exception instanceof SecretFreeMessage
            ? $exception->getMessage()
            : self::mask($exception->getMessage(), ...$secrets);
    }
}

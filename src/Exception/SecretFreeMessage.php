<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

/**
 * Marker for an exception whose message cannot carry a secret — so commands print it raw
 * instead of running it through {@see \ModxKit\Testbench\Support\Secret::mask()}.
 *
 * Masking is a blind `str_replace`, and it has to stay blind: the package's standard password
 * is `testbench` (`ci/docker-compose.yml`), a substring of the package's own names and paths,
 * so anything cleverer — word boundaries, minimum length, proximity to `password=` — would let
 * some real password through. Under-masking leaks into someone else's CI log and cannot be
 * taken back; over-masking only ruins the diagnosis. The way out is therefore not a smarter
 * `mask()` but a smaller set of texts it is applied to.
 *
 * The default stays "mask": an exception is masked unless it implements this interface. Only a
 * type that can PROVE, by how its message is built, that no secret can reach it may be marked.
 * The proof lives in the docblock of the implementing class, and it is a proof about
 * construction, not a hope about the usual case.
 *
 * The bar is not met by most of the package. `Secret::mask()` at the console is the only
 * masking on several paths: `GitCloneProvider` embeds raw `git`/`composer` output into a plain
 * `TestbenchException`, `SchemaInventory` and `DatabaseCleaner` embed raw PDO messages,
 * `TransportInstaller` embeds the output of the consumer's own build script. Those exceptions
 * must never be marked.
 */
interface SecretFreeMessage
{
}

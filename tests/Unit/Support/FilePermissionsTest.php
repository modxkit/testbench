<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Unit\Support;

use ModxKit\Testbench\Support\FilePermissions;
use ModxKit\Testbench\Tests\Support\CapturesWarnings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FilePermissionsTest extends TestCase
{
    use CapturesWarnings;

    public function testRestrictsPermissionsQuietlyWhenTheFilesystemAllowsIt(): void
    {
        $file = sys_get_temp_dir() . '/tb-perms-' . bin2hex(random_bytes(4));

        self::assertNotFalse(file_put_contents($file, 'password'));

        try {
            $result = null;
            $warnings = $this->captureWarnings(function () use ($file, &$result): void {
                $result = FilePermissions::restrict($file, 0o600, 'password file.');
            });

            self::assertTrue($result);
            self::assertSame([], $warnings, 'On the successful path the package must print nothing.');

            $mode = fileperms($file);

            self::assertNotFalse($mode);
            self::assertSame('0600', substr(sprintf('%o', $mode), -4));
        } finally {
            unlink($file);
        }
    }

    /**
     * The refusal is loud but not fatal, and there is exactly ONE of it: a raw
     * `chmod(): No such file or directory` must not get ahead of the package's own message, which
     * names the same cause more clearly and adds the next action (the model here being
     * `Workspace::writeOwnershipMarker()`).
     *
     * A missing file is the cheapest way to get `chmod() === false` on a real file system; the
     * observable contract is the same as for any failed `chmod()`: the function returned `false`,
     * the file stayed as it was, and the install is not cancelled.
     */
    public function testWarnsExactlyOnceAndDoesNotThrowWhenPermissionsCannotBeRestricted(): void
    {
        $missing = sys_get_temp_dir() . '/tb-perms-missing-' . bin2hex(random_bytes(4));
        $result = null;

        $warnings = $this->captureWarnings(function () use ($missing, &$result): void {
            $result = FilePermissions::restrict($missing, 0o600, 'password file.');
        });

        self::assertFalse($result);
        self::assertCount(1, $warnings, "Exactly one warning was expected:\n" . implode("\n", $warnings));
        self::assertStringContainsString($missing, $warnings[0]);
        self::assertStringContainsString('0600', $warnings[0]);
        self::assertStringContainsString('password file.', $warnings[0]);
    }
}

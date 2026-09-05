<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Concerns;

use MODX\Revolution\modChunk;
use MODX\Revolution\modResource;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use ModxKit\Testbench\Exception\TestbenchException;
use ModxKit\Testbench\Isolation\IsolationStrategy;
use ModxKit\Testbench\TestCase;
use ModxKit\Testbench\Tests\Integration\Isolation\RecordingIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * The contract of the `InteractsWithModx` trait against a live MODX core.
 *
 * The tests do not depend on the execution order: everything they change is rolled back by the
 * transaction (`TransactionIsolation`), and the changes in the core's memory by
 * `TestCase::tearDown()`.
 */
#[Group('integration')]
final class InteractsWithModxTest extends TestCase
{
    public function testCreatesResourceWithSaneDefaults(): void
    {
        $resource = $this->createResource(['pagetitle' => 'Testbench page']);

        self::assertSame('Testbench page', $resource->get('pagetitle'));
        self::assertSame('web', $resource->get('context_key'));
        $this->assertObjectExists(modResource::class, ['pagetitle' => 'Testbench page']);
    }

    public function testCreateResourceGeneratesUniqueDefaults(): void
    {
        $first = $this->createResource();
        $second = $this->createResource();

        self::assertNotSame($first->get('alias'), $second->get('alias'));
        self::assertNotSame($first->get('pagetitle'), $second->get('pagetitle'));
    }

    /**
     * `pagetitle` is a `varchar(191)` (`core/src/Revolution/mysql/modResource.php:71-79`), so MySQL
     * rejects a longer value and `save()` returns `false`.
     */
    public function testCreateResourceReportsRejectedSave(): void
    {
        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('Failed to save the test modResource');

        $this->createResource(['pagetitle' => str_repeat('p', 300)]);
    }

    public function testCreatesUserWithProfileAndSwitchesIdentity(): void
    {
        $user = $this->createUser(['username' => 'tb-user']);
        $this->actingAs($user);

        self::assertSame('tb-user', $this->modx->user?->get('username'));
        $this->assertObjectExists(modUserProfile::class, ['internalKey' => $user->get('id')]);
    }

    /**
     * The profile fields used to be dropped silently: `createUser(['email' => …])` put `email` into
     * `modUser`, which has no such field (`fromArray()` ignores unknown fields), while the profile
     * got a generated address. A test "checking" a user with a known address was working with a
     * completely different one.
     *
     * The Cyrillic `fullname` below is deliberate and must NOT be replaced with ASCII: it is the
     * only value in this class that makes a round trip through the live database as multibyte text.
     *
     * The exact match through `assertObjectExists()` alone does NOT establish that, and that is
     * measured rather than feared: with `SET NAMES latin1` injected into the boot of the core, the
     * profile row holds 54 bytes of double-encoded rubbish instead of 27 bytes of UTF-8, and the
     * test stays green — the write and the read go through the SAME broken connection, so the
     * distortion is symmetric and therefore invisible. The `HEX()` assertion below is what makes
     * the value visible: `HEX()` is computed by the server over the bytes it actually stored, and
     * the connection charset takes no part in it.
     */
    public function testCreateUserAcceptsProfileFields(): void
    {
        $user = $this->createUser([], ['email' => 'nightly@example.invalid', 'fullname' => 'Ночной сборщик']);

        $this->assertObjectExists(modUserProfile::class, [
            'internalKey' => $user->get('id'),
            'email' => 'nightly@example.invalid',
            'fullname' => 'Ночной сборщик',
        ]);

        $id = $user->get('id');

        // `get()` returns `mixed`, and the value is about to be pasted into SQL. `fail()` returns
        // `never`, so this is both the check and the narrowing.
        if (!is_int($id)) {
            self::fail('The user id came back as ' . get_debug_type($id) . ' rather than an integer.');
        }

        $statement = $this->modx->query(sprintf(
            'SELECT HEX(fullname) FROM %s WHERE internalKey = %d',
            (string) $this->modx->getTableName(modUserProfile::class),
            $id
        ));

        // A refused query would otherwise leave `fetchColumn()` on `false` and the comparison below
        // would fail with a message about the wrong thing.
        self::assertNotFalse($statement, 'The query for the stored bytes of the profile was refused.');

        self::assertSame(
            strtoupper(bin2hex('Ночной сборщик')),
            $statement->fetchColumn(),
            'The bytes the server stored are not the UTF-8 of the value that was written: the '
            . 'connection is re-encoding the text on the way in.'
        );
    }

    /**
     * `username` is a unique index (`core/src/Revolution/mysql/modUser.php:36-44`); a second user
     * with the same name is not saved.
     */
    public function testCreateUserReportsRejectedSave(): void
    {
        $this->createUser(['username' => 'tb-duplicate']);

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('Failed to save the test modUser');

        $this->createUser(['username' => 'tb-duplicate']);
    }

    public function testCreatesChunkAndSnippet(): void
    {
        $chunk = $this->createChunk('tb-chunk', 'hello');
        $snippet = $this->createSnippet('tb-snippet', 'return 1;');

        self::assertSame('hello', $chunk->get('snippet'));
        self::assertSame('return 1;', $snippet->get('snippet'));
        $this->assertObjectExists(modChunk::class, ['name' => 'tb-chunk']);
        $this->assertObjectExists(modSnippet::class, ['name' => 'tb-snippet']);
    }

    public function testCreateChunkReportsRejectedSave(): void
    {
        $this->createChunk('tb-dup-chunk', 'a');

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('Failed to save the test chunk "tb-dup-chunk"');

        $this->createChunk('tb-dup-chunk', 'b');
    }

    public function testCreateSnippetReportsRejectedSave(): void
    {
        $this->createSnippet('tb-dup-snippet', 'a');

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('Failed to save the test snippet "tb-dup-snippet"');

        $this->createSnippet('tb-dup-snippet', 'b');
    }

    public function testSetSettingIsVisibleThroughGetOption(): void
    {
        $this->setSetting('testbench_probe', 'value-42');

        $this->assertSettingEquals('testbench_probe', 'value-42');
        self::assertSame('value-42', $this->modx->getOption('testbench_probe'));
    }

    public function testSetSettingUpdatesExistingRecord(): void
    {
        $this->setSetting('site_name', 'Testbench site');

        $this->assertSettingEquals('site_name', 'Testbench site');
        self::assertSame(1, $this->modx->getCount(modSystemSetting::class, ['key' => 'site_name']));
    }

    public function testSetSettingNormalisesScalars(): void
    {
        $this->setSetting('testbench_bool_true', true);
        $this->setSetting('testbench_bool_false', false);
        $this->setSetting('testbench_int', 42);

        $this->assertSettingEquals('testbench_bool_true', '1');
        $this->assertSettingEquals('testbench_bool_false', '0');
        $this->assertSettingEquals('testbench_int', '42');
    }

    /**
     * The setting key is a `varchar(50)` and the primary key of the table
     * (`core/src/Revolution/mysql/modSystemSetting.php:29-37`): MySQL will not accept a longer one
     * and `save()` returns `false`. A refusal swallowed silently would leave the test with a setting
     * that is not in the database.
     */
    public function testSetSettingReportsRejectedSave(): void
    {
        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('Failed to save system setting');

        $this->setSetting(str_repeat('k', 80), 'value');
    }

    public function testRunsProcessorAndAssertsSuccess(): void
    {
        $chunk = $this->createChunk('tb-chunk', 'hello');

        $response = $this->runProcessor('element/chunk/get', ['id' => $chunk->get('id')]);

        $this->assertProcessorSuccess($response);
    }

    public function testRunsProcessorAndAssertsFailure(): void
    {
        $response = $this->runProcessor('element/chunk/get', ['id' => 0x7FFFFFFF]);

        $this->assertProcessorFailure($response);
    }

    /**
     * A processor OF AN EXTRA is found by a string action only through `$options['processors_path']`
     * — otherwise the core looks for the file relative to its own `config['processors_path']`
     * (`core/src/Revolution/modX.php:1809-1823`). Without the third argument such a call returned a
     * plausible `Requested processor not found` refusal, that is, `assertProcessorFailure()` would
     * go green without ever executing the extra's code.
     *
     * The test holds both halves: with the path the fixture's processor really runs, and without the
     * path the core does not find it — and that is exactly what made the earlier assertion vacuous.
     */
    public function testRunProcessorPassesOptionsThroughToTheCore(): void
    {
        $processorsPath = dirname(__DIR__, 2) . '/Fixtures/SampleExtra/processors/';

        $response = $this->runProcessor(
            'mgr/item/create',
            ['name' => 'nightly'],
            ['processors_path' => $processorsPath]
        );

        $this->assertProcessorSuccess($response);

        $withoutPath = $this->runProcessor('mgr/item/create', ['name' => 'nightly']);

        $this->assertProcessorFailure($withoutPath);
        self::assertSame('Requested processor not found', $withoutPath->getMessage());
    }

    /**
     * The second half of the same defect: with the path passed, a refusal by the processor is a REAL
     * refusal from its validation rather than "file not found".
     */
    public function testRunProcessorWithOptionsReportsTheProcessorsOwnFailure(): void
    {
        $response = $this->runProcessor(
            'mgr/item/create',
            ['name' => ''],
            ['processors_path' => dirname(__DIR__, 2) . '/Fixtures/SampleExtra/processors/']
        );

        $this->assertProcessorFailure($response);
        self::assertSame('sampleextra.name_required', $response->getMessage());
    }

    /**
     * `modX::runProcessor()` is declared as `@return ProcessorResponse|mixed`
     * (`core/src/Revolution/modX.php:1766`), so the trait must check the type rather than trust the
     * signature.
     */
    public function testRunProcessorRejectsForeignReturnValue(): void
    {
        $modx = $this->createStub(modX::class);
        $modx->method('runProcessor')->willReturn('not a ProcessorResponse');

        $probe = InteractsWithModxProbe::forModx($modx);

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('returned string instead of a ProcessorResponse');

        $probe->callRunProcessor('element/chunk/get');
    }

    /**
     * `xPDO::newObject()` returns `null` for a class that is absent from the model
     * (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:806`) — against a live core there is no such class,
     * so the path is checked against the stand-in.
     */
    public function testCreateChunkReportsMissingModelClass(): void
    {
        $modx = $this->createStub(modX::class);
        $modx->method('newObject')->willReturn(null);

        $probe = InteractsWithModxProbe::forModx($modx);

        $this->expectException(TestbenchException::class);
        $this->expectExceptionMessage('is not registered in the xPDO model');

        $probe->callCreateChunk('tb-chunk', 'hello');
    }

    public function testTriggerEventReturnsFalseForUnknownEvent(): void
    {
        self::assertFalse($this->triggerEvent('OnNoSuchTestbenchEvent'));
    }

    public function testAssertObjectMissingForUnknownRecord(): void
    {
        $this->assertObjectMissing(modChunk::class, ['name' => 'no-such-chunk']);
    }

    /**
     * The transaction rolls the database back but not the core's memory: `$modx->user` and
     * `$modx->config` would survive the test and leak into the next one. This is checked against a
     * stand-in for the base `TestCase` that shares one and the same core instance with the current
     * test.
     */
    public function testRuntimeStateIsRestoredOnTearDown(): void
    {
        $originalUser = $this->modx->user;
        $originalSiteName = $this->modx->getOption('site_name');

        $probe = InteractsWithModxProbe::forModx($this->modx);
        $user = $this->createUser(['username' => 'tb-leak-probe']);

        $probe->callActingAs($user);
        $probe->callSetSetting('testbench_leak_probe', 'temporary');
        // Twice: the backup must remember the value BEFORE the first write, otherwise a repeated call
        // would "restore" a value that had already been substituted.
        $probe->callSetSetting('site_name', 'Testbench draft');
        $probe->callSetSetting('site_name', 'Testbench site');

        self::assertSame($user, $this->modx->user);
        self::assertSame('temporary', $this->modx->getOption('testbench_leak_probe'));
        self::assertSame('Testbench site', $this->modx->getOption('site_name'));

        $probe->callTearDown();

        self::assertSame($originalUser, $this->modx->user);
        self::assertArrayNotHasKey('testbench_leak_probe', $this->modx->config);
        self::assertSame($originalSiteName, $this->modx->getOption('site_name'));
    }

    /**
     * The user stays the same after the first `actingAs()`: a repeated call must not remember an
     * already substituted user as the "original" one.
     */
    public function testRepeatedActingAsRestoresTheOriginalUser(): void
    {
        $originalUser = $this->modx->user;

        $probe = InteractsWithModxProbe::forModx($this->modx);
        $probe->callActingAs($this->createUser(['username' => 'tb-first']));
        $probe->callActingAs($this->createUser(['username' => 'tb-second']));
        $probe->callTearDown();

        self::assertSame($originalUser, $this->modx->user);
    }

    /**
     * A failed restoration must not leave the transaction open: the next test would get "There is
     * already an active transaction" instead of the real cause. An isolation without a transaction is
     * substituted — this test does not risk its own current transaction.
     */
    public function testTearDownClosesIsolationEvenWhenRuntimeRestoreFails(): void
    {
        $recorder = new RecordingIsolation(new class () implements IsolationStrategy {
            public function begin(modX $modx): void
            {
            }

            public function end(modX $modx): void
            {
            }
        });

        $probe = FailingRestoreProbe::withStrategy($recorder);
        $probe->callSetUp();

        try {
            $probe->callTearDown();
            self::fail('An exception from restoreModxRuntimeState() was expected.');
        } catch (TestbenchException) {
            // Expected: all that matters is that the isolation closed all the same.
        }

        self::assertSame(['begin', 'end'], $recorder->calls());
    }
}

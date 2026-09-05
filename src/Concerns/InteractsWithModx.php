<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Concerns;

use MODX\Revolution\modChunk;
use MODX\Revolution\modResource;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\Processors\ProcessorResponse;
use ModxKit\Testbench\Exception\TestbenchException;
use xPDO\Om\xPDOObject;

/**
 * Factories for MODX objects, running processors and events, assertions over the xPDO model.
 *
 * Everything the trait writes to the database is rolled back by the `TransactionIsolation`
 * transaction. Changes in the core's memory (`$modx->user`, `$modx->config`) are not rolled back by
 * a transaction, so the trait remembers the original values and puts them back in
 * `restoreModxRuntimeState()`, which is called by `TestCase::tearDown()`.
 *
 * @property \MODX\Revolution\modX $modx
 */
trait InteractsWithModx
{
    /**
     * The original `$modx->config` values for the keys `setSetting()` touched. The array key is
     * the setting key, the value is a pair of "was the key in the config" and its value.
     *
     * @var array<string, array{bool, mixed}>
     */
    private array $modxOptionBackups = [];

    /** `$modx->user` is remembered on the first `actingAs()`, not on every one. */
    private bool $modxUserBackedUp = false;

    private ?modUser $modxUserBackup = null;

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createResource(array $attributes = []): modResource
    {
        $suffix = bin2hex(random_bytes(4));

        $resource = $this->newModxObject(modResource::class);
        $resource->fromArray(array_merge([
            'pagetitle' => 'Testbench resource ' . $suffix,
            'alias' => 'testbench-' . $suffix,
            'context_key' => 'web',
            'published' => true,
            'template' => 0,
        ], $attributes));

        if (!$resource->save()) {
            throw new TestbenchException(
                'Failed to save the test modResource: MODX rejected the write. Check the length and '
                . 'uniqueness of the fields passed (pagetitle and alias are limited to 191 '
                . 'characters) and the details in core/cache/logs/error.log of the working environment.'
            );
        }

        return $resource;
    }

    /**
     * Creates a user together with a profile.
     *
     * MODX saves a `modUser` without a `modUserProfile` too (the `Profile` composite is declared in
     * `core/src/Revolution/mysql/modUser.php:212-219`), but a user without a profile is a trap:
     * `modUser::getPhoto()` reaches for `$this->Profile->photo` without a check
     * (`core/src/Revolution/modUser.php:1002`), and `Security\User\Get::cleanup()` builds its
     * response from the profile fields
     * (`core/src/Revolution/Processors/Security/User/Get.php:98-115`). The profile is a composite
     * with `owner => local`, so it is saved together with the user.
     *
     * The profile fields are given as the SECOND argument rather than alongside the user fields:
     * `email` and `fullname` live in `modUserProfile`, and `modUser::fromArray()` silently ignores
     * fields unknown to it — an address passed in `$attributes` simply vanished and the profile got
     * a generated one.
     *
     * @param array<string, mixed> $attributes `modUser` fields.
     * @param array<string, mixed> $profile    `modUserProfile` fields; they override the defaults.
     */
    protected function createUser(array $attributes = [], array $profile = []): modUser
    {
        $suffix = bin2hex(random_bytes(4));

        $user = $this->newModxObject(modUser::class);
        $user->fromArray(array_merge([
            'username' => 'testbench-' . $suffix,
            'active' => true,
        ], $attributes));

        $profileObject = $this->newModxObject(modUserProfile::class);
        $profileObject->fromArray(array_merge([
            'email' => 'testbench-' . $suffix . '@example.invalid',
            'fullname' => 'Testbench user ' . $suffix,
        ], $profile));
        $user->addOne($profileObject);

        if (!$user->save()) {
            throw new TestbenchException(
                'Failed to save the test modUser: MODX rejected the write. The user name must be '
                . 'unique and no longer than 100 characters; the details are in '
                . 'core/cache/logs/error.log of the working environment.'
            );
        }

        return $user;
    }

    protected function createChunk(string $name, string $content): modChunk
    {
        $chunk = $this->newModxObject(modChunk::class);
        $chunk->fromArray(['name' => $name, 'snippet' => $content]);

        if (!$chunk->save()) {
            throw new TestbenchException(
                "Failed to save the test chunk \"{$name}\": MODX rejected the write. The chunk "
                . 'name must be unique and no longer than 50 characters; the details are in '
                . 'core/cache/logs/error.log of the working environment.'
            );
        }

        return $chunk;
    }

    protected function createSnippet(string $name, string $content): modSnippet
    {
        $snippet = $this->newModxObject(modSnippet::class);
        $snippet->fromArray(['name' => $name, 'snippet' => $content]);

        if (!$snippet->save()) {
            throw new TestbenchException(
                "Failed to save the test snippet \"{$name}\": MODX rejected the write. The snippet "
                . 'name must be unique and no longer than 50 characters; the details are in '
                . 'core/cache/logs/error.log of the working environment.'
            );
        }

        return $snippet;
    }

    /**
     * Creates or updates a system setting and synchronises its value in the core's memory.
     *
     * `$modx->getOption()` reads only `$modx->config` (`xPDO.php:711-741`), and the config is
     * assembled from the settings cache during initialisation, so a fresh row in the table is not
     * by itself visible through `getOption()` — verified on MODX 3.2.3-pl.
     */
    protected function setSetting(string $key, string|int|bool $value): void
    {
        $normalized = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        $setting = $this->modx->getObject(modSystemSetting::class, ['key' => $key]);

        if (!$setting instanceof modSystemSetting) {
            $setting = $this->newModxObject(modSystemSetting::class);
            $setting->set('key', $key);
            $setting->set('namespace', 'core');
            $setting->set('xtype', 'textfield');
        }

        $setting->set('value', $normalized);

        // On failure `xPDOObject::save()` returns `false` rather than throwing
        // (`xPDOObject.php:1326`): a swallowed result would leave the test with a setting that is
        // visible through `getOption()` but absent from the database.
        if (!$setting->save()) {
            throw new TestbenchException(
                "Failed to save system setting \"{$key}\": MODX rejected the write. The setting key "
                . 'is the primary key of the table and is no longer than 50 characters; the details '
                . 'are in core/cache/logs/error.log of the working environment.'
            );
        }

        $this->backupModxOption($key);
        $this->modx->setOption($key, $normalized);
    }

    /**
     * Substitutes the core's current user.
     *
     * We confine ourselves to `$modx->user`: that is exactly the property read by
     * `modX::getLoginUserID()` (`core/src/Revolution/modX.php:1877-1891`) and
     * `modAccessibleObject::checkPolicy()` (`core/src/Revolution/modAccessibleObject.php:250-255`).
     * The core reads no separate setting such as `testbench_acting_user` — such a call would be
     * dead code.
     */
    protected function actingAs(modUser $user): void
    {
        if (!$this->modxUserBackedUp) {
            $this->modxUserBackup = $this->modx->user;
            $this->modxUserBackedUp = true;
        }

        $this->modx->user = $user;
    }

    /**
     * The third argument is passed into the core as is — for the sake of
     * `$options['processors_path']`.
     *
     * Given a string action (`'mgr/job/create'`) the core looks for the processor file relative to
     * ITS OWN `config['processors_path']` (`core/src/Revolution/modX.php:1809-1823`), so an extra's
     * processor is never found without that path, while the response looks plausible:
     * `success => false`, "Requested processor not found". A test with `assertProcessorFailure()`
     * on such a call went green VACUOUSLY — it passed on knowingly valid input too, because the
     * extra's validation was never executed at all.
     *
     * Addressing a processor by its full class name (`Create::class`) remains the shortest path and
     * requires no `$options`; see docs/DX_GUIDE.md §5.
     *
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $options
     */
    protected function runProcessor(
        string $action,
        array $properties = [],
        array $options = [],
    ): ProcessorResponse {
        $response = $this->modx->runProcessor($action, $properties, $options);

        // `modX::runProcessor()` is declared as `@return ProcessorResponse|mixed`
        // (`core/src/Revolution/modX.php:1766`): a processor with an overridden `run()` is free to
        // return anything, and without the check the test would get a TypeError instead of an
        // explainable error.
        if (!$response instanceof ProcessorResponse) {
            throw new TestbenchException(sprintf(
                'Processor "%s" returned %s instead of a ProcessorResponse. Make sure the processor '
                . 'class extends MODX\Revolution\Processors\Processor and that its run() method '
                . 'returns a ProcessorResponse.',
                $action,
                get_debug_type($response)
            ));
        }

        return $response;
    }

    /**
     * Invokes a MODX system event.
     *
     * `modX::invokeEvent()` returns `false` if the event is unknown or has no active plugins, and an
     * array of the plugins' results otherwise (`core/src/Revolution/modX.php:1704-1757`).
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, mixed>|bool
     */
    protected function triggerEvent(string $name, array $params = []): array|bool
    {
        return $this->modx->invokeEvent($name, $params);
    }

    /**
     * @param class-string<xPDOObject> $class
     * @param array<string, mixed>     $criteria
     */
    protected function assertObjectExists(string $class, array $criteria): void
    {
        self::assertNotNull(
            $this->modx->getObject($class, $criteria),
            sprintf(
                'Expected an object %s matching the criteria %s, but it was not found.',
                $class,
                var_export($criteria, true)
            )
        );
    }

    /**
     * @param class-string<xPDOObject> $class
     * @param array<string, mixed>     $criteria
     */
    protected function assertObjectMissing(string $class, array $criteria): void
    {
        self::assertNull(
            $this->modx->getObject($class, $criteria),
            sprintf(
                'An object %s matching the criteria %s must not exist.',
                $class,
                var_export($criteria, true)
            )
        );
    }

    protected function assertProcessorSuccess(ProcessorResponse $response): void
    {
        self::assertFalse(
            $response->isError(),
            'The processor failed with an error: ' . $response->getMessage()
        );
    }

    protected function assertProcessorFailure(ProcessorResponse $response): void
    {
        self::assertTrue($response->isError(), 'A processor error was expected, but it finished successfully.');
    }

    protected function assertSettingEquals(string $key, string $expected): void
    {
        $setting = $this->modx->getObject(modSystemSetting::class, ['key' => $key]);

        self::assertNotNull($setting, "System setting \"{$key}\" was not found in the database.");
        self::assertSame($expected, $setting->get('value'));
    }

    /**
     * Returns the core's memory to its pre-test state.
     *
     * A transaction rolls back the database alone: the user substituted by `actingAs()` and the
     * values written by `setSetting()` would stay in `$modx` and leak into the next test — there is
     * one core instance per process (`TestbenchKernel::modx()`).
     *
     * No branch touches `$this->modx` until a backup has appeared, and backups appear only after
     * the core has been addressed. That is why the method is safe even when `setUp()` failed before
     * the core was booted and the typed `$modx` property stayed uninitialised
     * (`TestCaseContractTest::testTearDownIsSilentWhenSetUpFailedBeforeIsolationStarted`).
     */
    protected function restoreModxRuntimeState(): void
    {
        if ($this->modxUserBackedUp) {
            $this->modx->user = $this->modxUserBackup;
            $this->modxUserBackedUp = false;
            $this->modxUserBackup = null;
        }

        foreach ($this->modxOptionBackups as $key => [$existed, $value]) {
            if ($existed) {
                $this->modx->config[$key] = $value;
            } else {
                unset($this->modx->config[$key]);
            }
        }

        $this->modxOptionBackups = [];
    }

    private function backupModxOption(string $key): void
    {
        if (array_key_exists($key, $this->modxOptionBackups)) {
            return;
        }

        $this->modxOptionBackups[$key] = array_key_exists($key, $this->modx->config)
            ? [true, $this->modx->config[$key]]
            : [false, null];
    }

    /**
     * `xPDO::newObject()` returns `null` if the class is not found in the loaded model
     * (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:806-817`) — without the check the test would die on
     * a method call on `null`.
     *
     * @template TObject of xPDOObject
     *
     * @param class-string<TObject> $class
     *
     * @return TObject
     */
    private function newModxObject(string $class): xPDOObject
    {
        $object = $this->modx->newObject($class);

        if ($object === null) {
            throw new TestbenchException(sprintf(
                'MODX could not create the object %s: the class is not registered in the xPDO model. '
                . 'Add the model package with $modx->addPackage() before creating the object.',
                $class
            ));
        }

        return $object;
    }
}

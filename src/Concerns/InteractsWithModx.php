<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Concerns;

use MODX\Revolution\modChunk;
use MODX\Revolution\modResource;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroup;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MODX\Revolution\Processors\ProcessorResponse;
use ModxKit\Testbench\Exception\TestbenchException;
use ReflectionProperty;
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

    /** Set by `enforcePermissions()`; cleared by `restoreModxRuntimeState()`. */
    private bool $permissionsEnforced = false;

    /** The value `modX::$_sessionState` held before `enforcePermissions()` replaced it. */
    private ?int $modxSessionStateBackup = null;

    /**
     * The `$_SESSION` superglobal as it was before `enforcePermissions()` emptied it; `null` means
     * the superglobal did not exist at all and has to be removed again, not set to `[]`.
     *
     * @var array<mixed>|null
     */
    private ?array $phpSessionBackup = null;

    /** Distinguishes "there was no `$_SESSION`" from "there was an empty one". */
    private bool $phpSessionExisted = false;

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

    /**
     * Puts the user into a group, in a role that really grants something.
     *
     * The role is the half that is easy to get wrong, which is why it has a default rather than
     * being left to the caller. `modAccessContext::loadAttributes()` joins the ACL with
     * `mr.authority <= acl.authority` (`core/src/Revolution/modAccessContext.php:42-49`), and a
     * default install gives the context ACL authority 0. `Super User` carries authority 0 and
     * satisfies it; `Member` carries 9999 and satisfies nothing, so a membership in that role
     * leaves the user's attribute set empty and the user refused exactly as if no membership
     * existed. Pass `Member` explicitly when that refusal is what the test is about.
     *
     * The group and the role are looked up by name and MUST already exist: creating them silently
     * would turn a mistyped name into a membership in a group nobody meant, and the test would go
     * green for the wrong reason. A name that is not found raises with the list of names that are.
     *
     * Under `enforcePermissions()` the user's ACL attributes are reloaded right away
     * (`modPrincipal::getAttributes()` with `$reload`), so a membership added AFTER the first
     * permission check takes effect immediately. Without that the core would keep answering from
     * the copy it cached in `$_SESSION` on that first check — measured: the pre-join answer
     * survived the join.
     *
     * @param string $group The group's `name`, as the manager shows it, e.g. `Administrator`.
     * @param string $role  The role's `name`, e.g. `Super User` or `Member`.
     */
    protected function joinUserGroup(
        modUser $user,
        string $group,
        string $role = 'Super User',
    ): modUserGroupMember {
        $groupObject = $this->modx->getObject(modUserGroup::class, ['name' => $group]);

        if (!$groupObject instanceof modUserGroup) {
            throw new TestbenchException(sprintf(
                'MODX has no user group named "%s". The groups that exist are: %s. The name is the '
                . '`name` field of modUserGroup, spelled as the manager shows it.',
                $group,
                $this->existingNames(modUserGroup::class)
            ));
        }

        $roleObject = $this->modx->getObject(modUserGroupRole::class, ['name' => $role]);

        if (!$roleObject instanceof modUserGroupRole) {
            throw new TestbenchException(sprintf(
                'MODX has no user group role named "%s". The roles that exist are: %s.',
                $role,
                $this->existingNames(modUserGroupRole::class)
            ));
        }

        $member = $this->newModxObject(modUserGroupMember::class);
        $member->set('user_group', $groupObject->get('id'));
        $member->set('member', $user->get('id'));
        $member->set('role', $roleObject->get('id'));

        if (!$member->save()) {
            $username = $user->get('username');

            throw new TestbenchException(sprintf(
                'Failed to save the membership of user "%s" in the group "%s": MODX rejected the '
                . 'write. The user must already be saved, and the same user cannot be added to the '
                . 'same group twice; the details are in core/cache/logs/error.log of the working '
                . 'environment.',
                is_string($username) ? $username : '(no username)',
                $group
            ));
        }

        if ($this->permissionsEnforced) {
            $user->getAttributes([], '', true);
        }

        return $member;
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

        // `modUser::loadAttributes()` caches the ACL attributes in
        // `$_SESSION["modx.user.{$id}.attributes"]` and reads them back on the next call
        // (`core/src/Revolution/modUser.php:167-194`). Under the transaction the ids are reused
        // between tests, so a stale entry would answer for a DIFFERENT user carrying the same id.
        // The whole superglobal goes rather than one key: under enforcement it is ours from the
        // start (`enforcePermissions()` emptied it), and nothing there outlives the test anyway.
        // It matters only while the policies are really evaluated.
        if ($this->permissionsEnforced) {
            $_SESSION = [];
        }
    }

    /**
     * Makes MODX really evaluate access policies instead of allowing everything.
     *
     * Without this call a level 2 test CANNOT prove that a processor asks for the permission it
     * ought to. `modX::hasPermission()` forwards to `modContext::checkPolicy()`, and the entire
     * body of `modAccessibleObject::checkPolicy()` sits behind
     * `getSessionState() == modX::SESSION_STATE_INITIALIZED`
     * (`core/src/Revolution/modAccessibleObject.php:252`); past that guard the method falls through
     * to `return true`. Under PHPUnit the guard can never hold by itself: `getSessionState()`
     * answers `SESSION_STATE_UNAVAILABLE` whenever `XPDO_CLI_MODE` is true
     * (`core/src/Revolution/modX.php:2281-2291`), and that constant is `PHP_SAPI === 'cli'`
     * (`core/vendor/xpdo/xpdo/src/xPDO/xPDO.php:34-39`). Measured on MODX 3.2.3-pl: a user with no
     * groups at all got `true` for `save_document` and `true` for a permission that does not exist.
     *
     * After the call the same questions are answered from the database. Measured on the same core:
     * the groupless user gets `false` for both, a member of `Administrator` holding the `Super
     * User` role gets `true` for `save_document`, and a `sudo` user keeps getting `true` — the
     * short circuit at `modAccessibleObject.php:256-258`.
     *
     * `restoreModxRuntimeState()` puts the state back in `TestCase::tearDown()`, so the mode lasts
     * one test and never leaks into the next.
     *
     * Two consequences to know before switching it on:
     *
     * - `modAccessibleObject::save()` and `::remove()` check `save`/`remove` through the same
     *   method (`modAccessibleObject.php:210-218` and `225-233`), so fixtures built while acting as a restricted
     *   user can start being refused. Build them before `actingAs()`, or under a `sudo` user.
     * - The check is answered for the CURRENT context, and the testbench core is booted in `web`.
     *   A default install carries a `modAccessContext` row for `web` as well as for `mgr`, so
     *   manager permissions do resolve — but they resolve through the `web` row.
     *
     * There is no public API for this. `modX::$_sessionState` is protected, and `startSession()`,
     * its only public writer, acts only while the property is still
     * `SESSION_STATE_UNINITIALIZED` (`modX.php:2456-2468`) — which `initialize()` has already left
     * behind by the time a test runs. The property exists with the same meaning on both supported
     * lines: verified against 3.1.2-pl and 3.2.3-pl. The line numbers above are those of 3.2.3-pl;
     * on 3.1.2-pl the same code sits elsewhere in `modX.php`, so check by symbol, not by number.
     */
    protected function enforcePermissions(): void
    {
        if ($this->permissionsEnforced) {
            return;
        }

        $property = new ReflectionProperty(modX::class, '_sessionState');

        $state = $property->getValue($this->modx);

        // The core declares the property as an int and never assigns anything else
        // (`modX.php:243`, `2281-2291`, `2456-2468`). Anything else here means the core has changed
        // shape, and a wrong backup would leave the NEXT test with a session state nobody chose.
        if (!is_int($state)) {
            throw new TestbenchException(sprintf(
                'MODX holds %s in modX::$_sessionState instead of an int, so enforcePermissions() '
                . 'cannot restore the value it is about to replace. The supported core lines are '
                . '3.1.2-pl and 3.2.3-pl.',
                get_debug_type($state)
            ));
        }

        $this->modxSessionStateBackup = $state;
        $property->setValue($this->modx, modX::SESSION_STATE_INITIALIZED);

        // `loadAttributes()` reads and writes `$_SESSION` as a plain array and never asks whether a
        // real PHP session was started, so an empty superglobal is all the core needs. It is
        // emptied rather than left alone: whatever an earlier test cached there is keyed by user id,
        // and the ids come back after every rollback.
        $this->phpSessionExisted = array_key_exists('_SESSION', $GLOBALS);
        $this->phpSessionBackup = $this->phpSessionExisted ? $_SESSION : null;
        $_SESSION = [];

        $this->permissionsEnforced = true;
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

        if ($this->permissionsEnforced) {
            (new ReflectionProperty(modX::class, '_sessionState'))
                ->setValue($this->modx, $this->modxSessionStateBackup);

            if ($this->phpSessionExisted) {
                $_SESSION = $this->phpSessionBackup;
            } else {
                // `unset($_SESSION)` inside a method drops the local binding and leaves the
                // superglobal in place; the global entry is what has to go, so that the next test
                // sees exactly what this one saw — no `$_SESSION` at all.
                unset($GLOBALS['_SESSION']);
            }

            $this->permissionsEnforced = false;
            $this->modxSessionStateBackup = null;
            $this->phpSessionBackup = null;
            $this->phpSessionExisted = false;
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

    /**
     * The `name` values present for the class, for an error message that says what IS there rather
     * than only what is not.
     *
     * @param class-string<xPDOObject> $class
     */
    private function existingNames(string $class): string
    {
        $names = [];

        foreach ($this->modx->getCollection($class) as $object) {
            $name = $object->get('name');

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names === [] ? '(none at all)' : implode(', ', $names);
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

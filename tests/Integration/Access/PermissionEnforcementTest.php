<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Access;

use MODX\Revolution\Error\modError;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroup;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modX;
use ModxKit\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What `InteractsWithModx::enforcePermissions()` changes, measured against a live core.
 *
 * The first test is the reason the rest exist: without the call the core answers "allowed" to
 * everything, so a permission check written in an extra cannot be tested at all — a test would go
 * green on a user who holds nothing.
 *
 * The tests do not depend on the order: every row they add is rolled back by the transaction, the
 * session state is put back by `TestCase::tearDown()` through `restoreModxRuntimeState()`, and the
 * first test asserts the default rather than assuming a previous test left it alone.
 */
#[Group('integration')]
final class PermissionEnforcementTest extends TestCase
{
    /**
     * A permission nothing in a default install defines. Its answer separates "the policy allows
     * it" from "nothing is being evaluated at all": a real evaluation cannot allow a permission
     * that appears in no policy.
     */
    private const UNKNOWN_PERMISSION = 'testbench_permission_that_does_not_exist';

    public function testWithoutEnforcementEveryPermissionIsGranted(): void
    {
        $this->actingAs($this->createUser());

        self::assertSame(
            modX::SESSION_STATE_UNAVAILABLE,
            $this->modx->getSessionState(),
            'under PHP_SAPI=cli the core reports no session'
        );
        self::assertTrue(
            $this->modx->hasPermission('save_document'),
            'a user with no groups is granted save_document'
        );
        self::assertTrue(
            $this->modx->hasPermission(self::UNKNOWN_PERMISSION),
            'and a permission that exists nowhere is granted too'
        );
    }

    public function testEnforcementDeniesAUserWithoutGroups(): void
    {
        $this->enforcePermissions();
        $this->actingAs($this->createUser());

        self::assertSame(modX::SESSION_STATE_INITIALIZED, $this->modx->getSessionState());
        self::assertFalse($this->modx->hasPermission('save_document'));
        self::assertFalse($this->modx->hasPermission(self::UNKNOWN_PERMISSION));
    }

    public function testEnforcementGrantsAMemberOfAdministrator(): void
    {
        $user = $this->createUser();
        $this->joinAdministrators($user);

        $this->enforcePermissions();
        $this->actingAs($user);

        self::assertTrue(
            $this->modx->hasPermission('save_document'),
            'the Administrator policy carries save_document'
        );
        self::assertFalse(
            $this->modx->hasPermission(self::UNKNOWN_PERMISSION),
            'but it carries nothing beyond what it lists'
        );
    }

    /**
     * `checkPolicy()` returns `true` for a `sudo` user before it looks at any policy
     * (`core/src/Revolution/modAccessibleObject.php:256-258`), so such a user is not a witness that
     * a check happened at all.
     */
    public function testSudoUserPassesEvenAnUnknownPermission(): void
    {
        $sudoer = $this->createUser();
        // `sudo` cannot be mass-assigned: `modUser::set()` refuses the field outside setup mode
        // (`core/src/Revolution/modUser.php:55-62`), so it never arrives through `createUser()`.
        self::assertTrue($sudoer->setSudo(true));
        self::assertTrue($sudoer->save());
        self::assertTrue((bool) $sudoer->get('sudo'), 'the flag really reached the row');

        $this->enforcePermissions();
        $this->actingAs($sudoer);

        self::assertTrue($this->modx->hasPermission('save_document'));
        self::assertTrue($this->modx->hasPermission(self::UNKNOWN_PERMISSION));
    }

    public function testProcessorGuardedByAPermissionIsRefusedAndThenAllowed(): void
    {
        $this->enforcePermissions();

        $this->actingAs($this->createUser());
        // The `action` property is passed for the core's sake, not the processor's: the refusal
        // branch feeds `getProperty('action')` to `preg_replace()`
        // (`core/src/Revolution/Processors/Processor.php:193-197`), and a processor addressed by
        // class name has no such property — PHP 8.4 then reports passing null as deprecated.
        $denied = $this->runProcessor(PermissionGatedProcessor::class, ['action' => 'gated']);
        $this->assertProcessorFailure($denied);

        // Two processor calls in one test share `$modx->error`, which no transaction rolls back:
        // without the reset the refusal above travels inside the response of the call below.
        // `$modx->error` is declared nullable because the core resets it to null when leaving a
        // context; the assertion is the narrowing, not a separate check.
        self::assertInstanceOf(modError::class, $this->modx->error);
        $this->modx->error->reset();

        $privileged = $this->createUser();
        $this->joinAdministrators($privileged);
        $this->actingAs($privileged);

        $this->assertProcessorSuccess(
            $this->runProcessor(PermissionGatedProcessor::class, ['action' => 'gated'])
        );
    }

    /**
     * The mode lasts one test. `tearDown()` calls the same method, and a second call has to be
     * harmless — that is what the repeated invocation below checks.
     *
     * What `$_SESSION` looked like beforehand is READ rather than assumed. It is not always absent:
     * `modUser::getSessionContexts()` writes `modx.user.contextTokens` into it
     * (`core/src/Revolution/modUser.php:352-370`), so whether the
     * superglobal exists at all depends on which tests ran earlier — and an assertion that it is
     * absent fails under `--order-by=reverse` and under a random order, while passing in the
     * declaration order. The invariant that does hold in every order is the one asserted here:
     * afterwards everything is exactly as it was found.
     */
    public function testStateIsPutBackByTheRuntimeRestore(): void
    {
        $sessionBefore = $this->sessionSnapshot();
        $stateBefore = $this->modx->getSessionState();

        $this->enforcePermissions();
        $this->actingAs($this->createUser());

        self::assertSame(modX::SESSION_STATE_INITIALIZED, $this->modx->getSessionState());
        self::assertNotNull($this->sessionSnapshot(), 'the mode needs the superglobal');

        $this->restoreModxRuntimeState();
        $this->restoreModxRuntimeState();

        self::assertSame($stateBefore, $this->modx->getSessionState());
        self::assertSame($sessionBefore, $this->sessionSnapshot());
        self::assertTrue(
            $this->modx->hasPermission(self::UNKNOWN_PERMISSION),
            'and the core is back to granting everything'
        );
    }

    /**
     * `$_SESSION` as it stands, with "no superglobal at all" told apart from "an empty one" by
     * `null`. It is read through a method rather than inline so that an earlier assertion about
     * the key cannot teach the static analyser that the key is always there.
     *
     * @return array<mixed>|null
     */
    private function sessionSnapshot(): ?array
    {
        return array_key_exists('_SESSION', $GLOBALS) ? $_SESSION : null;
    }

    /**
     * The role decides whether a membership grants anything: `modAccessContext::loadAttributes()`
     * joins the ACL with `mr.authority <= acl.authority`
     * (`core/src/Revolution/modAccessContext.php:42-49`). A default install gives the context ACL
     * authority 0, and only the `Super User` role (authority 0) satisfies it — `Member`
     * (authority 9999) leaves the user with an empty attribute set and therefore with nothing.
     */
    private function joinAdministrators(modUser $user): void
    {
        $group = $this->modx->getObject(modUserGroup::class, ['name' => 'Administrator']);
        $role = $this->modx->getObject(modUserGroupRole::class, ['name' => 'Super User']);

        self::assertNotNull($group, 'a default install carries the Administrator group');
        self::assertNotNull($role, 'a default install carries the Super User role');

        $member = $this->modx->newObject(modUserGroupMember::class);
        self::assertNotNull($member);

        $member->set('user_group', $group->get('id'));
        $member->set('member', $user->get('id'));
        $member->set('role', $role->get('id'));

        self::assertTrue($member->save());
    }
}

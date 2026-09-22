<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\CapabilityRegistry;
use App\Core\Security\UserCapabilityOverridePolicy;
use App\Core\Security\UserRoleGuardService;
use App\Entity\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserCapabilityOverridePolicy::class)]
final class UserCapabilityOverridePolicyTest extends TestCase
{
    public function testGrantIsRefusedForSystemPrefixAndUnscopedSiblings(): void
    {
        $policy = $this->policy();

        self::assertTrue($policy->canGrant('content.moderate'));
        self::assertTrue($policy->canGrant('forum.post.edit.own'));
        self::assertFalse($policy->canGrant('system.aacp.access'));
        self::assertFalse($policy->canGrant('system.users.manage'));
        self::assertFalse($policy->canGrant('forum.post.edit'));
        self::assertFalse($policy->canGrant('unknown.capability'));
    }

    public function testLastAdminCannotBeDeniedProtectedCapabilities(): void
    {
        $admin = (new User('last-admin@example.test'))
            ->setPassword('x')
            ->setCpaliusRoles([UserRoleGuardService::ROLE_ADMIN])
            ->setStatus(User::STATUS_ACTIVE);

        $users = $this->createMock(UserRepository::class);
        $users->method('findByRole')->willReturn([$admin]);

        $policy = $this->policy($users);

        self::assertFalse($policy->canDeny('system.aacp.access', $admin, null));
        self::assertTrue($policy->canDeny('content.moderate', $admin, null));
    }

    public function testMemberCanBeDeniedProtectedCapabilities(): void
    {
        $member = (new User('member@example.test'))
            ->setPassword('x')
            ->setCpaliusRoles([UserRoleGuardService::ROLE_MEMBER])
            ->setStatus(User::STATUS_ACTIVE);

        $users = $this->createMock(UserRepository::class);
        $users->method('findByRole')->willReturn([]);

        $policy = $this->policy($users);

        self::assertTrue($policy->canDeny('system.aacp.access', $member, null));
    }

    private function policy(?UserRepository $users = null): UserCapabilityOverridePolicy
    {
        $registry = new CapabilityRegistry();
        $registry->registerMany([
            'content.moderate',
            'system.aacp.access',
            'system.users.manage',
            'forum.post.edit',
            'forum.post.edit.own',
            'forum.post.edit.any',
        ]);

        return new UserCapabilityOverridePolicy(
            $registry,
            $users ?? $this->createMock(UserRepository::class),
        );
    }
}

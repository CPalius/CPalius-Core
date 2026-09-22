<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Security\Entity\UserCapabilityOverride;
use App\Core\Security\Repository\UserCapabilityOverrideRepository;
use App\Core\Security\UserCapabilityOverrideStore;
use App\Core\Security\UserCapabilityOverrideWriter;
use App\Core\Security\Voter\CPaliusVoter;
use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(CPaliusVoter::class)]
#[CoversClass(UserCapabilityOverrideWriter::class)]
final class UserCapabilityOverrideTest extends IntegrationTestCase
{
    public function testDenyWinsOverRoleUnion(): void
    {
        $this->pushRequest();
        $user = $this->authenticateAs('member', 'overlay-deny@example.test');

        /** @var AuthorizationCheckerInterface $auth */
        $auth = $this->container()->get(AuthorizationCheckerInterface::class);
        self::assertTrue($auth->isGranted('account.profile.edit'));

        $this->em()->persist(new UserCapabilityOverride(
            (int) $user->getId(),
            'account.profile.edit',
            UserCapabilityOverride::EFFECT_DENY,
        ));
        $this->em()->flush();
        $this->forgetStore($user);

        self::assertFalse($auth->isGranted('account.profile.edit'));
    }

    public function testDelegableGrantAddsACapabilityTheRoleDoesNotHold(): void
    {
        $this->pushRequest();
        $user = $this->authenticateAs('member', 'overlay-grant@example.test');

        /** @var AuthorizationCheckerInterface $auth */
        $auth = $this->container()->get(AuthorizationCheckerInterface::class);
        self::assertFalse($auth->isGranted('content.moderate'));

        $this->em()->persist(new UserCapabilityOverride(
            (int) $user->getId(),
            'content.moderate',
            UserCapabilityOverride::EFFECT_GRANT,
        ));
        $this->em()->flush();
        $this->forgetStore($user);

        self::assertTrue($auth->isGranted('content.moderate'));
    }

    public function testVoterIgnoresALockedGrantRow(): void
    {
        $this->pushRequest();
        $user = $this->authenticateAs('member', 'overlay-locked-grant@example.test');

        $this->em()->persist(new UserCapabilityOverride(
            (int) $user->getId(),
            'system.aacp.access',
            UserCapabilityOverride::EFFECT_GRANT,
        ));
        $this->em()->flush();
        $this->forgetStore($user);

        /** @var AuthorizationCheckerInterface $auth */
        $auth = $this->container()->get(AuthorizationCheckerInterface::class);
        self::assertFalse($auth->isGranted('system.aacp.access'));
    }

    public function testWriterRefusesALockedGrantAndAppliesNothing(): void
    {
        $this->pushRequest();
        $actor = $this->authenticateAs('admin', 'overlay-actor@example.test');
        $target = $this->member('overlay-target@example.test');

        /** @var UserCapabilityOverrideWriter $writer */
        $writer = $this->container()->get(UserCapabilityOverrideWriter::class);

        $errors = $writer->replace($target, [
            'content.moderate' => 'grant',
            'system.aacp.access' => 'grant',
        ], $actor);

        self::assertSame(['aacp.users.overrides.error.grant_locked'], $errors);
        self::assertSame([], $this->rowsFor($target));
    }

    public function testWriterReplaceAndResetRoundTrip(): void
    {
        $this->pushRequest();
        $actor = $this->authenticateAs('admin', 'overlay-roundtrip-actor@example.test');
        $target = $this->member('overlay-roundtrip@example.test');

        /** @var UserCapabilityOverrideWriter $writer */
        $writer = $this->container()->get(UserCapabilityOverrideWriter::class);

        self::assertSame([], $writer->replace($target, [
            'content.moderate' => 'grant',
            'account.profile.edit' => 'deny',
            'account.avatar.upload' => 'inherit',
        ], $actor));

        $rows = $this->rowsFor($target);
        self::assertSame(UserCapabilityOverride::EFFECT_GRANT, $rows['content.moderate'] ?? null);
        self::assertSame(UserCapabilityOverride::EFFECT_DENY, $rows['account.profile.edit'] ?? null);
        self::assertArrayNotHasKey('account.avatar.upload', $rows);

        $writer->reset($target, $actor);
        self::assertSame([], $this->rowsFor($target));
    }

    public function testWriterRefusesDenyOfProtectedCapabilityOnLastAdmin(): void
    {
        $this->pushRequest();
        $admin = $this->authenticateAs('admin', 'overlay-last-admin@example.test');

        /** @var UserCapabilityOverrideWriter $writer */
        $writer = $this->container()->get(UserCapabilityOverrideWriter::class);

        $errors = $writer->replace($admin, [
            'system.aacp.access' => 'deny',
        ], $admin);

        self::assertSame(['aacp.users.overrides.error.deny_protected'], $errors);
        self::assertSame([], $this->rowsFor($admin));
    }

    public function testEmptySubmittedDoesNotWipeExistingRows(): void
    {
        $this->pushRequest();
        $actor = $this->authenticateAs('admin', 'overlay-empty-actor@example.test');
        $target = $this->member('overlay-empty@example.test');

        /** @var UserCapabilityOverrideWriter $writer */
        $writer = $this->container()->get(UserCapabilityOverrideWriter::class);
        $writer->replace($target, ['content.moderate' => 'grant'], $actor);

        self::assertSame([], $writer->replace($target, [], $actor));
        self::assertArrayHasKey('content.moderate', $this->rowsFor($target));
    }

    private function member(string $email): User
    {
        $user = new User($email);
        $user->setPassword('x')->setCpaliusRoles(['member'])->setStatus(User::STATUS_ACTIVE);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function rowsFor(User $user): array
    {
        $this->em()->clear();

        $rows = [];
        $repository = $this->container()->get(UserCapabilityOverrideRepository::class);
        foreach ($repository->findAllForUser((int) $user->getId()) as $row) {
            $rows[$row->getCapability()] = $row->getEffect();
        }

        return $rows;
    }

    private function forgetStore(User $user): void
    {
        $this->container()->get(UserCapabilityOverrideStore::class)->forget($user->getId());
    }
}

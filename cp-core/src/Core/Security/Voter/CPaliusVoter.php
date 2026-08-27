<?php

namespace App\Core\Security\Voter;

use App\Core\Security\CapabilityRegistry;
use App\Core\Security\OwnableInterface;
use App\Core\Security\RoleConfigManager;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * CPalius'ta TÜM yetki kararları tek bu merkezden geçer. Kod içinde
 * hiçbir yerde $this->isGranted('ROLE_ADMIN') gibi sabit rol kontrolü
 * YAPILMAZ; her zaman is_granted('node.post.edit.own', $node) gibi
 * dinamik bir yetenek (capability) sorgulanır ve buraya düşer.
 *
 * Akış: capability geçerli mi (CapabilityRegistry, fail-safe) ->
 * kullanıcının rollerinin bu capability'ye sahip olup olmadığı
 * (RoleConfigManager) -> ".own" ile bitiyorsa subject'in sahibi
 * kullanıcıyla eşleşiyor mu (OwnableInterface).
 */
final class CPaliusVoter extends Voter
{
    private const OWN_SUFFIX = '.own';

    public function __construct(
        private readonly CapabilityRegistry $capabilityRegistry,
        private readonly RoleConfigManager $roleConfigManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Fail-Safe: registry'de kayıtlı olmayan bir "yetenek" bu voter
        // tarafından hiç ele alınmaz (abstain) — başka bir voter'a
        // (varsa) bırakılır, ama asla burada true varsayılmaz.
        return $this->capabilityRegistry->has($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if (!$user->isActive()) {
            return false;
        }

        $grantedCapabilities = $this->roleConfigManager->getCapabilitiesForRoles($user->getCpaliusRoles());

        if (!in_array($attribute, $grantedCapabilities, true)) {
            return false;
        }

        if (str_ends_with($attribute, self::OWN_SUFFIX)) {
            return $this->isOwnedBy($subject, $user);
        }

        return true;
    }

    /**
     * ".own" ekli bir yetenek için subject'in gerçekten mevcut kullanıcıya
     * ait olduğunu doğrular. Subject OwnableInterface uygulamıyorsa ya da
     * sahibi belirsizse, fail-safe gereği izin VERİLMEZ.
     */
    private function isOwnedBy(mixed $subject, User $user): bool
    {
        if (!$subject instanceof OwnableInterface) {
            return false;
        }

        $ownerId = $subject->getOwnerId();

        return $ownerId !== null && $ownerId === $user->getId();
    }
}

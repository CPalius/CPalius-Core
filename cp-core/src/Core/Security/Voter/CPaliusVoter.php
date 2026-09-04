<?php

namespace App\Core\Security\Voter;

use App\Core\Security\CapabilityRegistry;
use App\Core\Security\OwnableInterface;
use App\Core\Security\RoleConfigManager;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Single gate for authorization: is_granted('capability', $subject) — never ROLE_* checks.
 * Flow: registry (abstain if unknown) → role capabilities → ".own" vs OwnableInterface.
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
        // Unknown attributes abstain; never treat an unregistered capability as granted.
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
     * ".own" requires OwnableInterface with a matching owner id; otherwise deny.
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

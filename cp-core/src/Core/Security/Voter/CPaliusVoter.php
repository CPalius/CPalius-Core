<?php

declare(strict_types=1);

namespace App\Core\Security\Voter;

use App\Core\Entity\Event\EntityAccessEvent;
use App\Core\Entity\FieldableInterface;
use App\Core\Hook\HookContext;
use App\Core\Hook\HookDispatcherInterface;
use App\Core\Resource\ResourceRegistry;
use App\Core\Security\CapabilityRegistry;
use App\Core\Security\EntityAccessManager;
use App\Core\Security\Entity\UserCapabilityOverride;
use App\Core\Security\OwnableInterface;
use App\Core\Security\RoleConfigManager;
use App\Core\Security\UserCapabilityOverridePolicy;
use App\Core\Security\UserCapabilityOverrideStore;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Single gate for authorization: is_granted('capability', $subject) — never ROLE_* checks.
 * Flow: registry (abstain if unknown) → user overlay deny (wins) → role
 * capabilities → overlay grant → ".own" vs OwnableInterface → T1.4
 * per-record grant → T1.5 Access event.
 */
final class CPaliusVoter extends Voter
{
    private const OWN_SUFFIX = '.own';
    private const ANY_SUFFIX = '.any';

    public function __construct(
        private readonly CapabilityRegistry $capabilityRegistry,
        private readonly RoleConfigManager $roleConfigManager,
        private readonly EntityAccessManager $entityAccessManager,
        private readonly ResourceRegistry $resourceRegistry,
        private readonly HookDispatcherInterface $hooks,
        private readonly UserCapabilityOverrideStore $userOverrides,
        private readonly UserCapabilityOverridePolicy $overridePolicy,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Unknown attributes abstain; never treat an unregistered capability as granted.
        // A base capability (e.g. "node.post.view") counts as known when either scoped
        // form is registered — T1.4 grants are keyed on the base, not .own/.any.
        return $this->capabilityRegistry->has($attribute)
            || $this->capabilityRegistry->has($attribute.self::OWN_SUFFIX)
            || $this->capabilityRegistry->has($attribute.self::ANY_SUFFIX);
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

        $overlay = $this->userOverrides->forUser($user->getId());
        $effect = $overlay[$attribute] ?? null;

        // Deny wins over role union, record grants and Access events.
        if ($effect === UserCapabilityOverride::EFFECT_DENY) {
            return false;
        }

        $grantedCapabilities = $this->roleConfigManager->getCapabilitiesForRoles($user->getCpaliusRoles());
        $grantedByOverlay = $effect === UserCapabilityOverride::EFFECT_GRANT
            && $this->overridePolicy->canGrant($attribute);

        if (in_array($attribute, $grantedCapabilities, true) || $grantedByOverlay) {
            return str_ends_with($attribute, self::OWN_SUFFIX) ? $this->isOwnedBy($subject, $user) : true;
        }

        // Role capabilities settled nothing for this exact attribute. A record-specific
        // grant (T1.4) or an Access event listener (T1.5) may still reach it — both need
        // a resolvable (entityType, id), so a subject the voter cannot identify falls
        // through to deny, same as before this addition.
        $identity = $this->resolveEntityIdentity($subject);
        if ($identity === null) {
            return false;
        }
        [$entityType, $entityId] = $identity;

        if ($this->entityAccessManager->isGrantedForRecord($entityType, $entityId, $attribute, $user)) {
            return true;
        }

        return $this->consultAccessEvent($subject, $entityType, $attribute, $user);
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function resolveEntityIdentity(mixed $subject): ?array
    {
        if (!\is_object($subject) || !method_exists($subject, 'getId')) {
            return null;
        }

        $id = $subject->getId();
        if (!\is_int($id)) {
            return null;
        }

        if ($subject instanceof FieldableInterface) {
            return [$subject->fieldableEntityTypeId(), $id];
        }

        $definition = $this->resourceRegistry->get($subject::class);

        return $definition !== null && $definition->name !== '' ? [$definition->name, $id] : null;
    }

    private function consultAccessEvent(object $subject, string $entityType, string $attribute, User $user): bool
    {
        $event = new EntityAccessEvent($subject, $entityType, $attribute, $user);

        $this->hooks->trigger(sprintf('entity.%s.access', $entityType), new HookContext(['event' => $event]));
        $this->hooks->trigger('entity.any.access', new HookContext(['event' => $event]));

        return $event->getDecision() === true;
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

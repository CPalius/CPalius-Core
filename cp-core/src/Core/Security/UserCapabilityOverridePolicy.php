<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * What a root may write into the overlay. The voter never asks this class —
 * a row that should not exist is refused here, at the AACP write, so a
 * forgotten checkbox cannot mint system.aacp.access.
 */
final class UserCapabilityOverridePolicy
{
    public const EFFECT_INHERIT = 'inherit';
    public const EFFECT_GRANT = 'grant';
    public const EFFECT_DENY = 'deny';

    /**
     * Overlay cannot GRANT these. They stay role-only (admin YAML / *).
     * DENY is allowed except for the last-admin / self-lock set below.
     */
    private const GRANT_LOCKED_PREFIXES = [
        'system.',
    ];

    /**
     * Denying these on the last admin, or on yourself, would strand the site
     * with no one who can open AACP or assign roles.
     */
    private const LAST_ADMIN_PROTECTED = [
        'system.aacp.access',
        'system.users.manage',
        'system.module.manage',
        'system.update.manage',
    ];

    private const SCOPE_SUFFIXES = ['.own', '.any'];

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly UserRepository $users,
    ) {
    }

    public function isKnown(string $capability): bool
    {
        return $this->capabilities->has($capability);
    }

    public function canGrant(string $capability): bool
    {
        if (!$this->isKnown($capability) || $this->isGrantLocked($capability)) {
            return false;
        }

        return !$this->hasScopedSibling($capability);
    }

    public function canDeny(string $capability, User $target, ?User $actor): bool
    {
        if (!$this->isKnown($capability)) {
            return false;
        }

        if (!\in_array($capability, self::LAST_ADMIN_PROTECTED, true)) {
            return true;
        }

        if ($actor !== null && $target->getId() !== null && $actor->getId() === $target->getId()) {
            return false;
        }

        return !$this->isLastAdmin($target);
    }

    public function isGrantLocked(string $capability): bool
    {
        foreach (self::GRANT_LOCKED_PREFIXES as $prefix) {
            if (str_starts_with($capability, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function lastAdminProtected(): array
    {
        return self::LAST_ADMIN_PROTECTED;
    }

    public function isLastAdmin(User $target): bool
    {
        if (!\in_array(UserRoleGuardService::ROLE_ADMIN, $target->getCpaliusRoles(), true)) {
            return false;
        }

        return \count($this->users->findByRole(UserRoleGuardService::ROLE_ADMIN)) <= 1;
    }

    private function hasScopedSibling(string $capability): bool
    {
        foreach (self::SCOPE_SUFFIXES as $suffix) {
            if (str_ends_with($capability, $suffix)) {
                return false;
            }
        }

        foreach (self::SCOPE_SUFFIXES as $suffix) {
            if ($this->capabilities->has($capability.$suffix)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Server-side whitelist and rules for AACP user-role assignment. Does not change CPaliusVoter.
 */
final class UserRoleGuardService
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public function __construct(
        private readonly RoleConfigManager $roleConfigManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @param list<string> $submittedRoles
     *
     * @return list<string>
     */
    public function sanitizeRoles(array $submittedRoles): array
    {
        $valid = [];

        foreach ($submittedRoles as $role) {
            if (!\is_string($role) || $role === '') {
                continue;
            }

            if ($this->roleConfigManager->hasRole($role)) {
                $valid[$role] = true;
            }
        }

        return array_keys($valid);
    }

    /**
     * @param list<string> $newRoles Output of sanitizeRoles().
     */
    public function validateAssignment(User $target, array $newRoles, ?User $actor): ?string
    {
        if ($newRoles === []) {
            return 'aacp.users.roles.empty_not_allowed';
        }

        $currentRoles = $target->getCpaliusRoles();
        $hadAdmin = \in_array(self::ROLE_ADMIN, $currentRoles, true);
        $hasAdmin = \in_array(self::ROLE_ADMIN, $newRoles, true);

        if ($hadAdmin && !$hasAdmin) {
            if (\count($this->userRepository->findByRole(self::ROLE_ADMIN)) <= 1) {
                return 'aacp.users.roles.last_admin';
            }
        }

        if ($actor !== null
            && $target->getId() !== null
            && $actor->getId() === $target->getId()
            && $hadAdmin
            && !$hasAdmin
        ) {
            return 'aacp.users.roles.self_demote_admin';
        }

        return null;
    }

    public function validateStatusChange(User $target, string $newStatus, ?User $actor): ?string
    {
        if ($actor === null || $target->getId() === null) {
            return null;
        }

        if ($actor->getId() !== $target->getId()) {
            return null;
        }

        if ($newStatus === User::STATUS_BANNED) {
            return 'aacp.users.status.self_ban';
        }

        if ($newStatus === User::STATUS_INACTIVE) {
            return 'aacp.users.status.self_deactivate';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function defaultRolesForNewUser(): array
    {
        if ($this->roleConfigManager->hasRole(self::ROLE_MEMBER)) {
            return [self::ROLE_MEMBER];
        }

        $ids = $this->roleConfigManager->getAllRoleIds();

        return $ids !== [] ? [$ids[0]] : [];
    }

    /**
     * @return array<string, string> roleId => label
     */
    public function roleLabelMap(): array
    {
        $map = [];
        foreach ($this->roleConfigManager->getAllRoleIds() as $roleId) {
            $map[$roleId] = $this->roleConfigManager->getLabel($roleId) ?? $roleId;
        }

        return $map;
    }
}

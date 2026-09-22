<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Audit\Entity\AuditLog;
use App\Core\Security\Entity\UserCapabilityOverride;
use App\Core\Security\Repository\UserCapabilityOverrideRepository;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The only writer of overlay rows. CPaliusVoter never writes. A tampered POST
 * that asks for a locked grant is refused as a whole — no partial apply.
 */
final class UserCapabilityOverrideWriter
{
    public function __construct(
        private readonly UserCapabilityOverridePolicy $policy,
        private readonly UserCapabilityOverrideRepository $overrides,
        private readonly UserCapabilityOverrideStore $store,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, string> $submitted capability => inherit|grant|deny
     *
     * @return list<string> translation keys of refusals; empty = applied
     */
    public function replace(User $target, array $submitted, ?User $actor): array
    {
        $userId = $target->getId();
        if ($userId === null) {
            return ['aacp.users.overrides.error.unsaved'];
        }

        // Empty POST is not "set everything to inherit" — that is reset().
        // A missing field list would otherwise wipe the overlay by accident.
        if ($submitted === []) {
            return [];
        }

        $errors = [];
        $desired = [];

        foreach ($submitted as $capability => $effect) {
            if (!\is_string($capability) || $capability === '' || !\is_string($effect)) {
                continue;
            }

            if ($effect === UserCapabilityOverridePolicy::EFFECT_INHERIT) {
                continue;
            }

            if (!$this->policy->isKnown($capability)) {
                $errors[] = 'aacp.users.overrides.error.unknown';
                continue;
            }

            if ($effect === UserCapabilityOverridePolicy::EFFECT_GRANT) {
                if (!$this->policy->canGrant($capability)) {
                    $errors[] = 'aacp.users.overrides.error.grant_locked';
                    continue;
                }
                $desired[$capability] = UserCapabilityOverride::EFFECT_GRANT;
                continue;
            }

            if ($effect === UserCapabilityOverridePolicy::EFFECT_DENY) {
                if (!$this->policy->canDeny($capability, $target, $actor)) {
                    $errors[] = 'aacp.users.overrides.error.deny_protected';
                    continue;
                }
                $desired[$capability] = UserCapabilityOverride::EFFECT_DENY;
            }
        }

        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        $existing = [];
        foreach ($this->overrides->findAllForUser($userId) as $row) {
            $existing[$row->getCapability()] = $row;
        }

        $diff = [];

        foreach ($existing as $capability => $row) {
            $next = $desired[$capability] ?? null;
            if ($next === null) {
                $diff[$capability] = [$row->getEffect(), null];
                $this->entityManager->remove($row);
                continue;
            }
            if ($next !== $row->getEffect()) {
                $diff[$capability] = [$row->getEffect(), $next];
                $row->setEffect($next);
            }
            unset($desired[$capability]);
        }

        foreach ($desired as $capability => $effect) {
            $diff[$capability] = [null, $effect];
            $this->entityManager->persist(new UserCapabilityOverride($userId, $capability, $effect));
        }

        $this->entityManager->flush();
        $this->store->forget($userId);
        $this->audit($userId, $actor?->getId(), $diff, AuditLog::ACTION_UPDATE);

        return [];
    }

    public function reset(User $target, ?User $actor): void
    {
        $userId = $target->getId();
        if ($userId === null) {
            return;
        }

        $diff = [];
        foreach ($this->overrides->findAllForUser($userId) as $row) {
            $diff[$row->getCapability()] = [$row->getEffect(), null];
            $this->entityManager->remove($row);
        }

        $this->entityManager->flush();
        $this->store->forget($userId);
        $this->audit($userId, $actor?->getId(), $diff, AuditLog::ACTION_DELETE);
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $diff
     */
    private function audit(int $targetUserId, ?int $actorId, array $diff, string $action): void
    {
        if ($diff === []) {
            return;
        }

        try {
            $this->entityManager->persist(new AuditLog(
                'user.capability_override',
                (string) $targetUserId,
                $action,
                $diff,
                $actorId,
            ));
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger?->warning('User capability overlay audit write failed.', ['exception' => $e]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Security\Entity\EntityAccessGrant;
use App\Core\Security\Repository\EntityAccessGrantRepository;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Row-level access grants: "this user / this role / anyone may do this exact
 * capability on this one record." One capability namespace shared with
 * CapabilityRegistry and RoleConfigManager — not a parallel realm/gid system.
 *
 * Grants are written incrementally by domain code (a module grants access the
 * moment it knows a record's visibility changed — a group membership, a
 * shared invite, …). There is no bulk "rebuild" step: each grant row is
 * independently correct the instant it is written, and buildScopeCondition()
 * folds directly into the caller's query as a correlated EXISTS — the same
 * "voter to SQL" discipline as QueryScopeApplier (Law 6.2), generalized to
 * per-record grants instead of only ownership.
 */
final class EntityAccessManager
{
    public function __construct(
        private readonly EntityAccessGrantRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CapabilityRegistry $capabilities,
    ) {
    }

    public function grantToUser(string $entityType, int $entityId, string $capability, int $userId, ?int $grantedBy = null): ?EntityAccessGrant
    {
        return $this->grant($entityType, $entityId, $capability, EntityAccessGrant::SUBJECT_USER, (string) $userId, $grantedBy);
    }

    public function grantToRole(string $entityType, int $entityId, string $capability, string $roleId, ?int $grantedBy = null): ?EntityAccessGrant
    {
        return $this->grant($entityType, $entityId, $capability, EntityAccessGrant::SUBJECT_ROLE, $roleId, $grantedBy);
    }

    public function grantToAnyone(string $entityType, int $entityId, string $capability, ?int $grantedBy = null): ?EntityAccessGrant
    {
        return $this->grant($entityType, $entityId, $capability, EntityAccessGrant::SUBJECT_ANY, '', $grantedBy);
    }

    public function revokeFromUser(string $entityType, int $entityId, string $capability, int $userId): void
    {
        $this->revoke($entityType, $entityId, $capability, EntityAccessGrant::SUBJECT_USER, (string) $userId);
    }

    public function revokeFromRole(string $entityType, int $entityId, string $capability, string $roleId): void
    {
        $this->revoke($entityType, $entityId, $capability, EntityAccessGrant::SUBJECT_ROLE, $roleId);
    }

    public function revokeFromAnyone(string $entityType, int $entityId, string $capability): void
    {
        $this->revoke($entityType, $entityId, $capability, EntityAccessGrant::SUBJECT_ANY, '');
    }

    /**
     * A hard-delete caller's responsibility: nothing cascades to this table
     * automatically because entity_type/entity_id is not a real foreign key
     * (it spans every fieldable/resource entity type).
     */
    public function revokeAllForEntity(string $entityType, int $entityId): void
    {
        foreach ($this->repository->findForEntity($entityType, $entityId) as $grant) {
            $this->entityManager->remove($grant);
        }
        $this->entityManager->flush();
    }

    /**
     * @return list<EntityAccessGrant>
     */
    public function grantsFor(string $entityType, int $entityId): array
    {
        return $this->repository->findForEntity($entityType, $entityId);
    }

    /**
     * Single-record check (detail pages, edit forms) — not for list screens,
     * use buildScopeCondition() there so access stays a SQL WHERE, not a loop.
     */
    public function isGrantedForRecord(string $entityType, int $entityId, string $capability, User $user): bool
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(g.id)')
            ->from(EntityAccessGrant::class, 'g')
            ->andWhere('g.entityType = :entityType')->setParameter('entityType', $entityType)
            ->andWhere('g.entityId = :entityId')->setParameter('entityId', $entityId)
            ->andWhere('g.capability = :capability')->setParameter('capability', $capability);

        $qb->andWhere($this->subjectExpression($qb, $user, 'r'));

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * A correlated EXISTS DQL fragment scoping $rootAlias.id to records this
     * user has an explicit grant for — parameters are already bound on $qb.
     * $paramPrefix must be unique per call on the same QueryBuilder (multiple
     * accessCheck() calls in one query would otherwise collide on param names).
     */
    public function buildScopeCondition(
        QueryBuilder $qb,
        string $rootAlias,
        string $entityType,
        string $capability,
        User $user,
        string $paramPrefix = 'cpAccessGrant',
    ): string {
        $alias = $paramPrefix.'_g';
        $etParam = $paramPrefix.'_et';
        $capParam = $paramPrefix.'_cap';

        $qb->setParameter($etParam, $entityType);
        $qb->setParameter($capParam, $capability);

        $subject = $this->subjectExpression($qb, $user, $paramPrefix, $alias);

        return sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.entityType = :%s AND %s.entityId = %s.id AND %s.capability = :%s AND (%s))',
            EntityAccessGrant::class,
            $alias,
            $alias,
            $etParam,
            $alias,
            $rootAlias,
            $alias,
            $capParam,
            $subject,
        );
    }

    /**
     * Idempotent: an existing identical grant is returned unchanged. Refuses
     * to store a grant for a capability CapabilityRegistry does not know —
     * fail-safe, matches "unknown capability = deny" everywhere else. A grant
     * is keyed on the BASE capability (e.g. "node.post.view", never
     * "node.post.view.own"/".any" — those are the global scopes this table
     * extends), so a family counts as known when either scoped form is
     * registered even if the bare base string never is on its own.
     */
    private function grant(
        string $entityType,
        int $entityId,
        string $capability,
        string $subjectType,
        string $subjectId,
        ?int $grantedBy,
    ): ?EntityAccessGrant {
        $known = $this->capabilities->has($capability)
            || $this->capabilities->has($capability.'.own')
            || $this->capabilities->has($capability.'.any');
        if (!$known) {
            return null;
        }

        $existing = $this->repository->findOneMatching($entityType, $entityId, $capability, $subjectType, $subjectId);
        if ($existing !== null) {
            return $existing;
        }

        $grant = new EntityAccessGrant($entityType, $entityId, $capability, $subjectType, $subjectId, $grantedBy);
        $this->entityManager->persist($grant);
        $this->entityManager->flush();

        return $grant;
    }

    private function revoke(string $entityType, int $entityId, string $capability, string $subjectType, string $subjectId): void
    {
        $existing = $this->repository->findOneMatching($entityType, $entityId, $capability, $subjectType, $subjectId);
        if ($existing === null) {
            return;
        }

        $this->entityManager->remove($existing);
        $this->entityManager->flush();
    }

    /**
     * "anyone" OR "this exact user" OR "one of this user's roles" — the shared
     * subject-matching boolean used by both the list-scope EXISTS and the
     * single-record check.
     */
    private function subjectExpression(QueryBuilder $qb, User $user, string $paramPrefix, string $alias = 'g'): string
    {
        $anyParam = $paramPrefix.'_subjectAny';
        $userTypeParam = $paramPrefix.'_subjectUserType';
        $userIdParam = $paramPrefix.'_subjectUserId';

        $qb->setParameter($anyParam, EntityAccessGrant::SUBJECT_ANY);
        $qb->setParameter($userTypeParam, EntityAccessGrant::SUBJECT_USER);
        $qb->setParameter($userIdParam, (string) $user->getId());

        $conditions = [
            sprintf('%s.subjectType = :%s', $alias, $anyParam),
            sprintf('(%s.subjectType = :%s AND %s.subjectId = :%s)', $alias, $userTypeParam, $alias, $userIdParam),
        ];

        $roles = $user->getCpaliusRoles();
        if ($roles !== []) {
            $roleTypeParam = $paramPrefix.'_subjectRoleType';
            $roleIdsParam = $paramPrefix.'_subjectRoleIds';
            $qb->setParameter($roleTypeParam, EntityAccessGrant::SUBJECT_ROLE);
            $qb->setParameter($roleIdsParam, $roles);
            $conditions[] = sprintf('(%s.subjectType = :%s AND %s.subjectId IN (:%s))', $alias, $roleTypeParam, $alias, $roleIdsParam);
        }

        return implode(' OR ', $conditions);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Law 6.2: push .any/.own into SQL WHERE before the query runs (no per-row voter on list screens).
 * No .any, no .own, and no matching per-record grant → 1=0 (empty result), never an unfiltered table.
 */
final class QueryScopeApplier
{
    private const ANY_SUFFIX = '.any';
    private const OWN_SUFFIX = '.own';

    public function __construct(
        private readonly Security $security,
        private readonly RoleConfigManager $roleConfigManager,
        private readonly EntityAccessManager $entityAccessManager,
    ) {
    }

    /**
     * @param string $capabilityBase Root without suffix (e.g. "node.post.edit"); this method adds .any/.own.
     * @param string $ownerField     Ownership column compared as $rootAlias.$ownerField.
     * @param string $entityType     #[CpEntityType] id for row-level grants (T1.4); "node" by default.
     */
    public function apply(
        QueryBuilder $qb,
        string $rootAlias,
        string $capabilityBase,
        string $ownerField = 'author',
        string $entityType = 'node',
    ): QueryBuilder {
        $user = $this->security->getUser();

        if (!$user instanceof User || !$user->isActive()) {
            return $this->denyAll($qb);
        }

        $granted = $this->roleConfigManager->getCapabilitiesForRoles($user->getCpaliusRoles());

        if (in_array($capabilityBase.self::ANY_SUFFIX, $granted, true)) {
            return $qb;
        }

        $conditions = [];

        if (in_array($capabilityBase.self::OWN_SUFFIX, $granted, true)) {
            $qb->setParameter('cpScopeCurrentUser', $user->getId());
            $conditions[] = sprintf('%s.%s = :cpScopeCurrentUser', $rootAlias, $ownerField);
        }

        // T1.4: a record-specific grant reaches records .own/.any never would
        // (shared with someone who is neither the owner nor role-wide granted).
        $conditions[] = $this->entityAccessManager->buildScopeCondition($qb, $rootAlias, $entityType, $capabilityBase, $user);

        return $qb->andWhere(implode(' OR ', array_map(static fn (string $c): string => '('.$c.')', $conditions)));
    }

    /**
     * Fail-safe empty result when the user has neither .any nor .own (caller still gets the same list type).
     */
    private function denyAll(QueryBuilder $qb): QueryBuilder
    {
        return $qb->andWhere('1 = 0');
    }
}

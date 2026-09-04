<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Law 6.2: push .any/.own into SQL WHERE before the query runs (no per-row voter on list screens).
 * No .any and no .own → 1=0 (empty result), never an unfiltered table.
 */
final class QueryScopeApplier
{
    private const ANY_SUFFIX = '.any';
    private const OWN_SUFFIX = '.own';

    public function __construct(
        private readonly Security $security,
        private readonly RoleConfigManager $roleConfigManager,
    ) {
    }

    /**
     * @param string $capabilityBase Root without suffix (e.g. "node.post.edit"); this method adds .any/.own.
     * @param string $ownerField     Ownership column compared as $rootAlias.$ownerField.
     */
    public function apply(
        QueryBuilder $qb,
        string $rootAlias,
        string $capabilityBase,
        string $ownerField = 'author',
    ): QueryBuilder {
        $user = $this->security->getUser();

        if (!$user instanceof User || !$user->isActive()) {
            return $this->denyAll($qb);
        }

        $granted = $this->roleConfigManager->getCapabilitiesForRoles($user->getCpaliusRoles());

        if (in_array($capabilityBase.self::ANY_SUFFIX, $granted, true)) {
            return $qb;
        }

        if (in_array($capabilityBase.self::OWN_SUFFIX, $granted, true)) {
            return $qb
                ->andWhere(sprintf('%s.%s = :cpScopeCurrentUser', $rootAlias, $ownerField))
                ->setParameter('cpScopeCurrentUser', $user->getId());
        }

        return $this->denyAll($qb);
    }

    /**
     * Fail-safe empty result when the user has neither .any nor .own (caller still gets the same list type).
     */
    private function denyAll(QueryBuilder $qb): QueryBuilder
    {
        return $qb->andWhere('1 = 0');
    }
}

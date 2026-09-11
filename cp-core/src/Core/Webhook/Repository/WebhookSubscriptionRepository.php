<?php

declare(strict_types=1);

namespace App\Core\Webhook\Repository;

use App\Core\Webhook\Entity\WebhookSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookSubscription>
 */
class WebhookSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookSubscription::class);
    }

    /**
     * @return list<WebhookSubscription>
     */
    public function findDeliverableForEvent(string $event): array
    {
        /** @var list<WebhookSubscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.active = :active')
            ->andWhere('s.quarantinedAt IS NULL')
            ->setParameter('active', true)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $rows,
            static fn (WebhookSubscription $s): bool => $s->matchesEvent($event),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Content\EventListener;

use App\Core\Content\ContentModerationManager;
use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Stamps the initial moderation place on a new node whose content type has
 * moderation enabled. prePersist so the value is part of the insert.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class NodeModerationInitListener
{
    public function __construct(
        private readonly ContentModerationManager $moderation,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Node) {
            $this->moderation->initialize($entity);
        }
    }
}

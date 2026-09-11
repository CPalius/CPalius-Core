<?php

declare(strict_types=1);

namespace App\Core\Revision\EventListener;

use App\Core\Revision\RevisionManager;
use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Auto-captures a NodeRevision whenever a Node is created or edited. Mirrors
 * NodeIndexListener: flushes its own rows, never touches the committed Node.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class NodeRevisionListener
{
    private bool $flushing = false;

    public function __construct(
        private readonly RevisionManager $revisions,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handle($args->getObject(), $args->getObjectManager());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handle($args->getObject(), $args->getObjectManager());
    }

    private function handle(object $entity, \Doctrine\ORM\EntityManagerInterface $em): void
    {
        if (!$entity instanceof Node || $this->flushing) {
            return;
        }

        $revision = $this->revisions->capture($entity);
        if ($revision === null) {
            return;
        }

        $this->flushing = true;
        try {
            $em->flush();
            $this->revisions->prune($entity);
        } finally {
            $this->flushing = false;
        }
    }
}

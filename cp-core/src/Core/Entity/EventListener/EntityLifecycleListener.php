<?php

declare(strict_types=1);

namespace App\Core\Entity\EventListener;

use App\Core\Entity\Event\AbstractEntityLifecycleEvent;
use App\Core\Entity\Event\EntityLifecycleRejectedException;
use App\Core\Entity\Event\EntityPostDeleteEvent;
use App\Core\Entity\Event\EntityPostInsertEvent;
use App\Core\Entity\Event\EntityPostUpdateEvent;
use App\Core\Entity\Event\EntityPreDeleteEvent;
use App\Core\Entity\Event\EntityPreSaveEvent;
use App\Core\Entity\FieldableInterface;
use App\Core\Hook\HookContext;
use App\Core\Hook\HookDispatcherInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * T1.5: turns Doctrine's lifecycle callbacks into typed entity events, for
 * EVERY FieldableInterface entity (Node, User, taxonomy Term today —
 * whatever opts in next needs zero new listener code, unlike Blog/Forum's
 * per-entity ad hoc listeners). Delivered as hook points
 * ("entity.{type}.{moment}" and a wildcard "entity.any.{moment}") through the
 * existing HookManager — every listener already gets Core Never Dies
 * isolation for free. Only a listener's deliberate reject() call turns into a
 * real thrown exception that aborts the Doctrine flush; a listener that
 * merely throws a bug is isolated and quarantined like any other hook.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postRemove)]
final class EntityLifecycleListener
{
    public function __construct(
        private readonly HookDispatcherInterface $hooks,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->dispatchPreSave($args->getObject(), true);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->dispatchPreSave($args->getObject(), false);
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->dispatchNotification($args->getObject(), 'post_insert', EntityPostInsertEvent::class);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->dispatchNotification($args->getObject(), 'post_update', EntityPostUpdateEvent::class);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        $entityTypeId = $this->entityTypeIdOf($entity);
        if ($entityTypeId === null) {
            return;
        }

        $event = new EntityPreDeleteEvent($entity, $entityTypeId);
        $this->trigger($entityTypeId, 'pre_delete', $event);

        if ($event->isRejected()) {
            throw new EntityLifecycleRejectedException($event->getRejectionReason() ?? 'entity.lifecycle.rejected', $entityTypeId);
        }
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->dispatchNotification($args->getObject(), 'post_delete', EntityPostDeleteEvent::class);
    }

    private function dispatchPreSave(object $entity, bool $isNew): void
    {
        $entityTypeId = $this->entityTypeIdOf($entity);
        if ($entityTypeId === null) {
            return;
        }

        $event = new EntityPreSaveEvent($entity, $entityTypeId, $isNew);
        $this->trigger($entityTypeId, 'pre_save', $event);

        if ($event->isRejected()) {
            throw new EntityLifecycleRejectedException($event->getRejectionReason() ?? 'entity.lifecycle.rejected', $entityTypeId);
        }
    }

    /**
     * @param class-string<AbstractEntityLifecycleEvent> $eventClass
     */
    private function dispatchNotification(object $entity, string $moment, string $eventClass): void
    {
        $entityTypeId = $this->entityTypeIdOf($entity);
        if ($entityTypeId === null) {
            return;
        }

        $this->trigger($entityTypeId, $moment, new $eventClass($entity, $entityTypeId));
    }

    private function trigger(string $entityTypeId, string $moment, AbstractEntityLifecycleEvent $event): void
    {
        // The SAME event object flows through both triggers (object identity, not
        // the HookContext bag) — a per-type listener and a wildcard listener can
        // both see and react to one reject()/allow() decision.
        $context = new HookContext(['event' => $event]);
        $this->hooks->trigger(sprintf('entity.%s.%s', $entityTypeId, $moment), $context);
        $this->hooks->trigger(sprintf('entity.any.%s', $moment), $context);
    }

    private function entityTypeIdOf(object $entity): ?string
    {
        return $entity instanceof FieldableInterface ? $entity->fieldableEntityTypeId() : null;
    }
}

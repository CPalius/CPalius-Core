<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use App\Core\Entity\Event\AbstractEntityLifecycleEvent;
use App\Core\Entity\FieldableInterface;
use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;

/**
 * T2.3: the automatic half of the cache tag primitive — every FieldableInterface save/
 * delete (T1.5's `entity.any.*` wildcard, Node/User/Term today, whatever opts in next
 * for free) purges exactly the snapshots tagged with that entity plus its listing tag,
 * no per-controller `purgeAreas()` call required. A listener bug here is quarantined by
 * the existing Hook isolation (Law: Core Never Dies) — a broken purge never blocks the
 * entity save itself.
 */
final class EntityCacheTagInvalidator
{
    public function __construct(
        private readonly OriginCachePurger $purger,
    ) {
    }

    #[CpHook(hookPoint: 'entity.any.post_insert')]
    #[CpHook(hookPoint: 'entity.any.post_update')]
    #[CpHook(hookPoint: 'entity.any.post_delete')]
    public function onEntityChanged(HookContext $context): void
    {
        $event = $context->get('event');
        if (!$event instanceof AbstractEntityLifecycleEvent) {
            return;
        }

        $entity = $event->getEntity();
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        if ($id === null) {
            return;
        }

        $entityTypeId = $event->getEntityTypeId();
        $tags = [CacheTag::entity($entityTypeId, $id), CacheTag::list($entityTypeId)];
        if ($entity instanceof FieldableInterface) {
            $tags[] = CacheTag::list($entityTypeId, $entity->fieldableBundle());
        }

        $this->purger->purgeTags(...$tags);
    }
}

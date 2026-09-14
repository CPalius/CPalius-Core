<?php

declare(strict_types=1);

namespace Modules\HookFixture;

use App\Core\Entity\Event\EntityAccessEvent;
use App\Core\Entity\Event\EntityPostInsertEvent;
use App\Core\Entity\Event\EntityPreSaveEvent;
use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;

/**
 * Static state because the DI container owns the live instance and tests
 * cannot reach it directly — same convention as HealthyModule::$bootCount.
 */
final class EntityHookRecorder
{
    /** @var list<string> */
    private static array $insertedTitles = [];

    private static bool $vetoNextPreSave = false;

    private static bool $allowNextAccess = false;

    private static bool $throwOnNextPostInsert = false;

    public static function reset(): void
    {
        self::$insertedTitles = [];
        self::$vetoNextPreSave = false;
        self::$allowNextAccess = false;
        self::$throwOnNextPostInsert = false;
    }

    /**
     * @return list<string>
     */
    public static function insertedTitles(): array
    {
        return self::$insertedTitles;
    }

    public static function vetoNextPreSave(): void
    {
        self::$vetoNextPreSave = true;
    }

    public static function allowNextAccess(): void
    {
        self::$allowNextAccess = true;
    }

    public static function throwOnNextPostInsert(): void
    {
        self::$throwOnNextPostInsert = true;
    }

    #[CpHook(hookPoint: 'entity.node.pre_save')]
    public function onPreSave(HookContext $context): void
    {
        if (!self::$vetoNextPreSave) {
            return;
        }

        self::$vetoNextPreSave = false;
        /** @var EntityPreSaveEvent $event */
        $event = $context->get('event');
        $event->reject('hookfixture.vetoed');
    }

    #[CpHook(hookPoint: 'entity.node.post_insert')]
    public function onPostInsert(HookContext $context): void
    {
        /** @var EntityPostInsertEvent $event */
        $event = $context->get('event');
        $entity = $event->getEntity();
        if (method_exists($entity, 'getTitle')) {
            self::$insertedTitles[] = (string) $entity->getTitle();
        }

        if (self::$throwOnNextPostInsert) {
            self::$throwOnNextPostInsert = false;
            throw new \RuntimeException('EntityHookRecorder deliberate failure — must be quarantined, not fatal.');
        }
    }

    #[CpHook(hookPoint: 'entity.node.access')]
    public function onAccess(HookContext $context): void
    {
        if (!self::$allowNextAccess) {
            return;
        }

        self::$allowNextAccess = false;
        /** @var EntityAccessEvent $event */
        $event = $context->get('event');
        $event->allow();
    }
}

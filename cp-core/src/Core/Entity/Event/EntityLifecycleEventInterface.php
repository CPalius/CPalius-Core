<?php

declare(strict_types=1);

namespace App\Core\Entity\Event;

/**
 * Typed payload for one entity lifecycle moment (T1.5) — the antidote to
 * WordPress-style untyped positional hook args. Delivered through the
 * existing Hook engine (HookContext::get('event')), not a parallel event
 * bus: every #[CpHook] / Hooks/{point}.php listener already gets Core Never
 * Dies isolation (a throwing listener is quarantined, never blocks the
 * request) for free — see EntityLifecycleListener.
 */
interface EntityLifecycleEventInterface
{
    public function getEntity(): object;

    /**
     * The #[CpEntityType] id the entity belongs to ("node", "user",
     * "taxonomy_term", …) or a #[CpResource] name.
     */
    public function getEntityTypeId(): string;
}

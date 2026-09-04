<?php

declare(strict_types=1);

namespace App\Core\Hook\Attribute;

/**
 * Binds a service method to a hook point (DI alternative to flat-file Hooks/{point}.php).
 * Repeatable on methods; HookRegistrationPass collects them — no extra services.yaml tag.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class CpHook
{
    /**
     * @param string $hookPoint Unique hook id (e.g. "theme.render.sidebar").
     * @param int    $priority  Lower runs first (default 100); tracks are ordered separately.
     */
    public function __construct(
        public readonly string $hookPoint,
        public readonly int $priority = 100,
    ) {
    }
}

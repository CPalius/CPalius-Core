<?php

declare(strict_types=1);

namespace App\Core\Rebuild;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One named recount job. Modules register implementations; Core never imports them.
 *
 * COUNT(*) belongs here and nowhere on the write path. A batch must return
 * quickly: the Studio AJAX runner will call again with the next offset.
 *
 * Tagged on the interface so a module services.yaml (its own YamlFileLoader)
 * still contributes — the same reason StudioShellBrandingInterface tags itself.
 */
#[AutoconfigureTag('cpalius.rebuilder')]
interface RebuilderInterface
{
    /**
     * Stable machine id, conventionally "<module>.<what>" — "forum.topics".
     * Used in the AJAX route; never rename one operators already bookmark.
     */
    public function getId(): string;

    /** Translation key for the list title ("Rebuild threads"). */
    public function getName(): string;

    /** Translation key for the one-line hint under the title. */
    public function getDescription(): string;

    /**
     * Rows this job will attempt in one HTTP request.
     * Keep it small enough that PHP will not time out on a cheap host.
     */
    public function getBatchSize(): int;

    /**
     * Lower sorts first. Topics before sections when both exist, so a full
     * pass the operator runs top-to-bottom still makes sense.
     */
    public function getPriority(): int;

    /** How many units rebuild() will walk from offset 0. Used for the % bar. */
    public function getTotal(): int;

    /**
     * Studio is the site desk: content counters the operator can safely touch.
     * AACP is the platform desk: every registered job, including Core.
     */
    public function isStudioVisible(): bool;

    /**
     * Process one page. Return how many units were visited (including no-ops).
     * A short page (return < $limit) means there is nothing left.
     *
     * Implementations MUST EntityManager::clear() (or equivalent) before
     * returning so a long run cannot accumulate the whole table in memory.
     */
    public function rebuild(int $offset, int $limit): int;
}

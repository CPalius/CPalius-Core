<?php

declare(strict_types=1);

namespace App\Core\Search;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Front global search. Core never imports Modules\*; inactive modules
 * are absent from the container so the page stays up.
 */
#[AutoconfigureTag('cpalius.search.provider')]
interface SearchProviderInterface
{
    public function getKey(): string;

    /**
     * Translation key for the section heading (Twig trans).
     */
    public function getLabel(): string;

    public function getIcon(): string;

    public function getPriority(): int;

    /**
     * Fail-safe: GlobalSearchService skips a provider that throws.
     */
    public function search(string $term, string $locale, int $limit): SearchGroup;
}

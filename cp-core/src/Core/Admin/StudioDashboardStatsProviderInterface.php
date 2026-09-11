<?php

declare(strict_types=1);

namespace App\Core\Admin;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Studio command-desk contract. Core never imports Modules\* classes.
 * Inactive modules are absent from the container, so the desk stays up.
 */
#[AutoconfigureTag('cpalius.studio.dashboard_stats_provider')]
interface StudioDashboardStatsProviderInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    public function getIcon(): string;

    public function getPriority(): int;

    /**
     * Fail-safe: StudioDashboardService skips a provider that throws.
     */
    public function buildContribution(): StudioDashboardContribution;
}

<?php

declare(strict_types=1);

namespace App\Core\Aacp;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for modules to add status cards to AACP System Monitor (/aacp/system).
 * Core Never Dies: AACPController collects tagged providers via iterable injection, not direct module imports.
 */
#[AutoconfigureTag('cpalius.aacp.system_widget_provider')]
interface SystemWidgetProviderInterface
{
    /** Returns this provider's card; AACPController skips the widget on failure (fail-safe). */
    public function getWidget(): SystemWidgetData;
}

<?php

declare(strict_types=1);

namespace App\Core\Widget;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Declares the widget areas a module (or theme) draws. An area must be declared to appear in Studio.
 */
#[AutoconfigureTag('cpalius.widget_area_provider')]
interface WidgetAreaProviderInterface
{
    /**
     * @return list<WidgetArea>
     */
    public function areas(): array;
}

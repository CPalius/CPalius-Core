<?php

declare(strict_types=1);

namespace App\Core\Widget;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A kind of widget ("Recent posts", "Custom HTML"…). Any module can contribute types by implementing this
 * interface; the Widget module collects them, offers them in Studio and draws them. Core never imports a
 * module's type class, and a type simply goes unused on a site without the Widget module.
 */
#[AutoconfigureTag('cpalius.widget_type')]
interface WidgetTypeInterface
{
    /** Stable id stored with every instance, "<module>.<name>" (e.g. "blog.recent_posts"). Never rename. */
    public function id(): string;

    /** Translation key: name shown in the catalogue. */
    public function label(): string;

    /** Translation key: one line on what it shows. */
    public function description(): string;

    /** symfony/ux-icons name. */
    public function icon(): string;

    /** Translation key: the catalogue section ("Blog", "General"…). */
    public function group(): string;

    /**
     * The type's own settings; the title, language, pages and CSS class are common to all widgets and
     * handled by the Widget module.
     *
     * @return list<WidgetField>
     */
    public function fields(): array;

    /**
     * HTML of the widget body, or '' to show nothing (the widget, title included, is then left out).
     * Receives the validated settings with defaults filled in. Must escape everything it prints;
     * exceptions are caught by the caller, so one broken widget never takes the sidebar down.
     *
     * @param array<string, mixed> $settings
     */
    public function render(array $settings, WidgetRenderContext $context): string;
}

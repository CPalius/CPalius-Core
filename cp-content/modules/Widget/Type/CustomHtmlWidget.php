<?php

declare(strict_types=1);

namespace Modules\Widget\Type;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetRenderContext;
use App\Core\Widget\WidgetTypeInterface;

/**
 * Free-form HTML box (an announcement, an embed-free promo, a custom block). The markup is passed through
 * the site's HTML sanitiser when saved, so scripts and event handlers are removed: this is for content, not
 * for ad or tracking code.
 */
final class CustomHtmlWidget implements WidgetTypeInterface
{
    public function id(): string
    {
        return 'widget.custom_html';
    }

    public function label(): string
    {
        return 'widget.type.custom_html.label';
    }

    public function description(): string
    {
        return 'widget.type.custom_html.description';
    }

    public function icon(): string
    {
        return 'heroicons:code-bracket';
    }

    public function group(): string
    {
        return 'widget.group.general';
    }

    public function fields(): array
    {
        return [
            WidgetField::html('content', 'widget.type.custom_html.content', '', 'widget.type.custom_html.help')->translatable(),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        // Sanitised on save (WidgetFieldSanitizer); printed as markup on purpose.
        return trim((string) ($settings['content'] ?? ''));
    }
}

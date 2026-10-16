<?php

declare(strict_types=1);

namespace Modules\Widget\Type;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Core\Widget\WidgetTypeInterface;

/** Plain text box: paragraphs and line breaks, no markup. */
final class TextWidget implements WidgetTypeInterface
{
    public function id(): string
    {
        return 'widget.text';
    }

    public function label(): string
    {
        return 'widget.type.text.label';
    }

    public function description(): string
    {
        return 'widget.type.text.description';
    }

    public function icon(): string
    {
        return 'heroicons:document-text';
    }

    public function group(): string
    {
        return 'widget.group.general';
    }

    public function fields(): array
    {
        return [
            WidgetField::textarea('text', 'widget.type.text.text', '', 'widget.type.text.help')->translatable(),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $text = trim((string) ($settings['text'] ?? ''));
        if ($text === '') {
            return '';
        }

        $paragraphs = [];
        foreach (preg_split('/\R{2,}/', $text) ?: [] as $block) {
            $paragraphs[] = '<p>'.nl2br(WidgetHtml::esc(trim($block)), false).'</p>';
        }

        return implode('', $paragraphs);
    }
}

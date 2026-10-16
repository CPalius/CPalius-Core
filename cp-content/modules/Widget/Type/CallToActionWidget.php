<?php

declare(strict_types=1);

namespace Modules\Widget\Type;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Core\Widget\WidgetTypeInterface;

/** A short pitch with one button: subscribe, download, contact. */
final class CallToActionWidget implements WidgetTypeInterface
{
    public function id(): string
    {
        return 'widget.call_to_action';
    }

    public function label(): string
    {
        return 'widget.type.cta.label';
    }

    public function description(): string
    {
        return 'widget.type.cta.description';
    }

    public function icon(): string
    {
        return 'heroicons:cursor-arrow-rays';
    }

    public function group(): string
    {
        return 'widget.group.general';
    }

    public function fields(): array
    {
        return [
            WidgetField::textarea('text', 'widget.type.cta.text')->translatable(),
            WidgetField::text('button_label', 'widget.type.cta.button_label')->translatable(),
            WidgetField::url('button_url', 'widget.type.cta.button_url')->translatable(),
            WidgetField::checkbox('new_tab', 'widget.type.cta.new_tab', false),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $url = WidgetHtml::safeUrl((string) ($settings['button_url'] ?? ''));
        $label = trim((string) ($settings['button_label'] ?? ''));
        $text = trim((string) ($settings['text'] ?? ''));
        if ($text === '' && ($url === '' || $label === '')) {
            return '';
        }

        $html = $text !== '' ? '<p>'.nl2br(WidgetHtml::esc($text), false).'</p>' : '';
        if ($url !== '' && $label !== '') {
            $html .= '<p><a href="'.WidgetHtml::esc($url).'" class="btn btn-primary"'.(!empty($settings['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '').'>'.WidgetHtml::esc($label).'</a></p>';
        }

        return $html;
    }
}

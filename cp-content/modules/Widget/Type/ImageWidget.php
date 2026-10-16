<?php

declare(strict_types=1);

namespace Modules\Widget\Type;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Core\Widget\WidgetTypeInterface;

/** A picture or banner, optionally linked, with an optional caption. */
final class ImageWidget implements WidgetTypeInterface
{
    public function id(): string
    {
        return 'widget.image';
    }

    public function label(): string
    {
        return 'widget.type.image.label';
    }

    public function description(): string
    {
        return 'widget.type.image.description';
    }

    public function icon(): string
    {
        return 'heroicons:photo';
    }

    public function group(): string
    {
        return 'widget.group.general';
    }

    public function fields(): array
    {
        return [
            WidgetField::url('image', 'widget.type.image.image', '', 'widget.type.image.image_help')->translatable(),
            WidgetField::text('alt', 'widget.type.image.alt', '', 'widget.type.image.alt_help')->translatable(),
            WidgetField::text('caption', 'widget.type.image.caption')->translatable(),
            WidgetField::url('link', 'widget.type.image.link')->translatable(),
            WidgetField::checkbox('new_tab', 'widget.type.image.new_tab', false),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $src = WidgetHtml::safeUrl((string) ($settings['image'] ?? ''));
        if ($src === '') {
            return '';
        }

        $img = '<img src="'.WidgetHtml::esc($src).'" alt="'.WidgetHtml::esc((string) ($settings['alt'] ?? '')).'" loading="lazy" style="max-width:100%;height:auto">';
        $link = WidgetHtml::safeUrl((string) ($settings['link'] ?? ''));
        if ($link !== '') {
            $img = '<a href="'.WidgetHtml::esc($link).'"'.(!empty($settings['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '').'>'.$img.'</a>';
        }

        $caption = trim((string) ($settings['caption'] ?? ''));

        return '<figure style="margin:0">'.$img.($caption !== '' ? '<figcaption>'.WidgetHtml::esc($caption).'</figcaption>' : '').'</figure>';
    }
}

<?php

declare(strict_types=1);

namespace Modules\Widget\Type;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Core\Widget\WidgetTypeInterface;

/** A row of links to the site's accounts elsewhere; empty ones are left out. */
final class SocialLinksWidget implements WidgetTypeInterface
{
    /** field name => visible label */
    private const NETWORKS = [
        'website' => 'Web',
        'github' => 'GitHub',
        'x' => 'X',
        'linkedin' => 'LinkedIn',
        'instagram' => 'Instagram',
        'youtube' => 'YouTube',
        'facebook' => 'Facebook',
        'telegram' => 'Telegram',
        'mastodon' => 'Mastodon',
    ];

    public function id(): string
    {
        return 'widget.social_links';
    }

    public function label(): string
    {
        return 'widget.type.social.label';
    }

    public function description(): string
    {
        return 'widget.type.social.description';
    }

    public function icon(): string
    {
        return 'heroicons:share';
    }

    public function group(): string
    {
        return 'widget.group.general';
    }

    public function fields(): array
    {
        $fields = [];
        foreach (self::NETWORKS as $name => $label) {
            $fields[] = WidgetField::url($name, 'widget.type.social.'.$name);
        }

        return $fields;
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $links = [];
        foreach (self::NETWORKS as $name => $label) {
            $url = WidgetHtml::safeUrl((string) ($settings[$name] ?? ''));
            if ($url !== '') {
                $links[] = '<a href="'.WidgetHtml::esc($url).'" class="badge" target="_blank" rel="noopener noreferrer me">'.WidgetHtml::esc($label).'</a>';
            }
        }

        return $links === [] ? '' : '<div class="post-tags">'.implode(' ', $links).'</div>';
    }
}

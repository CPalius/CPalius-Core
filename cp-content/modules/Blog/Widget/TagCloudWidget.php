<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Repository\TagRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Tags as badges: the most used ones, shown by popularity or alphabetically. */
final class TagCloudWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    protected function name(): string
    {
        return 'tags';
    }

    public function icon(): string
    {
        return 'heroicons:hashtag';
    }

    public function fields(): array
    {
        return [
            WidgetField::number('limit', 'blog.widget.field.tag_limit', 15, 1, 60),
            WidgetField::select('order', 'blog.widget.field.order', [
                'popular' => 'blog.widget.order.popular',
                'alpha' => 'blog.widget.order.alpha',
            ], 'popular'),
            WidgetField::checkbox('show_count', 'blog.widget.field.show_count', false),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $rows = $this->tags->findMostUsed($context->locale, max(1, (int) ($settings['limit'] ?? 15)));
        if ($rows === []) {
            return '';
        }

        if (($settings['order'] ?? 'popular') === 'alpha') {
            usort($rows, static fn (array $a, array $b): int => strcasecmp($a['tag']->getName(), $b['tag']->getName()));
        }

        $badges = [];
        foreach ($rows as $row) {
            $tag = $row['tag'];
            $url = $this->urls->generate('blog_tag', ['_locale' => $tag->getLocale(), 'slug' => $tag->getSlug()]);
            $badges[] = '<a href="'.WidgetHtml::esc($url).'" class="badge">'.WidgetHtml::esc($tag->getName())
                .(!empty($settings['show_count']) ? ' <span class="text-fs-xs">('.$row['usageCount'].')</span>' : '').'</a>';
        }

        return '<div class="post-tags">'.implode(' ', $badges).'</div>';
    }
}

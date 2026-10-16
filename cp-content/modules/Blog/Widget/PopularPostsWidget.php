<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetRenderContext;
use App\Entity\Node;
use App\Repository\NodeRepository;
use Modules\Blog\Service\PostViewCounter;

/** The most read posts, ranked by the read counter (PostViewCounter). Empty until posts have been read. */
final class PopularPostsWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly PostViewCounter $views,
        private readonly BlogWidgetOutput $output,
    ) {
    }

    protected function name(): string
    {
        return 'popular_posts';
    }

    public function icon(): string
    {
        return 'heroicons:fire';
    }

    public function fields(): array
    {
        return [
            WidgetField::number('limit', 'blog.widget.field.limit', 5, 1, 20),
            WidgetField::checkbox('show_date', 'blog.widget.field.show_date', false),
            WidgetField::checkbox('show_thumb', 'blog.widget.field.show_thumb', false),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $limit = max(1, (int) ($settings['limit'] ?? 5));

        // Ask for far more than shown: the ranking is site-wide, the widget keeps only this language's published posts.
        $ids = $this->views->topIds($limit * 8);
        $byId = $this->nodes->findByIdsIndexed($ids);

        $posts = [];
        foreach ($ids as $id) {
            $post = $byId[$id] ?? null;
            if ($post instanceof Node && $post->getType() === 'post' && $post->getLocale() === $context->locale
                && $post->getStatus() === Node::STATUS_PUBLISHED && $post->getDeletedAt() === null) {
                $posts[] = $post;
            }
            if (\count($posts) === $limit) {
                break;
            }
        }

        return $this->output->postList($posts, $context->locale, !empty($settings['show_date']), !empty($settings['show_thumb']));
    }
}

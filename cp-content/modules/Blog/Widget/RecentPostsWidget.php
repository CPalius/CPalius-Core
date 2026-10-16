<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetRenderContext;
use App\Entity\Node;
use App\Repository\NodeRepository;

/** The newest posts. On a post page the post being read can be left out of its own list. */
final class RecentPostsWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly BlogWidgetOutput $output,
    ) {
    }

    protected function name(): string
    {
        return 'recent_posts';
    }

    public function icon(): string
    {
        return 'heroicons:clock';
    }

    public function fields(): array
    {
        return [
            WidgetField::number('limit', 'blog.widget.field.limit', 5, 1, 20),
            WidgetField::checkbox('show_date', 'blog.widget.field.show_date', false),
            WidgetField::checkbox('show_thumb', 'blog.widget.field.show_thumb', false),
            WidgetField::checkbox('exclude_current', 'blog.widget.field.exclude_current', true),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $limit = max(1, (int) ($settings['limit'] ?? 5));
        $current = $context->subject instanceof Node ? $context->subject->getId() : null;
        $exclude = !empty($settings['exclude_current']) && $current !== null;

        $posts = $this->nodes->findPublishedByTypeAndLocale('post', $context->locale, $limit + ($exclude ? 1 : 0));
        if ($exclude) {
            $posts = array_values(array_filter($posts, static fn (Node $post): bool => $post->getId() !== $current));
        }

        return $this->output->postList(\array_slice($posts, 0, $limit), $context->locale, !empty($settings['show_date']), !empty($settings['show_thumb']));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetRenderContext;
use App\Entity\NodeFieldIndex;
use App\Repository\NodeRepository;

/** Posts marked "featured" in the post form, newest first. */
final class FeaturedPostsWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly BlogWidgetOutput $output,
    ) {
    }

    protected function name(): string
    {
        return 'featured_posts';
    }

    public function icon(): string
    {
        return 'heroicons:star';
    }

    public function fields(): array
    {
        return [
            WidgetField::number('limit', 'blog.widget.field.limit', 5, 1, 20),
            WidgetField::checkbox('show_date', 'blog.widget.field.show_date', false),
            WidgetField::checkbox('show_thumb', 'blog.widget.field.show_thumb', true),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $limit = max(1, (int) ($settings['limit'] ?? 5));

        $posts = $this->nodes->createPublishedByTypeAndLocaleQueryBuilder('post', $context->locale)
            ->innerJoin(NodeFieldIndex::class, 'feat', 'WITH', 'feat.node = n AND feat.fieldName = :featField AND feat.valueInt = 1')
            ->setParameter('featField', 'is_featured')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->output->postList($posts, $context->locale, !empty($settings['show_date']), !empty($settings['show_thumb']));
    }
}

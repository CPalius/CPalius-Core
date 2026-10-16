<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Entity\Node;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\Entity\BlogComment;

/** The posts with the most approved comments. */
final class MostCommentedWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRepository $nodes,
        private readonly BlogWidgetOutput $output,
    ) {
    }

    protected function name(): string
    {
        return 'most_commented';
    }

    public function icon(): string
    {
        return 'heroicons:chat-bubble-left-right';
    }

    public function fields(): array
    {
        return [
            WidgetField::number('limit', 'blog.widget.field.limit', 5, 1, 20),
            WidgetField::checkbox('show_count', 'blog.widget.field.show_comment_count', true),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $limit = max(1, (int) ($settings['limit'] ?? 5));

        /** @var list<array{nodeId: int|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.node) AS nodeId, COUNT(c.id) AS total')
            ->from(BlogComment::class, 'c')
            ->andWhere('c.status = :status')
            ->setParameter('status', BlogComment::STATUS_APPROVED)
            ->groupBy('c.node')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit * 8)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['nodeId']] = (int) $row['total'];
        }
        $byId = $this->nodes->findByIdsIndexed(array_keys($counts));

        $items = [];
        foreach ($counts as $id => $total) {
            $post = $byId[$id] ?? null;
            if (!$post instanceof Node || $post->getType() !== 'post' || $post->getLocale() !== $context->locale
                || $post->getStatus() !== Node::STATUS_PUBLISHED || $post->getDeletedAt() !== null) {
                continue;
            }

            $items[] = '<a href="'.WidgetHtml::esc($this->output->postUrl($post)).'">'.WidgetHtml::esc($post->getTitle()).'</a>'
                .(!empty($settings['show_count']) ? ' <span class="text-fs-xs">('.$total.')</span>' : '');
            if (\count($items) === $limit) {
                break;
            }
        }

        return WidgetHtml::ul($items);
    }
}

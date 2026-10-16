<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\Entity\BlogComment;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The newest approved comments, each with who wrote it and on which post. */
final class LatestCommentsWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BlogWidgetOutput $output,
        private readonly TranslatorInterface $translator,
    ) {
    }

    protected function name(): string
    {
        return 'latest_comments';
    }

    public function icon(): string
    {
        return 'heroicons:chat-bubble-bottom-center-text';
    }

    public function fields(): array
    {
        return [
            WidgetField::number('limit', 'blog.widget.field.limit', 5, 1, 20),
            WidgetField::number('excerpt', 'blog.widget.field.comment_excerpt', 70, 0, 300, 'blog.widget.field.comment_excerpt_help'),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $limit = max(1, (int) ($settings['limit'] ?? 5));
        $excerpt = max(0, (int) ($settings['excerpt'] ?? 70));

        /** @var list<BlogComment> $comments */
        $comments = $this->entityManager->createQueryBuilder()
            ->select('c', 'n')
            ->from(BlogComment::class, 'c')
            ->innerJoin('c.node', 'n')
            ->andWhere('c.status = :status')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :published')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('status', BlogComment::STATUS_APPROVED)
            ->setParameter('type', 'post')
            ->setParameter('locale', $context->locale)
            ->setParameter('published', Node::STATUS_PUBLISHED)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($comments as $comment) {
            $post = $comment->getNode();
            $line = $this->translator->trans('blog.widget.comment_on', [
                'name' => $comment->getDisplayName(),
                'post' => $post->getTitle(),
            ], null, $context->locale);
            $link = '<a href="'.WidgetHtml::esc($this->output->postUrl($post).'#comment-'.$comment->getId()).'">'.WidgetHtml::esc($line).'</a>';

            $text = trim(html_entity_decode(strip_tags($comment->getBody()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            if ($excerpt > 0 && $text !== '') {
                $text = mb_strlen($text) > $excerpt ? mb_substr($text, 0, $excerpt).'…' : $text;
                $link .= '<span class="text-fs-xs" style="display:block;opacity:.7">'.WidgetHtml::esc($text).'</span>';
            }
            $items[] = $link;
        }

        return WidgetHtml::ul($items);
    }
}

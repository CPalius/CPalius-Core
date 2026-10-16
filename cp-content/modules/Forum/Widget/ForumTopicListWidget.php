<?php

declare(strict_types=1);

namespace Modules\Forum\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumTopicRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Public topics as a short list. The subclasses only decide which topics: latest activity, newest, most read.
 */
abstract class ForumTopicListWidget extends AbstractForumWidget
{
    public function __construct(
        protected readonly ForumTopicRepository $topics,
        protected readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<ForumTopic> */
    abstract protected function load(int $limit, string $locale): array;

    public function fields(): array
    {
        return [
            WidgetField::number('limit', 'forum.widget.field.limit', 5, 1, 20),
            WidgetField::checkbox('show_replies', 'forum.widget.field.show_replies', true),
            WidgetField::checkbox('show_author', 'forum.widget.field.show_author', false),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $topics = $this->load(max(1, (int) ($settings['limit'] ?? 5)), $context->locale);
        $items = [];

        foreach ($topics as $topic) {
            $meta = [];
            if (!empty($settings['show_replies'])) {
                $meta[] = $topic->getReplyCount().' ↩';
            }
            if (!empty($settings['show_author'])) {
                $meta[] = $topic->getFirstPosterName();
            }

            $url = $this->urls->generate('forum_topic', ['topicId' => $topic->getId(), 'slug' => $topic->getSlug() ?? '', '_locale' => $topic->getLocale()]);
            $items[] = WidgetHtml::link($url, $topic->getTitle())
                .($meta !== [] ? ' <small class="text-muted">'.WidgetHtml::esc(implode(' · ', $meta)).'</small>' : '');
        }

        return WidgetHtml::ul($items);
    }
}

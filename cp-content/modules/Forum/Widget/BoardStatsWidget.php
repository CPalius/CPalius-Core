<?php

declare(strict_types=1);

namespace Modules\Forum\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use Modules\Forum\Repository\ForumBoardStatsRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Topic and post totals of the board in the visitor's language (the counters the forum already keeps). */
final class BoardStatsWidget extends AbstractForumWidget
{
    public function __construct(
        private readonly ForumBoardStatsRepository $stats,
        private readonly TranslatorInterface $translator,
    ) {
    }

    protected function name(): string
    {
        return 'board_stats';
    }

    public function icon(): string
    {
        return 'heroicons:chart-bar';
    }

    public function fields(): array
    {
        return [
            WidgetField::checkbox('show_topics', 'forum.widget.field.show_topics', true),
            WidgetField::checkbox('show_posts', 'forum.widget.field.show_posts', true),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $stats = $this->stats->findOneByLocale($context->locale);
        if ($stats === null) {
            return '';
        }

        $rows = [];
        if (!empty($settings['show_topics'])) {
            $rows[] = WidgetHtml::esc($this->translator->trans('forum.widget.stats.topics', [], null, $context->locale)).': <strong>'.number_format($stats->getTopicCount(), 0, ',', '.').'</strong>';
        }
        if (!empty($settings['show_posts'])) {
            $rows[] = WidgetHtml::esc($this->translator->trans('forum.widget.stats.posts', [], null, $context->locale)).': <strong>'.number_format($stats->getPostCount(), 0, ',', '.').'</strong>';
        }

        return WidgetHtml::ul($rows);
    }
}

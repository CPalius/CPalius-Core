<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Repository\NodeRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Links to the month-by-month archive, newest month first. */
final class ArchiveWidget extends AbstractBlogWidget
{
    private const MONTHS = [
        1 => 'january', 2 => 'february', 3 => 'march', 4 => 'april', 5 => 'may', 6 => 'june',
        7 => 'july', 8 => 'august', 9 => 'september', 10 => 'october', 11 => 'november', 12 => 'december',
    ];

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    protected function name(): string
    {
        return 'archive';
    }

    public function icon(): string
    {
        return 'heroicons:archive-box';
    }

    public function fields(): array
    {
        return [
            WidgetField::number('limit', 'blog.widget.field.months', 12, 1, 60),
            WidgetField::checkbox('show_count', 'blog.widget.field.show_count', true),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $groups = \array_slice($this->nodes->findPublishedArchiveGroups('post', $context->locale), 0, max(1, (int) ($settings['limit'] ?? 12)));

        $items = [];
        foreach ($groups as $group) {
            $month = $this->translator->trans('blog.widget.month.'.(self::MONTHS[$group['month']] ?? 'january'), [], null, $context->locale);
            $url = $this->urls->generate('blog_archive_month', ['_locale' => $context->locale, 'year' => $group['year'], 'month' => $group['month']]);
            $items[] = '<a href="'.WidgetHtml::esc($url).'">'.WidgetHtml::esc($month.' '.$group['year']).'</a>'
                .(!empty($settings['show_count']) ? ' <span class="text-fs-xs">('.$group['count'].')</span>' : '');
        }

        return WidgetHtml::ul($items);
    }
}

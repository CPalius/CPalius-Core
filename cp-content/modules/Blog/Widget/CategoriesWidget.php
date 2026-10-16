<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Taxonomy\Entity\Term;
use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Repository\CategoryRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The category list, nested as the categories are, with post counts. */
final class CategoriesWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    protected function name(): string
    {
        return 'categories';
    }

    public function icon(): string
    {
        return 'heroicons:folder';
    }

    public function fields(): array
    {
        return [
            WidgetField::checkbox('show_count', 'blog.widget.field.show_count', true),
            WidgetField::checkbox('hide_empty', 'blog.widget.field.hide_empty', true),
            WidgetField::checkbox('hierarchical', 'blog.widget.field.hierarchical', true),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $counts = $this->categories->countPublishedPostsByLocale('post', $context->locale);

        return $this->branch(
            $this->categories->findTreeByLocale($context->locale),
            $counts,
            $context->locale,
            !empty($settings['show_count']),
            !empty($settings['hide_empty']),
            !empty($settings['hierarchical']),
        );
    }

    /**
     * @param iterable<Term>   $terms
     * @param array<int, int>  $counts
     */
    private function branch(iterable $terms, array $counts, string $locale, bool $showCount, bool $hideEmpty, bool $nested): string
    {
        $items = [];
        foreach ($terms as $term) {
            $children = $nested ? $this->branch($term->getChildren(), $counts, $locale, $showCount, $hideEmpty, true) : '';
            $count = $counts[(int) $term->getId()] ?? 0;
            // A category with no posts of its own is still shown when something below it has posts.
            if ($hideEmpty && $count === 0 && $children === '') {
                continue;
            }

            $url = $this->urls->generate('blog_category', ['_locale' => $locale, 'slug' => $term->getSlug()]);
            $items[] = '<a href="'.WidgetHtml::esc($url).'">'.WidgetHtml::esc($term->getName()).'</a>'
                .($showCount ? ' <span class="text-fs-xs">('.$count.')</span>' : '')
                .$children;
        }

        return WidgetHtml::ul($items);
    }
}

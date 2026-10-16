<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A search box that searches the blog's posts by title. */
final class SearchWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    protected function name(): string
    {
        return 'search';
    }

    public function icon(): string
    {
        return 'heroicons:magnifying-glass';
    }

    public function fields(): array
    {
        return [
            WidgetField::text('placeholder', 'blog.widget.field.placeholder', '', 'blog.widget.field.placeholder_help')->translatable(),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $action = $this->urls->generate('blog_search', ['_locale' => $context->locale]);
        $placeholder = trim((string) ($settings['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = $this->translator->trans('blog.widget.search_placeholder', [], null, $context->locale);
        }

        return '<form method="get" action="'.WidgetHtml::esc($action).'" role="search">'
            .'<input type="search" name="q" class="form-control" maxlength="80" placeholder="'.WidgetHtml::esc($placeholder).'" aria-label="'.WidgetHtml::esc($placeholder).'">'
            .'</form>';
    }
}

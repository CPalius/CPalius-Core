<?php

declare(strict_types=1);

namespace Modules\Blog\Hooks;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;
use App\Core\Localization\LocaleProvider;
use App\Repository\TagRepository;

/**
 * Phase 7B #[CpHook] listener for blog.render.sidebar (DI); runs beside the flat-file hook.
 */
final class BlogAttributeHooks
{
    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[CpHook('blog.render.sidebar', priority: 50)]
    public function onSidebarRender(HookContext $context): HookContext
    {
        $locale = $this->localeProvider->resolve(is_string($context->get('locale')) ? $context->get('locale') : null);
        $popularTags = $this->tagRepository->findMostUsed($locale, 5);

        if ($popularTags === []) {
            return $context;
        }

        // findMostUsed() returns rows, not terms: ['tag' => Term, 'usageCount' => int].
        // Calling getName() on the row itself used to fatal here — silently, because
        // the hook engine quarantines a failing listener and keeps the page up. The
        // sidebar simply stopped rendering and nothing reported it.
        $labels = array_map(
            static fn (array $row): string => htmlspecialchars($row['tag']->getName(), ENT_QUOTES, 'UTF-8'),
            $popularTags,
        );

        return $context->appendHtml(sprintf(
            '<div class="cp-hook-blog-tags"><p>Popüler Etiketler: %s</p></div>',
            implode(', ', $labels),
        ));
    }
}

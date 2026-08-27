<?php

declare(strict_types=1);

namespace Modules\Blog\Hooks;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;
use App\Repository\TagRepository;

/**
 * Faz 7B örnek Attribute Kanca (Symfony tarzı): aynı "blog.render.sidebar"
 * noktasına, flat-file dosyasıyla YAN YANA, DI konteynerinden bir bağımlılık
 * (TagRepository) ihtiyaç duyduğu için attribute kulvarından bağlanan bir
 * dinleyici. HookManager iki kulvarı da tetikler; ikisinin de HTML çıktısı
 * appendHtml() ile aynı $context->getHtml() dizisinde birikir.
 */
final class BlogAttributeHooks
{
    public function __construct(
        private readonly TagRepository $tagRepository,
    ) {
    }

    #[CpHook('blog.render.sidebar', priority: 50)]
    public function onSidebarRender(HookContext $context): HookContext
    {
        $locale = is_string($context->get('locale')) ? $context->get('locale') : 'tr';
        $popularTags = $this->tagRepository->findMostUsed($locale, 5);

        if ($popularTags === []) {
            return $context;
        }

        $labels = array_map(static fn ($tag): string => htmlspecialchars((string) $tag->getName(), ENT_QUOTES, 'UTF-8'), $popularTags);

        return $context->appendHtml(sprintf(
            '<div class="cp-hook-blog-tags"><p>Popüler Etiketler: %s</p></div>',
            implode(', ', $labels),
        ));
    }
}

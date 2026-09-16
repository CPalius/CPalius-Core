<?php

declare(strict_types=1);

namespace Modules\Showcase\Hooks;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;
use App\Core\Localization\LocaleProvider;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Service\ShowcasePresenter;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Showcase content rendered into hook points other modules already publish.
 *
 * This is the inbound half of the integration story, and it is one-directional
 * by design: the Showcase module listens on generic hook points and returns HTML.
 * It never imports Blog or Forum, and if neither is installed nothing calls these
 * methods — a listener that is never triggered costs nothing.
 *
 * Every method returns the context unchanged when it has nothing to add, so the
 * host page renders exactly as it did before.
 */
final class ShowcaseAttributeHooks
{
    private const SIDEBAR_LIMIT = 3;

    public function __construct(
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcasePresenter $presenter,
        private readonly LocaleProvider $localeProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Promoted entries in the blog sidebar. The hook point belongs to Blog; this
     * module only answers it, which is why there is no dependency either way.
     */
    #[CpHook('blog.render.sidebar', priority: 60)]
    public function onBlogSidebar(HookContext $context): HookContext
    {
        return $this->appendPromotedList($context, 'showcase.hook.blog_sidebar_title');
    }

    private function appendPromotedList(HookContext $context, string $titleKey): HookContext
    {
        $locale = $this->localeProvider->resolve(
            \is_string($context->get('locale')) ? $context->get('locale') : null,
        );

        $items = $this->items->findVisible($locale, self::SIDEBAR_LIMIT, featuredOnly: true);

        if ($items === []) {
            $items = $this->items->findVisible($locale, self::SIDEBAR_LIMIT);
        }

        if ($items === []) {
            return $context;
        }

        $rows = '';

        foreach ($items as $item) {
            if (!$item instanceof ShowcaseItem) {
                continue;
            }

            $url = $this->itemUrl($item);

            if ($url === null) {
                continue;
            }

            $price = $this->presenter->price($item, $locale);

            $rows .= sprintf(
                '<li><a href="%s">%s</a>%s</li>',
                $this->escape($url),
                $this->escape($item->getTitle()),
                $price !== null ? '<span class="cp-hook-showcase__price"> — '.$this->escape($price).'</span>' : '',
            );
        }

        if ($rows === '') {
            return $context;
        }

        // The hook engine treats returned HTML as trusted output, so every value
        // interpolated above is escaped here rather than anywhere downstream.
        return $context->appendHtml(sprintf(
            '<div class="cp-hook-showcase"><p class="cp-hook-showcase__title">%s</p><ul>%s</ul></div>',
            $this->escape($this->translator->trans($titleKey, [], null, $locale)),
            $rows,
        ));
    }

    private function itemUrl(ShowcaseItem $item): ?string
    {
        try {
            return $this->urlGenerator->generate('showcase_show', [
                '_locale' => $item->getLocale(),
                'slug' => $item->getSlug(),
            ]);
        } catch (RoutingException) {
            return null;
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
    }
}

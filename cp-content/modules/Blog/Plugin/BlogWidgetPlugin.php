<?php

declare(strict_types=1);

namespace Modules\Blog\Plugin;

use App\Core\Plugin\PluginInterface;
use App\Core\Settings\SettingsRegistry;
use App\Core\Localization\LocaleProvider;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Twig\Environment;

/**
 * Sidebar widget: recent posts + popular tags. Limits come from BlogWidgetSettings.
 * Invoke via {{ cp_plugin('blog_widget', {locale: ...}) }}; locale falls back safely.
 */
final class BlogWidgetPlugin implements PluginInterface
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly TagRepository $tagRepository,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly Environment $twig,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function getName(): string
    {
        return 'blog_widget';
    }

    public function getLabel(): string
    {
        return 'Blog Widget (Son Yazılar & Etiketler)';
    }

    public function isActive(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(array $context = []): string
    {
        $locale = $this->localeProvider->resolve(is_string($context['locale'] ?? null) ? $context['locale'] : null);

        $recentPostsLimit = (int) $this->settingsRegistry->get('blog_widget.recent_posts_limit');
        $popularTagsLimit = (int) $this->settingsRegistry->get('blog_widget.popular_tags_limit');

        $recentPosts = $this->nodeRepository->findPublishedByTypeAndLocale(
            self::NODE_TYPE,
            $locale,
            $recentPostsLimit,
        );

        $popularTags = $this->tagRepository->findMostUsed($locale, $popularTagsLimit);

        return $this->twig->render('@BlogModule/plugin/widget.html.twig', [
            'recentPosts' => $recentPosts,
            'popularTags' => $popularTags,
            'socialGithubUrl' => (string) $this->settingsRegistry->get('blog_widget.social_github_url'),
            'socialTwitterUrl' => (string) $this->settingsRegistry->get('blog_widget.social_twitter_url'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Blog\Plugin;

use App\Core\Plugin\PluginInterface;
use App\Core\Settings\SettingsRegistry;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Twig\Environment;

/**
 * Kullanıcının Faz 3 talebindeki "Widget" eklentisi — Slawman'ın
 * SidebarWidget'ından ilham alır ama CPalius'un PluginInterface
 * sözleşmesine (Twig şablonuna kendi render() ile HTML üreten, DB
 * entity'si OLMAYAN) uyarlanır: son N yayınlanmış yazı + en çok
 * kullanılan N etiket.
 *
 * Faz 5: RECENT_POSTS_LIMIT / POPULAR_TAGS_LIMIT sabitleri kaldırıldı —
 * bu limitler artık Modules\Blog\Settings\BlogWidgetSettings üzerinden
 * #[CpSetting] ile tanımlanıp AACP > Eklenti Ayarları ekranından
 * yönetiliyor (bkz. SettingsRegistry). DB'de override yoksa
 * SettingsRegistry->get() zaten tanımın default'unu (5 / 10) döner —
 * bu yüzden bu sınıf hiçbir "sabit" değer taşımaz.
 *
 * {{ cp_plugin('blog_widget', {locale: app.request.locale}) }} ile
 * çağrılır — locale context'ten alınır, verilmezse 'tr'ye düşer
 * (fail-safe: hiçbir zaman locale eksikliğinden patlamaz).
 */
final class BlogWidgetPlugin implements PluginInterface
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly TagRepository $tagRepository,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly Environment $twig,
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
        $locale = is_string($context['locale'] ?? null) ? $context['locale'] : 'tr';

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

<?php

declare(strict_types=1);

namespace Modules\Blog\Plugin;

use App\Core\Plugin\PluginInterface;
use App\Repository\NodeRepository;
use Twig\Environment;

/**
 * Kullanıcının Faz 3 talebindeki "Arşiv" eklentisi — Slawman'ın tarihsel
 * (yıl/ay bazlı) süzme fikrini CPalius'un PluginInterface sözleşmesine
 * uyarlar: NodeRepository::findPublishedArchiveGroups() ile kronolojik
 * bir yıl/ay/adet listesi üretir, her satır blog_archive_month route'una
 * (bkz. PostFrontController::archiveMonth()) bağlanır.
 *
 * {{ cp_plugin('blog_archive', {locale: app.request.locale}) }} ile
 * çağrılır.
 */
final class BlogArchivePlugin implements PluginInterface
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly Environment $twig,
    ) {
    }

    public function getName(): string
    {
        return 'blog_archive';
    }

    public function getLabel(): string
    {
        return 'Blog Arşivi (Yıl/Ay)';
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

        $archiveGroups = $this->nodeRepository->findPublishedArchiveGroups(self::NODE_TYPE, $locale);

        return $this->twig->render('@BlogModule/plugin/archive.html.twig', [
            'archiveGroups' => $archiveGroups,
            'locale' => $locale,
        ]);
    }
}

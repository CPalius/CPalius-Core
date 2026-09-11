<?php

declare(strict_types=1);

namespace Modules\Blog\Plugin;

use App\Core\Localization\LocaleProvider;
use App\Core\Plugin\PluginInterface;
use App\Repository\NodeRepository;
use Twig\Environment;

/**
 * Year/month archive plugin via findPublishedArchiveGroups(); links to blog_archive_month.
 */
final class BlogArchivePlugin implements PluginInterface
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly Environment $twig,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function getName(): string
    {
        return 'blog_archive';
    }

    public function getLabel(): string
    {
        return 'blog.plugin.archive';
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

        $archiveGroups = $this->nodeRepository->findPublishedArchiveGroups(self::NODE_TYPE, $locale);

        return $this->twig->render('@BlogModule/plugin/archive.html.twig', [
            'archiveGroups' => $archiveGroups,
            'locale' => $locale,
        ]);
    }
}

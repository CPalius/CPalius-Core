<?php

declare(strict_types=1);

namespace Modules\Seo\Sitemap\Source;

use Modules\Forum\Entity\ForumSection;
use Modules\Forum\ForumNodeType;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Seo\Contract\SeoSitemapSourceInterface;
use Modules\Seo\Engine\SeoUrlBuilder;
use Modules\Seo\Sitemap\SitemapUrl;

final class ForumSitemapSource implements SeoSitemapSourceInterface
{
    public function __construct(
        private readonly SeoUrlBuilder $urls,
        private readonly ?ForumSectionRepository $sectionRepository = null,
        private readonly ?ForumTopicRepository $topicRepository = null,
    ) {
    }

    public function name(): string
    {
        return 'forum';
    }

    public function urls(string $locale): iterable
    {
        if ($this->sectionRepository === null || $this->topicRepository === null) {
            return;
        }

        foreach ($this->sectionRepository->findAllByLocale($locale) as $section) {
            if ($section->getNodeType() === ForumNodeType::Link) {
                continue;
            }
            if ($section->getRequiredCapability() !== null && $section->getRequiredCapability() !== '') {
                continue;
            }
            yield new SitemapUrl(
                loc: $this->urls->absolute('forum_section', [
                    '_locale' => $locale,
                    'sectionSlug' => $section->getSlug(),
                ], $locale),
                changefreq: 'daily',
                priority: '0.6',
                alternates: $this->sectionAlternates($section),
            );
        }

        $offset = 0;
        do {
            $batch = $this->topicRepository->findPublicForSitemap($locale, 200, $offset);
            foreach ($batch as $topic) {
                yield new SitemapUrl(
                    loc: $this->urls->absolute('forum_topic', [
                        '_locale' => $locale,
                        'topicId' => $topic->getId(),
                        'slug' => $topic->getSlug() ?? '',
                    ], $locale),
                    lastmod: $topic->getUpdatedAt(),
                    changefreq: 'hourly',
                    priority: '0.7',
                );
            }
            $offset += 200;
        } while (\count($batch) === 200);
    }

    /**
     * @return array<string, string>
     */
    private function sectionAlternates(ForumSection $section): array
    {
        $group = $section->getTranslationGroupId();
        if ($group === null || $this->sectionRepository === null) {
            return [];
        }

        $alternates = [];
        foreach ($this->sectionRepository->findBy(['translationGroupId' => $group]) as $row) {
            $alternates[$row->getLocale()] = $this->urls->absolute('forum_section', [
                '_locale' => $row->getLocale(),
                'sectionSlug' => $row->getSlug(),
            ], $row->getLocale());
        }

        return $alternates;
    }
}

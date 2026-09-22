<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Search\SearchGroup;
use App\Core\Search\SearchHit;
use App\Core\Search\SearchProviderInterface;
use App\Core\Search\SearchText;
use Modules\DnsTools\Catalog\PresentedTool;
use Modules\DnsTools\Catalog\ToolCatalog;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Enabled catalogue tools for the header global search.
 * Core never imports this class: it is discovered through the
 * cpalius.search.provider tag on SearchProviderInterface.
 */
final class DnsToolsSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly ToolRegistry $registry,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKey(): string
    {
        return 'dnstools';
    }

    public function getLabel(): string
    {
        return 'dnstools.search.group_label';
    }

    public function getIcon(): string
    {
        return 'bi-hdd-network';
    }

    public function getPriority(): int
    {
        return 15;
    }

    public function search(string $term, string $locale, int $limit): SearchGroup
    {
        $needle = $this->normalize($term);
        $scored = [];

        foreach ($this->catalog->all() as $definition) {
            $tool = $this->registry->present($definition, $locale);
            if (!$tool->enabled) {
                continue;
            }

            $score = $this->score($tool, $needle, $locale);
            if ($score <= 0) {
                continue;
            }

            $url = $this->safeUrl('dnstools_tool', ['_locale' => $locale, 'slug' => $tool->slug]);
            if ($url === null) {
                continue;
            }

            $scored[] = [
                $score,
                new SearchHit(
                    title: $tool->title,
                    url: $url,
                    excerpt: SearchText::snippet($tool->subtitle !== '' ? $tool->subtitle : $tool->description),
                ),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $hits = [];
        foreach ($scored as $row) {
            $hits[] = $row[1];
            if (\count($hits) >= $limit) {
                break;
            }
        }

        $hub = $this->hubHit($needle, $locale, $hits !== []);
        if ($hub instanceof SearchHit) {
            array_unshift($hits, $hub);
            $hits = \array_slice($hits, 0, $limit);
        }

        return new SearchGroup(
            key: $this->getKey(),
            label: $this->getLabel(),
            icon: $this->getIcon(),
            hits: $hits,
            total: \count($hits),
            moreUrl: $hits !== [] ? $this->safeUrl('dnstools_all', ['_locale' => $locale]) : null,
        );
    }

    private function hubHit(string $needle, string $locale, bool $hasToolHits): ?SearchHit
    {
        $copy = $this->registry->pageCopy('index', 'dnstools.seo.index', $locale);
        $title = $this->normalize($copy['title']);
        $generic = $this->normalize('dns tools dns araçları dns araclari');
        $titleHit = str_contains($title, $needle) || $this->tokensMatch($needle, $title);
        $genericHit = str_contains($generic, $needle) || $this->tokensMatch($needle, $generic);
        $keywordHit = !$hasToolHits && str_contains($this->normalize($copy['keywords']), $needle);
        if (!$titleHit && !$genericHit && !$keywordHit) {
            return null;
        }

        $url = $this->safeUrl('dnstools_index', ['_locale' => $locale]);
        if ($url === null) {
            return null;
        }

        return new SearchHit(
            title: $copy['title'],
            url: $url,
            excerpt: SearchText::snippet($copy['description']),
        );
    }

    private function score(PresentedTool $tool, string $needle, string $locale): int
    {
        $title = $this->normalize($tool->title);
        $slug = $this->normalize(str_replace('-', ' ', $tool->slug));
        $compactSlug = str_replace(' ', '', $slug);
        $compactNeedle = str_replace(' ', '', $needle);
        $keywords = $this->normalize($tool->keywords);
        $blurb = $this->normalize($tool->subtitle.' '.$tool->description);
        $category = $this->normalize($this->translator->trans('dnstools.category.'.$tool->category, [], null, $locale));

        if ($title === $needle || $slug === $needle || $compactSlug === $compactNeedle) {
            return 100;
        }

        if (str_starts_with($title, $needle) || str_starts_with($slug, $needle)) {
            return 80;
        }

        if (str_contains($title, $needle) || str_contains($slug, $needle) || str_contains($compactSlug, $compactNeedle)) {
            return 70;
        }

        if (str_contains($keywords, $needle) || $this->tokensMatch($needle, $title.' '.$slug.' '.$keywords)) {
            return 50;
        }

        if (str_contains($blurb, $needle) || str_contains($category, $needle)) {
            return 25;
        }

        return 0;
    }

    private function tokensMatch(string $needle, string $haystack): bool
    {
        $tokens = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY);
        if (!\is_array($tokens) || $tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2 || !str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ı' => 'i', 'İ' => 'i']);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function safeUrl(string $route, array $params): ?string
    {
        try {
            return $this->urlGenerator->generate($route, $params);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}

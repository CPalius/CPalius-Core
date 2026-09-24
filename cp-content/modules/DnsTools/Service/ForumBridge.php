<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Optional Forum hooks. DnsTools stays usable when Forum is not active.
 */
final class ForumBridge
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly ToolConfigStore $config,
        private readonly ?ForumSectionHierarchyService $hierarchy = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->forumEnabled() && $this->searchUrl() !== null;
    }

    public function searchUrl(): ?string
    {
        try {
            return $this->urls->generate('forum_search');
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    public function composeUrl(): ?string
    {
        $section = $this->config->forumSection();
        if ($section === '') {
            return $this->newTopicTemplate();
        }

        return $this->newTopicPath($section);
    }

    public function newTopicTemplate(): ?string
    {
        return $this->newTopicPath('__SLUG__');
    }

    public function newTopicPath(string $sectionSlug): ?string
    {
        if ($sectionSlug === '') {
            return null;
        }

        try {
            return $this->urls->generate('forum_new_topic', ['sectionSlug' => $sectionSlug]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    public function newTopicHref(string $sectionSlug, string $title, string $prefill = ''): ?string
    {
        $base = $this->newTopicPath($sectionSlug);
        if ($base === null) {
            return null;
        }

        $query = ['title' => mb_substr($title, 0, 180)];
        if (preg_match('/^[a-f0-9]{16}$/', $prefill) === 1) {
            $query['pf'] = $prefill;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($query);
    }

    public function wizardUrl(string $title): ?string
    {
        try {
            $base = $this->urls->generate('dnstools_forum_ask');
        } catch (RouteNotFoundException) {
            return $this->searchHref($title);
        }

        $title = mb_substr(trim($title), 0, 180);
        if ($title === '') {
            return $base;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').'title='.rawurlencode($title);
    }

    /**
     * @return list<array{slug: string, title: string}>
     */
    public function boards(string $locale): array
    {
        if (!$this->hierarchy instanceof ForumSectionHierarchyService) {
            return [];
        }

        return array_map(
            static fn (ForumSection $section): array => [
                'slug' => $section->getSlug(),
                'title' => $section->getTitle(),
            ],
            $this->hierarchy->getTopicBoards($locale),
        );
    }

    public function searchHref(string $query): ?string
    {
        $base = $this->searchUrl();
        if ($base === null || $query === '') {
            return $base;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').'q='.rawurlencode($query);
    }

    public function composeHref(string $title, string $body = ''): ?string
    {
        unset($body);

        return $this->wizardUrl($title) ?? $this->searchHref($title);
    }
}

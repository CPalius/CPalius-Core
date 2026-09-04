<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use App\Core\Settings\SettingsRegistry;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumNodeType;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SeoUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final class ForumSeoProvider implements SeoPageProviderInterface
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SeoUrlBuilder $urls,
        private readonly ?ForumSectionRepository $sectionRepository = null,
        private readonly ?ForumTopicRepository $topicRepository = null,
    ) {
    }

    public function priority(): int
    {
        return 60;
    }

    public function supports(Request $request): bool
    {
        if ($this->sectionRepository === null || $this->topicRepository === null) {
            return false;
        }

        $route = (string) $request->attributes->get('_route', '');

        return str_starts_with($route, 'forum_') && !str_starts_with($route, 'forum_notifications');
    }

    public function document(Request $request): ?SeoDocument
    {
        if ($this->sectionRepository === null || $this->topicRepository === null) {
            return null;
        }

        $route = (string) $request->attributes->get('_route', '');
        $locale = (string) $request->getLocale();

        return match ($route) {
            'forum_topic', 'forum_thread_by_slug' => $this->topic($request, $locale),
            'forum_section' => $this->section($request, $locale),
            'forum_index', 'forum_board_legacy' => $this->index($locale),
            default => null,
        };
    }

    private function topic(Request $request, string $locale): ?SeoDocument
    {
        $topicId = (int) $request->attributes->get('topicId', 0);
        $topic = $topicId > 0 ? $this->topicRepository?->find($topicId) : null;
        if (!$topic instanceof ForumTopic) {
            $slug = (string) $request->attributes->get('slug', '');
            $topic = $slug !== '' ? $this->topicRepository?->findOneBy(['slug' => $slug]) : null;
        }
        if (!$topic instanceof ForumTopic) {
            return null;
        }

        $section = $topic->getSection();
        $url = $this->urls->absolute('forum_topic', [
            '_locale' => $locale,
            'topicId' => $topic->getId(),
            'slug' => $topic->getSlug() ?? '',
        ], $locale);

        return new SeoDocument(
            headline: $topic->getTitle(),
            description: (string) ($topic->getPreview() ?? $topic->getDescription() ?? ''),
            canonicalPath: $url,
            ogType: 'article',
            contentKind: 'forum',
            schemaType: (string) $this->settings->get('seo.forum.topic_schema', 'DiscussionForumPosting'),
            breadcrumbs: [
                ['name' => 'Forum', 'url' => $this->urls->absolute('forum_index', ['_locale' => $locale], $locale)],
                ['name' => $section->getTitle(), 'url' => $this->urls->absolute('forum_section', [
                    '_locale' => $locale,
                    'sectionSlug' => $section->getSlug(),
                ], $locale)],
            ],
            authorName: $topic->getFirstPosterName() !== '' ? $topic->getFirstPosterName() : null,
            publishedAt: $topic->getCreatedAt(),
            modifiedAt: $topic->getUpdatedAt(),
            locale: $locale,
            schemaExtra: [
                'interactionStatistic' => [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/CommentAction',
                    'userInteractionCount' => $topic->getPostCount(),
                ],
            ],
        );
    }

    private function section(Request $request, string $locale): ?SeoDocument
    {
        $slug = (string) $request->attributes->get('sectionSlug', '');
        $section = $this->sectionRepository?->findOneBySlugAndLocale($slug, $locale);
        if (!$section instanceof ForumSection) {
            return null;
        }

        $url = $this->urls->absolute('forum_section', ['_locale' => $locale, 'sectionSlug' => $section->getSlug()], $locale);

        return new SeoDocument(
            headline: $section->getTitle(),
            description: (string) ($section->getDescription() ?? ''),
            canonicalPath: $url,
            ogType: 'website',
            contentKind: 'forum',
            schemaType: $section->getNodeType() === ForumNodeType::Forum ? 'CollectionPage' : 'WebPage',
            breadcrumbs: [
                ['name' => 'Forum', 'url' => $this->urls->absolute('forum_index', ['_locale' => $locale], $locale)],
            ],
            locale: $locale,
        );
    }

    private function index(string $locale): SeoDocument
    {
        $url = $this->urls->absolute('forum_index', ['_locale' => $locale], $locale);
        $description = (string) $this->settings->getForLocale('forum.home_meta_description', $locale, '');

        return new SeoDocument(
            headline: 'Forum',
            description: $description,
            canonicalPath: $url,
            ogType: 'website',
            contentKind: 'forum',
            schemaType: 'CollectionPage',
            locale: $locale,
        );
    }
}

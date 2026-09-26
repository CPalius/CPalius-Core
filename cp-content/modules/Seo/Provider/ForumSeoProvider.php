<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDictionary;
use Modules\Forum\ForumNodeType;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumPostVoteRepository;
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
        private readonly ?ForumPostRepository $postRepository = null,
        private readonly ?ForumPostVoteRepository $voteRepository = null,
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
            schemaExtra: $this->topicSchema($topic, $request->query->getInt('page', 1), $locale),
        );
    }

    /**
     * Google DiscussionForumPosting: the opening post is the entity (text, author.url),
     * the replies visible on this page become comment[] — the same slice the page renders.
     *
     * @return array<string, mixed>
     */
    private function topicSchema(ForumTopic $topic, int $page, string $locale): array
    {
        $extra = [
            'interactionStatistic' => [[
                '@type' => 'InteractionCounter',
                'interactionType' => 'https://schema.org/CommentAction',
                'userInteractionCount' => $topic->getReplyCount(),
            ]],
        ];
        if ($this->postRepository === null) {
            return $extra;
        }

        $first = $this->postRepository->findFirstByTopic($topic);
        $perPage = max(1, (int) $this->settings->get('forum.posts_per_page', ForumDictionary::DEFAULT_POSTS_PER_PAGE));
        /** @var list<ForumPost> $posts */
        $posts = $this->postRepository->createTopicPostsQueryBuilder($topic)
            ->setFirstResult((max(1, $page) - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $all = $first instanceof ForumPost ? [$first, ...$posts] : $posts;
        $postIds = array_values(array_unique(array_map(static fn (ForumPost $p): int => (int) $p->getId(), $all)));
        $userIds = array_values(array_unique(array_filter(array_map(static fn (ForumPost $p): int => (int) $p->getAuthor()?->getId(), $all))));
        $votes = $this->voteRepository?->countBothByPostIds($postIds) ?? [];
        $writes = $this->postRepository->countPublicPostsForUserIds($userIds);

        if ($first instanceof ForumPost) {
            $text = $this->plainText($first->getBody());
            $extra['text'] = $text !== '' ? $text : $topic->getTitle();
            $extra['author'] = $this->person($first, $writes, $locale);
            $extra['interactionStatistic'][] = $this->likeCounter($votes[(int) $first->getId()]['likes'] ?? 0);
        }

        $comments = [];
        foreach ($posts as $post) {
            $text = $this->plainText($post->getBody());
            if ($post->getId() === $first?->getId() || $text === '') {
                continue;
            }
            $comments[] = [
                '@type' => 'Comment',
                'text' => $text,
                'author' => $this->person($post, $writes, $locale),
                'datePublished' => $post->getCreatedAt()->format(\DATE_ATOM),
                'interactionStatistic' => $this->likeCounter($votes[(int) $post->getId()]['likes'] ?? 0),
            ];
        }
        if ($comments !== []) {
            $extra['comment'] = $comments;
        }

        return $extra;
    }

    /**
     * @param array<int, int> $writes public post count keyed by user id
     *
     * @return array<string, mixed>
     */
    private function person(ForumPost $post, array $writes, string $locale): array
    {
        $user = $post->getAuthor();
        $slug = $user instanceof User ? $user->getProfileSlug() : '';
        $person = ['@type' => 'Person', 'name' => $post->getPosterName() !== '' ? $post->getPosterName() : $slug];
        if ($slug !== '') {
            $person['url'] = $this->urls->absolute('forum_profile', ['_locale' => $locale, 'username' => $slug], $locale);
            $person['agentInteractionStatistic'] = [
                '@type' => 'InteractionCounter',
                'interactionType' => 'https://schema.org/WriteAction',
                'userInteractionCount' => $writes[(int) $user?->getId()] ?? 0,
            ];
        }

        return $person;
    }

    /**
     * @return array<string, mixed>
     */
    private function likeCounter(int $count): array
    {
        return [
            '@type' => 'InteractionCounter',
            'interactionType' => 'https://schema.org/LikeAction',
            'userInteractionCount' => $count,
        ];
    }

    private function plainText(string $html): string
    {
        $text = strip_tags(str_replace(['<br', '</p>', '</li>'], ["\n<br", "</p>\n", "</li>\n"], $html));
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace(['/[ \t]+/u', '/\n\s*\n\s*/u'], [' ', "\n\n"], $text));
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
        $title = (string) $this->settings->getForLocale('forum.home_title', $locale, 'Forum');
        $description = (string) $this->settings->getForLocale('forum.home_meta_description', $locale, '');
        $keywords = (string) $this->settings->getForLocale('forum.home_meta_keywords', $locale, '');

        return new SeoDocument(
            headline: $title !== '' ? $title : 'Forum',
            description: $description,
            canonicalPath: $url,
            ogType: 'website',
            contentKind: 'forum',
            schemaType: 'CollectionPage',
            locale: $locale,
            keywords: $keywords,
        );
    }
}

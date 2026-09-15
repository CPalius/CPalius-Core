<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumUserReputation;
use Modules\Forum\Install\ReputationSchema;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Repository\ForumUserReputationRepository;

/**
 * Reputation votes and counter sync (User::$data reputation_positive/negative cache).
 */
final class ForumReputationService
{
    public const DATA_POSITIVE = 'forum_reputation_positive';
    public const DATA_NEGATIVE = 'forum_reputation_negative';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumUserReputationRepository $reputationRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumDomainDispatcher $domainDispatcher,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly ReputationSchema $schema,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->settingsRegistry->get('forum.reputation_enabled') ?? true);
    }

    /**
     * Records one reputation vote.
     *
     * $topicUrl replaced the mandatory topic picker. The picker only ever
     * offered topics the RECIPIENT had taken part in, which meant reputation
     * could not be given for anything else — a helpful private message, a pull
     * request, a post in a section the giver could see and the query did not
     * return. In practice the dropdown was empty often enough that giving rep
     * failed outright, so the requirement bought nothing and cost the feature.
     *
     * The link is now optional and free-text, and is resolved to a real topic
     * when it points at one here (see resolveTopicFromUrl).
     *
     * @throws \InvalidArgumentException
     */
    public function give(
        User $from,
        User $to,
        int $value,
        string $reason,
        ?ForumTopic $topic = null,
        ?ForumPost $post = null,
        ?string $comment = null,
        ?string $topicUrl = null,
    ): ForumUserReputation {
        if (!$this->isEnabled()) {
            throw new \InvalidArgumentException('reputation_disabled');
        }

        if ($from->getId() === $to->getId()) {
            throw new \InvalidArgumentException('reputation_self');
        }

        if (!\in_array($reason, ForumUserReputation::REASONS, true)) {
            throw new \InvalidArgumentException('reputation_reason');
        }

        if ($reason === ForumUserReputation::REASON_OTHER && ($comment === null || trim($comment) === '')) {
            throw new \InvalidArgumentException('reputation_comment_required');
        }

        $value = $value === ForumUserReputation::VALUE_NEGATIVE
            ? ForumUserReputation::VALUE_NEGATIVE
            : ForumUserReputation::VALUE_POSITIVE;

        if ($post !== null) {
            if ($post->getAuthor()?->getId() !== $to->getId()) {
                throw new \InvalidArgumentException('reputation_post_mismatch');
            }
            $topic ??= $post->getTopic();
        }

        $topicUrl = $this->normaliseUrl($topicUrl);
        $topic ??= $this->resolveTopicFromUrl($topicUrl);

        // The relation still carries its old invariant — a linked topic is one
        // the recipient took part in — but a link that fails it is no longer an
        // error. It is kept as a URL and the relation is left empty, so the
        // ledger never claims a connection the data does not support and the
        // giver is not told off for pasting a link that happens to be somebody
        // else's thread.
        if ($topic !== null && !$this->topicBelongsToUser($topic, $to)) {
            if ($post !== null) {
                throw new \InvalidArgumentException('reputation_topic_mismatch');
            }

            $topic = null;
        }

        // topic_url arrived in a file patch, which cannot run the module's SQL
        // migration; make sure the column is there before the insert needs it.
        $this->schema->ensure();

        $reputation = new ForumUserReputation($from, $to, $value, $reason, $topic, $post, $comment, $topicUrl);
        $this->entityManager->persist($reputation);

        $this->bumpCachedCounter($to, $value);
        $this->entityManager->flush();

        $this->domainDispatcher->dispatchReputationGiven($reputation);

        return $reputation;
    }

    /**
     * Finds the topic a pasted URL points at, or null.
     *
     * Matches on the numeric id in the forum topic path rather than on the host:
     * a link copied from behind a CDN, an alternate domain or a locale prefix is
     * the same thread, and refusing it would push the operator right back to the
     * picker this replaced. Nothing is trusted from the URL beyond that id — the
     * topic is loaded from the database and re-checked like any other.
     */
    public function resolveTopicFromUrl(?string $url): ?ForumTopic
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, \PHP_URL_PATH);

        if (!\is_string($path) || preg_match('#/thread/(\d+)#', $path, $matches) !== 1) {
            return null;
        }

        $topic = $this->topicRepository->find((int) $matches[1]);

        return $topic instanceof ForumTopic ? $topic : null;
    }

    /**
     * Public topics the user opened or replied in.
     *
     * Kept for callers that still want to offer a shortcut list; the reputation
     * form no longer requires one.
     *
     * @return list<ForumTopic>
     */
    public function selectableTopicsForUser(User $user, int $limit = 50): array
    {
        $started = $this->topicRepository->createPublicByAuthorQueryBuilder($user)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $replied = $this->topicRepository->createRepliedTopicsByAuthorQueryBuilder($user)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach (array_merge($started, $replied) as $topic) {
            if ($topic instanceof ForumTopic && $topic->getId() !== null) {
                $map[$topic->getId()] = $topic;
            }
        }

        uasort($map, static fn (ForumTopic $a, ForumTopic $b): int => $b->getUpdatedAt() <=> $a->getUpdatedAt());

        return array_values(array_slice($map, 0, $limit));
    }

    public function getCachedPositive(User $user): int
    {
        return (int) $user->getDataValue(self::DATA_POSITIVE, 0);
    }

    public function getCachedNegative(User $user): int
    {
        return (int) $user->getDataValue(self::DATA_NEGATIVE, 0);
    }

    public function getCachedNet(User $user): int
    {
        return $this->getCachedPositive($user) - $this->getCachedNegative($user);
    }

    /**
     * Recalculate from the ledger when the cache is out of sync.
     */
    public function rebuildCachedCounters(User $user): void
    {
        $summary = $this->reputationRepository->summarizeReceived($user);
        $user->setDataValue(self::DATA_POSITIVE, $summary['positive']);
        $user->setDataValue(self::DATA_NEGATIVE, $summary['negative']);
        $this->entityManager->flush();
    }

    private function bumpCachedCounter(User $user, int $value): void
    {
        if ($value === ForumUserReputation::VALUE_POSITIVE) {
            $user->setDataValue(self::DATA_POSITIVE, $this->getCachedPositive($user) + 1);
        } else {
            $user->setDataValue(self::DATA_NEGATIVE, $this->getCachedNegative($user) + 1);
        }
    }

    /**
     * Keeps only a plausible http(s) link, capped to the column width.
     *
     * A scheme check and nothing more: this string is rendered as an href, and
     * `javascript:` in an href is a stored XSS delivered by whoever left the
     * reputation. Everything else about the URL is the giver's business.
     */
    private function normaliseUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($url, 0, 500);
    }

    private function topicBelongsToUser(ForumTopic $topic, User $user): bool
    {
        if ($topic->getFirstPoster()?->getId() === $user->getId()) {
            return true;
        }

        return $this->postRepository->count([
            'topic' => $topic,
            'author' => $user,
        ]) > 0;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumUserReputation;
use App\Entity\User;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Repository\ForumUserReputationRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

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
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->settingsRegistry->get('forum.reputation_enabled') ?? true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function give(
        User $from,
        User $to,
        int $value,
        string $reason,
        ?ForumTopic $topic = null,
        ?ForumPost $post = null,
        ?string $comment = null,
    ): ForumUserReputation {
        if (!$this->isEnabled()) {
            throw new InvalidArgumentException('reputation_disabled');
        }

        if ($from->getId() === $to->getId()) {
            throw new InvalidArgumentException('reputation_self');
        }

        if (!\in_array($reason, ForumUserReputation::REASONS, true)) {
            throw new InvalidArgumentException('reputation_reason');
        }

        if ($reason === ForumUserReputation::REASON_OTHER && ($comment === null || trim($comment) === '')) {
            throw new InvalidArgumentException('reputation_comment_required');
        }

        $value = $value === ForumUserReputation::VALUE_NEGATIVE
            ? ForumUserReputation::VALUE_NEGATIVE
            : ForumUserReputation::VALUE_POSITIVE;

        if ($post !== null) {
            if ($post->getAuthor()?->getId() !== $to->getId()) {
                throw new InvalidArgumentException('reputation_post_mismatch');
            }
            $topic ??= $post->getTopic();
        }

        if ($topic === null) {
            throw new InvalidArgumentException('reputation_topic_required');
        }

        // Topic must belong to the target user (author or poster).
        if (!$this->topicBelongsToUser($topic, $to)) {
            throw new InvalidArgumentException('reputation_topic_mismatch');
        }

        $reputation = new ForumUserReputation($from, $to, $value, $reason, $topic, $post, $comment);
        $this->entityManager->persist($reputation);

        $this->bumpCachedCounter($to, $value);
        $this->entityManager->flush();

        $this->domainDispatcher->dispatchReputationGiven($reputation);

        return $reputation;
    }

    /**
     * Public topics the user opened or replied in.
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

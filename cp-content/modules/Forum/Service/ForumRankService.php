<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Modules\Forum\Entity\ForumUserRank;
use App\Entity\User;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumUserRankRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves a member's forum rank: manual User::$data['forum_rank_id'] first, else highest auto rank by post count.
 */
final class ForumRankService
{
    public function __construct(
        private readonly ForumUserRankRepository $rankRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveRank(User $user): ?ForumUserRank
    {
        $manualRank = $this->manualRankFor($user);
        if ($manualRank !== null) {
            return $manualRank;
        }

        return $this->rankRepository->findHighestAutomaticForPostCount(
            $this->postRepository->countPublicByAuthor($user),
        );
    }

    /**
     * Rank for postbit/thread render using preloaded ranks and post counts — no per-author query (Law 6.1).
     *
     * @param ForumUserRank[] $preloadedRanks Full list ordered by sortOrder/minPosts
     */
    public function resolveRankFromPreloaded(User $user, int $postCount, array $preloadedRanks): ?ForumUserRank
    {
        $manualRank = $this->manualRankFor($user, $preloadedRanks);
        if ($manualRank !== null) {
            return $manualRank;
        }

        $best = null;
        foreach ($preloadedRanks as $rank) {
            if ($rank->getMinPosts() === null || $rank->getMinPosts() > $postCount) {
                continue;
            }
            if ($best === null || $rank->getMinPosts() > $best->getMinPosts()) {
                $best = $rank;
            }
        }

        return $best;
    }

    public function assignManualRank(User $user, ?ForumUserRank $rank): void
    {
        $user->setDataValue('forum_rank_id', $rank?->getId());
        $this->entityManager->flush();
    }

    /** @param ForumUserRank[]|null $preloadedRanks */
    private function manualRankFor(User $user, ?array $preloadedRanks = null): ?ForumUserRank
    {
        $manualRankId = $user->getDataValue('forum_rank_id');
        if (!\is_int($manualRankId) && !(\is_string($manualRankId) && ctype_digit($manualRankId))) {
            return null;
        }

        $manualRankId = (int) $manualRankId;

        if ($preloadedRanks !== null) {
            foreach ($preloadedRanks as $rank) {
                if ($rank->getId() === $manualRankId) {
                    return $rank;
                }
            }

            return null;
        }

        $rank = $this->rankRepository->find($manualRankId);

        return $rank instanceof ForumUserRank ? $rank : null;
    }
}

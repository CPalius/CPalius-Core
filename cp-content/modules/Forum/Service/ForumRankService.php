<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\ForumUserRank;
use App\Entity\User;
use App\Repository\ForumPostRepository;
use App\Repository\ForumUserRankRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bir kullanıcının forum rütbesini çözer: önce User::$data['forum_rank_id']
 * içindeki elle atanmış rütbeye bakılır (ör. "Moderatör"), yoksa mesaj
 * sayısına göre en yüksek otomatik rütbe seçilir (bkz. ForumUserRank
 * docblock'u).
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
     * Toplu (thread/postbit) render için — tek sorgu ile önceden çekilmiş
     * $preloadedRanks (ForumUserRankRepository::findAllOrdered()) ve $postCount
     * üzerinden, DB'ye gitmeden PHP'de rütbeyi hesaplar. Law 6.1 (N+1 muhafızı)
     * gereği: bir thread sayfasındaki her yazar için ayrı rütbe sorgusu
     * atmamak için ForumFrontController bu metodu kullanır (bkz.
     * buildPostbitStats()).
     *
     * @param ForumUserRank[] $preloadedRanks sortOrder/minPosts'a göre sıralı tam liste
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

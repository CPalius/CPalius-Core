<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Modules\Forum\Entity\ForumPostVote;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumPostVoteRepository;

/**
 * Thread readers and reactors for the topic-detail footer strips.
 */
final class ForumTopicEngagementService
{
    public const LIST_LIMIT = 12;

    public function __construct(
        private readonly ForumPresenceService $presenceService,
        private readonly ForumPostVoteRepository $voteRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Members and guests currently viewing this thread.
     *
     * @return array{
     *     members: list<array{id: int, name: string, slug: string, user: User}>,
     *     memberCount: int,
     *     guestCount: int
     * }
     */
    public function readers(ForumTopic $topic, int $limit = self::LIST_LIMIT): array
    {
        return $this->presenceService->currentOnTopic($topic, $limit);
    }

    /**
     * Unique members who liked or disliked any post in the thread.
     *
     * @return array{members: list<array{user: User, name: string, slug: string, liked: bool, disliked: bool}>, total: int}
     */
    public function reactors(ForumTopic $topic, int $limit = self::LIST_LIMIT): array
    {
        $likedAt = $this->voteRepository->findUserLastActivityByTopic($topic, ForumPostVote::LIKE);
        $dislikedAt = $this->voteRepository->findUserLastActivityByTopic($topic, ForumPostVote::DISLIKE);
        $ids = array_values(array_unique([...array_keys($likedAt), ...array_keys($dislikedAt)]));
        if ($ids === []) {
            return ['members' => [], 'total' => 0];
        }

        $users = $this->userRepository->findBy(['id' => $ids]);
        /** @var array<int, User> $byId */
        $byId = [];
        foreach ($users as $user) {
            $id = $user->getId();
            if ($id !== null) {
                $byId[$id] = $user;
            }
        }

        $ranked = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $likeTs = $likedAt[$id] ?? 0;
            $dislikeTs = $dislikedAt[$id] ?? 0;
            $ranked[] = [
                'id' => $id,
                'sort' => max($likeTs, $dislikeTs),
                'liked' => isset($likedAt[$id]),
                'disliked' => isset($dislikedAt[$id]),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        $members = [];
        foreach (\array_slice($ranked, 0, max(1, $limit)) as $row) {
            $user = $byId[$row['id']];
            $members[] = [
                'user' => $user,
                'name' => $this->memberLabel($user),
                'slug' => $user->getProfileSlug(),
                'liked' => $row['liked'],
                'disliked' => $row['disliked'],
            ];
        }

        return [
            'members' => $members,
            'total' => \count($ranked),
        ];
    }

    private function memberLabel(User $user): string
    {
        $label = $user->getPublicDisplayName();

        return $label !== '' ? $label : ('#'.(string) $user->getId());
    }
}

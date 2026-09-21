<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostVote;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumPostVoteRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Decides whether a stored spoiler is shown, then rewrites the HTML.
 *
 * Unlock: staff always; search spiders (indexing); the post author; a like on
 * that post; or a reply in the topic. Visitors never unlock.
 */
final class ForumSpoilerGate
{
    /** @var array<int, true>|null postId => liked, for this page */
    private ?array $pageLikes = null;

    /** @var array<int, bool> topicId => viewer has a reply */
    private array $replyMemo = [];

    public function __construct(
        private readonly ForumGuestView $guestView,
        private readonly ForumSpoilerMarkup $markup,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumPostVoteRepository $postVoteRepository,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<int, true> $likedPostIds
     */
    public function rememberLikes(array $likedPostIds): void
    {
        $this->pageLikes = $likedPostIds;
    }

    public function rewrite(string $html, ForumPost $post): string
    {
        if (!str_contains($html, ForumSpoilerMarkup::CLASS_NAME)) {
            return $html;
        }

        $unlocked = $this->isUnlocked($post);
        $label = $this->translator->trans('forum.spoiler.label');
        $hint = $unlocked ? '' : $this->lockedHint();

        return $this->markup->rewrite($html, $unlocked, $label, $hint);
    }

    public function quotePlain(string $html): string
    {
        return $this->markup->stripForQuote($html);
    }

    public function isUnlocked(ForumPost $post): bool
    {
        $user = $this->security->getUser();
        $isAuthor = $user instanceof User
            && $post->getAuthor() !== null
            && $post->getAuthor()->getId() === $user->getId();

        return ForumGuestPolicy::spoilerUnlocked(
            $this->guestView->isStaff(),
            $this->guestView->visitorKind(),
            $isAuthor,
            $this->likedThisPost($post, $user instanceof User ? $user : null),
            $user instanceof User ? $this->repliedInTopic($post->getTopic(), $user) : false,
        );
    }

    private function likedThisPost(ForumPost $post, ?User $user): bool
    {
        $id = $post->getId();
        if ($id === null || !$user instanceof User) {
            return false;
        }

        if ($this->pageLikes !== null) {
            return isset($this->pageLikes[$id]);
        }

        $map = $this->postVoteRepository->findVotedPostIdsForUser([$id], $user, ForumPostVote::LIKE);

        return isset($map[$id]);
    }

    private function repliedInTopic(ForumTopic $topic, User $user): bool
    {
        $topicId = $topic->getId() ?? 0;
        if (!\array_key_exists($topicId, $this->replyMemo)) {
            $this->replyMemo[$topicId] = $this->postRepository->authorHasReplyInTopic($topic, $user);
        }

        return $this->replyMemo[$topicId];
    }

    private function lockedHint(): string
    {
        $kind = $this->guestView->visitorKind();
        $key = $kind === ForumVisitorKind::MEMBER
            ? 'forum.spoiler.locked_member'
            : 'forum.spoiler.locked_guest';

        return $this->translator->trans($key);
    }
}

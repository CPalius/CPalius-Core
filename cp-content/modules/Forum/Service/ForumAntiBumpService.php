<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumPostRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Folds a consecutive self-reply into the post above it instead of creating a
 * new one.
 *
 * The behaviour this exists to stop is the one that made r10 unreadable: post,
 * wait, reply to yourself, repeat — each reply costing nothing and buying a
 * trip back to the top of the board. Refusing the reply outright would also
 * lose whatever the person actually wanted to say, so the reply is kept and
 * appended to their own previous post, dated. What it does not do is move the
 * topic: last_post_date, the post count and the reply notification are all left
 * alone, so the thread does not rise and nobody's inbox is spent on it.
 *
 * Deliberately not applied to moderators (a staff follow-up is usually the
 * point) and never to a held post, which moderation has to see as its own item.
 */
final class ForumAntiBumpService
{
    public const SCOPE_TOPIC_AUTHOR = 'topic_author';
    public const SCOPE_EVERYONE = 'everyone';

    /** Capability that means "this account is staff here", so the fold is skipped. */
    private const MODERATOR_CAPABILITY = 'forum.topic.moderate';

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly ForumPostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('forum.antibump_enabled', true);
    }

    /**
     * The post a new reply should be folded into, or null when it should become
     * a post of its own.
     */
    public function mergeTarget(ForumTopic $topic, User $author, bool $wouldBeHeld): ?ForumPost
    {
        if (!$this->isEnabled() || $wouldBeHeld) {
            return null;
        }

        if ($this->security->isGranted(self::MODERATOR_CAPABILITY)) {
            return null;
        }

        if ($this->scope() === self::SCOPE_TOPIC_AUTHOR && $topic->getFirstPoster()?->getId() !== $author->getId()) {
            return null;
        }

        $last = $this->postRepository->findLastByTopic($topic);
        if ($last === null || !$last->isVisible()) {
            return null;
        }

        // Somebody else spoke last: this is a conversation, not a bump.
        if ($last->getAuthor()?->getId() !== $author->getId()) {
            return null;
        }

        $window = $this->windowSeconds();
        if ($window > 0 && time() - $last->getCreatedAt()->getTimestamp() > $window) {
            return null;
        }

        return $last;
    }

    /**
     * Appends the already-sanitized reply body to $target with a dated divider.
     *
     * The divider text is written in the language of the request that produced
     * it, because it becomes part of the stored post — the same trade-off the
     * quote and merge tools already make.
     */
    public function appendTo(ForumPost $target, string $sanitizedBody, ForumTopic $topic): ForumPost
    {
        $now = new \DateTimeImmutable();

        $note = $this->translator->trans('forum.antibump.appended_at', [
            'date' => $now->format('d.m.Y H:i'),
        ]);

        $target->setBody(
            $target->getBody()
            .'<div class="forum-post__bump"><span class="forum-post__bump-note">'
            .htmlspecialchars($note, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            .'</span></div>'
            .$sanitizedBody,
        );

        // The topic's preview is cut from its first post, so folding into that
        // post has to refresh it — otherwise the board list keeps quoting text
        // the post no longer starts with.
        if ($topic->getFirstPostId() === $target->getId()) {
            $topic->setPreview(mb_substr(strip_tags($target->getBody()), 0, 128));
        }

        // No recordEdit(): "edited by X" is a claim about somebody revising what
        // they wrote, and the dated divider already says what happened here.
        // No syncTopic() either — nothing it recalculates has changed, and its
        // touch() would nudge the topic up the very list this is protecting.
        $this->entityManager->flush();

        return $target;
    }

    public function scope(): string
    {
        $value = (string) $this->settings->get('forum.antibump_scope', self::SCOPE_TOPIC_AUTHOR);

        return $value === self::SCOPE_EVERYONE ? self::SCOPE_EVERYONE : self::SCOPE_TOPIC_AUTHOR;
    }

    /**
     * @return int seconds; 0 means "fold however old the previous post is"
     */
    private function windowSeconds(): int
    {
        return max(0, (int) $this->settings->get('forum.antibump_window_minutes', 0)) * 60;
    }
}

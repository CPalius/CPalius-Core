<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Content\RichTextSanitizer;
use App\Core\Settings\SettingsRegistry;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostDislike;
use Modules\Forum\Entity\ForumPostLike;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicPrefix;
use App\Entity\User;
use Modules\Forum\Repository\ForumPostDislikeRepository;
use Modules\Forum\Repository\ForumPostLikeRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\ForumDictionary;
use Modules\Forum\ForumDiscussionState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Thread create, reply, move, merge, and delete — Forum Engine service layer.
 */
final class ForumTopicService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumPostLikeRepository $postLikeRepository,
        private readonly ForumPostDislikeRepository $postDislikeRepository,
        private readonly ForumStatsService $statsService,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly ForumDomainDispatcher $domainDispatcher,
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    public function createTopic(
        ForumSection $section,
        User $author,
        string $title,
        string $description,
        string $body,
        bool $isPrivate,
        Request $request,
        ?ForumTopicPrefix $prefix = null,
    ): ForumTopic {
        $topic = new ForumTopic($section, $title, $author->getFullName());
        $topic->setSlug($this->generateTopicSlug($title));
        $topic->setDescription($description !== '' ? $description : null);
        $topic->setPrefix($prefix);
        $topic->setFirstPoster($author);
        $topic->setMode($isPrivate ? ForumTopic::MODE_PRIVATE : ForumTopic::MODE_NORMAL);
        $topic->setPostCount(1);
        $topic->setDiscussionState(ForumDiscussionState::Visible);

        $post = new ForumPost($topic, $section, $author->getFullName(), $this->sanitizeBody($body));
        $post->setAuthor($author);
        $post->setPosterIp($request->getClientIp());

        $topic->setLastPoster($author);
        $topic->setLastPosterName($author->getFullName());
        $topic->setPreview(mb_substr(strip_tags($post->getBody()), 0, 128));
        $topic->setLastPostDate($post->getCreatedAt());

        $this->entityManager->persist($topic);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->ensureUniqueSlug($topic);
        $topic->setFirstPostId($post->getId());
        $topic->setLastPostId($post->getId());
        $this->entityManager->flush();

        $this->statsService->syncSection($section);
        $this->domainDispatcher->dispatchPostCreated($post, $topic, $author, true);

        return $topic;
    }

    public function addReply(ForumTopic $topic, User $author, string $body, Request $request): ForumPost
    {
        $post = new ForumPost($topic, $topic->getSection(), $author->getFullName(), $this->sanitizeBody($body));
        $post->setAuthor($author);
        $post->setPosterIp($request->getClientIp());

        $topic->setLastPoster($author);
        $topic->setLastPosterName($author->getFullName());
        $topic->setLastPostDate($post->getCreatedAt());
        $topic->touch();

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $topic->setLastPostId($post->getId());
        $this->entityManager->flush();

        $this->statsService->syncTopic($topic);
        $this->domainDispatcher->dispatchPostCreated($post, $topic, $author, false);

        return $post;
    }

    public function deleteTopic(ForumTopic $topic, bool $hard = false): void
    {
        $section = $topic->getSection();

        if ($hard) {
            $this->entityManager->remove($topic);
            $this->entityManager->flush();
            $this->statsService->syncSection($section);

            return;
        }

        $topic->setDiscussionState(ForumDiscussionState::Deleted);
        $topic->touch();
        $this->entityManager->flush();
        $this->statsService->syncSection($section);
    }

    public function restoreTopic(ForumTopic $topic): void
    {
        $topic->setDiscussionState(ForumDiscussionState::Visible);
        $topic->touch();
        $this->entityManager->flush();
        $this->statsService->syncSection($topic->getSection());
    }

    /**
     * Move a topic to another forum. When $keepRedirect is true, leave a ghost redirect in the origin.
     */
    public function moveTopic(ForumTopic $topic, ForumSection $target, bool $keepRedirect): void
    {
        $origin = $topic->getSection();

        if ($keepRedirect) {
            $ghost = new ForumTopic($origin, $topic->getTitle(), $topic->getFirstPosterName());
            $ghost->setSlug($topic->getSlug());
            $ghost->setMovedToTopic($topic);
            $ghost->setLocked(true);
            $this->entityManager->persist($ghost);
        }

        $topic->setSection($target);
        $topic->touch();

        foreach ($this->postRepository->findByTopic($topic) as $post) {
            $post->setSection($target);
        }

        $this->entityManager->flush();

        $this->statsService->syncSection($origin);
        $this->statsService->syncSection($target);
    }

    /**
     * Merge source into target: move posts, soft-delete source, and redirect to the target.
     */
    public function mergeTopics(ForumTopic $source, ForumTopic $target): void
    {
        if ($source->getId() === $target->getId()) {
            return;
        }

        $originSection = $source->getSection();
        $targetSection = $target->getSection();

        foreach ($this->postRepository->findByTopic($source) as $post) {
            $post->setTopic($target);
            $post->setSection($targetSection);
        }

        $source->setMovedToTopic($target);
        $source->setDiscussionState(ForumDiscussionState::Deleted);
        $source->setLocked(true);
        $source->touch();
        $target->touch();

        $this->entityManager->flush();
        $this->statsService->syncTopic($target);
        $this->statsService->syncSection($originSection);
        if ($originSection->getId() !== $targetSection->getId()) {
            $this->statsService->syncSection($targetSection);
        }
    }

    public function canEditPost(ForumPost $post, ?User $user, bool $isModerator): bool
    {
        if ($user === null) {
            return false;
        }

        if ($isModerator) {
            return true;
        }

        if ($post->getAuthor()?->getId() !== $user->getId()) {
            return false;
        }

        $minutes = (int) $this->settingsRegistry->get(
            'forum.edit_time_limit',
            $this->settingsRegistry->get('forum.edit_timeout_minutes', ForumDictionary::DEFAULT_EDIT_TIMEOUT_MINUTES),
        );
        $timeout = max(0, $minutes) * 60;
        $elapsed = time() - $post->getCreatedAt()->getTimestamp();

        return $elapsed <= $timeout;
    }

    public function updatePost(ForumPost $post, User $editor, string $body, ?string $topicTitle = null): void
    {
        $post->setBody($this->sanitizeBody($body));
        $post->recordEdit($editor->getFullName());

        if ($topicTitle !== null) {
            $post->getTopic()->setTitle($topicTitle);
            $post->getTopic()->setSlug($this->generateTopicSlug($topicTitle));
            $this->ensureUniqueSlug($post->getTopic());
        }

        $this->entityManager->flush();
        $this->statsService->syncTopic($post->getTopic());
    }

    /**
     * Toggle like; returns true when the post is now liked.
     * Own posts cannot be liked or unliked.
     */
    public function toggleLike(ForumPost $post, User $user): bool
    {
        $this->assertNotOwnPost($post, $user);

        $existing = $this->postLikeRepository->findOneByPostAndUser($post, $user);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();

            return false;
        }

        $dislike = $this->postDislikeRepository->findOneByPostAndUser($post, $user);
        if ($dislike !== null) {
            $this->entityManager->remove($dislike);
        }

        $this->entityManager->persist(new ForumPostLike($post, $user));
        $this->entityManager->flush();

        $this->dispatchReactionEvent(function () use ($post, $user): void {
            $postAuthor = $post->getAuthor();
            if ($postAuthor !== null) {
                $this->domainDispatcher->dispatchPostLiked($post, $user, $postAuthor);
            }
        });

        return true;
    }

    /**
     * Toggle dislike; returns true when the post is now disliked.
     * Own posts cannot be disliked or undisliked.
     */
    public function toggleDislike(ForumPost $post, User $user): bool
    {
        $this->assertNotOwnPost($post, $user);

        $existing = $this->postDislikeRepository->findOneByPostAndUser($post, $user);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();

            return false;
        }

        $like = $this->postLikeRepository->findOneByPostAndUser($post, $user);
        if ($like !== null) {
            $this->entityManager->remove($like);
        }

        $this->entityManager->persist(new ForumPostDislike($post, $user));
        $this->entityManager->flush();

        $this->dispatchReactionEvent(function () use ($post, $user): void {
            $postAuthor = $post->getAuthor();
            if ($postAuthor !== null) {
                $this->domainDispatcher->dispatchPostDisliked($post, $user, $postAuthor);
            }
        });

        return true;
    }

    public function deletePost(ForumPost $post): void
    {
        $topic = $post->getTopic();
        $postCount = $this->postRepository->countByTopic($topic);

        if ($postCount <= 1) {
            $this->deleteTopic($topic);

            return;
        }

        $this->entityManager->remove($post);
        $this->entityManager->flush();
        $this->statsService->syncTopic($topic);
    }

    private function sanitizeBody(string $body): string
    {
        return $this->richTextSanitizer->sanitize(nl2br(trim($body), false));
    }

    /**
     * Do not turn a notification/hook failure into HTTP 500 after the reaction row is flushed.
     */
    private function dispatchReactionEvent(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable) {
        }
    }

    private function assertNotOwnPost(ForumPost $post, User $user): void
    {
        $author = $post->getAuthor();
        if ($author !== null && $author->getId() === $user->getId()) {
            throw new \DomainException('Users cannot like or dislike their own posts.');
        }
    }

    private function generateTopicSlug(string $title): string
    {
        $slugger = new AsciiSlugger();
        $slug = mb_strtolower($slugger->slug($title)->toString());

        return $slug !== '' ? mb_substr($slug, 0, 180) : 'konu';
    }

    private function ensureUniqueSlug(ForumTopic $topic): void
    {
        $slug = $topic->getSlug();
        if ($slug === null || $slug === '') {
            return;
        }

        $duplicate = $this->entityManager->getRepository(ForumTopic::class)->createQueryBuilder('t')
            ->andWhere('t.slug = :slug')
            ->andWhere('t.id != :id')
            ->setParameter('slug', $slug)
            ->setParameter('id', $topic->getId() ?? 0)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($duplicate instanceof ForumTopic && $topic->getId() !== null) {
            $topic->setSlug($slug . '-' . $topic->getId());
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Content\RichTextSanitizer;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostVote;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicPrefix;
use Modules\Forum\ForumDictionary;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumPostVoteRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Thread create, reply, move, merge, and delete — Forum Engine service layer.
 */
final class ForumTopicService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumPostVoteRepository $postVoteRepository,
        private readonly ForumCounterService $counterService,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly ForumDomainDispatcher $domainDispatcher,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly OriginCachePurger $originCachePurger,
        private readonly ForumCensorService $censorService,
        private readonly ForumPostHoldService $holdService,
        private readonly ForumAntiBumpService $antiBumpService,
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
        ?string $contentLocale = null,
        bool $autoTranslate = false,
        ?Uuid $translationGroupId = null,
    ): ForumTopic {
        $locale = $contentLocale !== null && $contentLocale !== '' ? $contentLocale : $section->getLocale();
        $home = $this->sectionRepository->findLocaleSibling($section, $locale) ?? $section;

        $topic = new ForumTopic($home, $title, $this->posterLabel($author));
        $topic->setLocale($locale);
        if ($translationGroupId instanceof Uuid) {
            $topic->joinTranslationGroup($translationGroupId);
        } else {
            $topic->assignToNewTranslationGroup();
        }
        $topic->setSlug($this->generateTopicSlug($title));
        $topic->setDescription($description !== '' ? $description : null);
        $topic->setPrefix($prefix);
        $topic->setFirstPoster($author);
        $topic->setMode($isPrivate ? ForumTopic::MODE_PRIVATE : ForumTopic::MODE_NORMAL);
        $topic->setPostCount(0);
        $topic->setDiscussionState(ForumDiscussionState::Visible);

        $post = new ForumPost($topic, $home, $this->posterLabel($author), $this->sanitizeBody($body));
        $post->setAuthor($author);
        $post->setPosterIp($request->getClientIp());
        if ($this->holdService->shouldHold($author)) {
            $topic->setDiscussionState(ForumDiscussionState::Moderated);
            $post->setDiscussionState(ForumDiscussionState::Moderated);
        }

        $topic->setLastPoster($author);
        $topic->setLastPosterName($this->posterLabel($author));
        $topic->setPreview(mb_substr(strip_tags($post->getBody()), 0, 128));
        $topic->setLastPostDate($post->getCreatedAt());

        $this->entityManager->persist($topic);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->ensureUniqueSlug($topic);
        $topic->setFirstPostId($post->getId());
        $topic->setLastPostId($post->getId());
        $this->entityManager->flush();

        $this->counterService->incrementTopic($topic);
        $this->counterService->incrementPost($post);
        $this->entityManager->flush();
        if (!$topic->isModerated()) {
            $this->domainDispatcher->dispatchPostCreated($post, $topic, $author, true);
            $this->invalidatePublicCache();
        }

        $this->domainDispatcher->dispatchTopicCreated($topic, $post, $author, $request, $autoTranslate);

        return $topic;
    }

    public function addReply(ForumTopic $topic, User $author, string $body, Request $request): ForumReplyResult
    {
        $sanitized = $this->sanitizeBody($body);
        $held = $this->holdService->shouldHold($author);

        // Anti-bump runs before anything is persisted: a folded reply must never
        // exist as a row, or the post count and the "last post" pointer would
        // move for the instant it took to merge it away again.
        $mergeTarget = $this->antiBumpService->mergeTarget($topic, $author, $held);
        if ($mergeTarget !== null) {
            return ForumReplyResult::mergedInto(
                $this->antiBumpService->appendTo($mergeTarget, $sanitized, $topic),
            );
        }

        $post = new ForumPost($topic, $topic->getSection(), $this->posterLabel($author), $sanitized);
        $post->setAuthor($author);
        $post->setPosterIp($request->getClientIp());
        if ($held) {
            $post->setDiscussionState(ForumDiscussionState::Moderated);
        }

        if (!$held) {
            $topic->setLastPoster($author);
            $topic->setLastPosterName($this->posterLabel($author));
            $topic->setLastPostDate($post->getCreatedAt());
            $topic->touch();
        }

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        if (!$held) {
            $topic->setLastPostId($post->getId());
            $this->entityManager->flush();
        }

        $this->counterService->incrementPost($post);
        $this->entityManager->flush();
        if (!$held) {
            $this->domainDispatcher->dispatchPostCreated($post, $topic, $author, false);
            $this->invalidatePublicCache();
        }

        return ForumReplyResult::created($post);
    }

    public function publishHeldPost(ForumPost $post): void
    {
        $topic = $post->getTopic();
        $wasHeldTopic = $topic->isModerated();
        $this->counterService->approvePost($post);
        $this->entityManager->flush();

        $author = $post->getAuthor();
        if ($author !== null) {
            $this->domainDispatcher->dispatchPostCreated($post, $topic, $author, $wasHeldTopic);
            $this->invalidatePublicCache();
        }
    }

    public function deleteTopic(ForumTopic $topic, bool $hard = false): void
    {
        if ($hard) {
            $this->counterService->removeTopic($topic);
            $this->entityManager->remove($topic);
            $this->entityManager->flush();
            $this->invalidatePublicCache();

            return;
        }

        $this->counterService->softDeleteTopic($topic);
        $this->entityManager->flush();
        $this->invalidatePublicCache();
    }

    public function restoreTopic(ForumTopic $topic): void
    {
        $topic->setDiscussionState(ForumDiscussionState::Visible);
        $topic->touch();
        $this->counterService->restoreTopic($topic);
        $this->entityManager->flush();
        $this->invalidatePublicCache();
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
            $ghost->setLocale($topic->getLocale());
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

        $this->counterService->moveTopic($topic, $origin, $target);
        $this->entityManager->flush();
        $this->invalidatePublicCache();
    }

    /**
     * Merge source into target: move posts, soft-delete source, and redirect to the target.
     */
    public function mergeTopics(ForumTopic $source, ForumTopic $target): void
    {
        if ($source->getId() === $target->getId()) {
            return;
        }

        $targetSection = $target->getSection();

        foreach ($this->postRepository->findByTopic($source) as $post) {
            $post->setTopic($target);
            $post->setSection($targetSection);
        }

        $source->setMovedToTopic($target);
        $source->setLocked(true);
        $source->touch();
        $target->touch();

        $this->entityManager->flush();
        $this->counterService->mergeTopicInto($source, $target);
        $this->entityManager->flush();
        $this->invalidatePublicCache();
    }

    /**
     * Keep the oldest post, append the others' HTML, then delete the extras.
     *
     * @param list<ForumPost> $posts
     */
    public function mergePosts(array $posts, User $editor): ForumPost
    {
        if (\count($posts) < 2) {
            throw new \InvalidArgumentException('forum.imod.merge_posts_min');
        }

        usort($posts, static function (ForumPost $a, ForumPost $b): int {
            $byDate = $a->getCreatedAt() <=> $b->getCreatedAt();

            return $byDate !== 0 ? $byDate : (($a->getId() ?? 0) <=> ($b->getId() ?? 0));
        });

        $keeper = $posts[0];
        $topicId = $keeper->getTopic()->getId();
        foreach ($posts as $post) {
            if ($post->getTopic()->getId() !== $topicId) {
                throw new \InvalidArgumentException('forum.imod.merge_posts_same_topic');
            }
        }

        $html = $keeper->getBody();
        $extras = \array_slice($posts, 1);
        foreach ($extras as $post) {
            $html .= "\n<hr>\n".$post->getBody();
        }

        $keeper->setBody($html);
        $keeper->recordEdit($this->posterLabel($editor));
        $this->entityManager->flush();

        foreach ($extras as $post) {
            $this->deletePost($post);
        }

        return $keeper;
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

        $timeout = $this->editWindowMinutes($post) * 60;
        $elapsed = time() - $post->getCreatedAt()->getTimestamp();

        return $elapsed <= $timeout;
    }

    /**
     * How long the author may keep editing this post.
     *
     * The opening post gets its own, longer allowance: it carries the title and
     * the question everybody else is answering, so a typo in it costs the whole
     * thread, while a typo in reply #40 costs one line. A reply is also the half
     * an author is tempted to rewrite after being argued with, which is exactly
     * what a short window is for.
     */
    public function editWindowMinutes(ForumPost $post): int
    {
        $replyDefault = (int) $this->settingsRegistry->get(
            'forum.edit_time_limit',
            $this->settingsRegistry->get('forum.edit_timeout_minutes', ForumDictionary::DEFAULT_EDIT_TIMEOUT_MINUTES),
        );

        if ($post->getTopic()->getFirstPostId() !== $post->getId()) {
            return max(0, $replyDefault);
        }

        return max(0, (int) $this->settingsRegistry->get(
            'forum.edit_time_limit_topic',
            ForumDictionary::DEFAULT_TOPIC_EDIT_TIMEOUT_MINUTES,
        ));
    }

    public function updatePost(ForumPost $post, User $editor, string $body, ?string $topicTitle = null): void
    {
        $post->setBody($this->sanitizeBody($body));
        $post->recordEdit($this->posterLabel($editor));

        if ($topicTitle !== null) {
            $post->getTopic()->setTitle($topicTitle);
            $post->getTopic()->setSlug($this->generateTopicSlug($topicTitle));
            $this->ensureUniqueSlug($post->getTopic());
        }

        $this->entityManager->flush();
        $this->invalidatePublicCache();
    }

    public function ensureTranslationGroup(ForumTopic $topic): Uuid
    {
        $groupId = $topic->ensureTranslationGroup();
        $this->entityManager->flush();

        return $groupId;
    }

    public function requestTranslation(ForumTopic $topic, ForumPost $opening, User $author, Request $request): void
    {
        $this->domainDispatcher->dispatchTopicTranslate($topic, $opening, $author, $request);
    }

    /**
     * Toggle like; returns true when the post is now liked.
     * Own posts cannot be liked or unliked.
     */
    public function toggleLike(ForumPost $post, User $user): bool
    {
        return $this->toggleVote($post, $user, ForumPostVote::LIKE);
    }

    /**
     * Toggle dislike; returns true when the post is now disliked.
     * Own posts cannot be disliked or undisliked.
     */
    public function toggleDislike(ForumPost $post, User $user): bool
    {
        return $this->toggleVote($post, $user, ForumPostVote::DISLIKE);
    }

    /**
     * The one path both verdicts take, now that they share a table.
     *
     * Switching sides used to be a delete plus an insert across two tables with
     * nothing holding them together; it is an UPDATE of one row here, so a
     * member cannot end up holding a like and a dislike on the same post even if
     * two requests race — UNIQUE(post_id, user_id) has the final say.
     */
    private function toggleVote(ForumPost $post, User $user, int $vote): bool
    {
        $this->assertNotOwnPost($post, $user);

        $existing = $this->postVoteRepository->findOneByPostAndUser($post, $user);

        if ($existing !== null && $existing->getVote() === $vote) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();

            return false;
        }

        if ($existing !== null) {
            $existing->setVote($vote);
        } else {
            $this->entityManager->persist(new ForumPostVote($post, $user, $vote));
        }

        $this->entityManager->flush();

        $this->dispatchReactionEvent(function () use ($post, $user, $vote): void {
            $postAuthor = $post->getAuthor();
            if ($postAuthor === null) {
                return;
            }

            if ($vote === ForumPostVote::LIKE) {
                $this->domainDispatcher->dispatchPostLiked($post, $user, $postAuthor);
            } else {
                $this->domainDispatcher->dispatchPostDisliked($post, $user, $postAuthor);
            }
        });

        return true;
    }

    public function deletePost(ForumPost $post): void
    {
        if ($post->getDiscussionState() === ForumDiscussionState::Deleted) {
            return;
        }

        $topic = $post->getTopic();
        $this->counterService->softDeletePost($post);
        $this->entityManager->flush();

        if ($topic->getPostCount() === 0 && $topic->getPostCountHeld() === 0 && !$topic->isDeleted()) {
            $this->deleteTopic($topic);

            return;
        }

        $this->invalidatePublicCache();
    }

    private function sanitizeBody(string $body): string
    {
        return $this->censorService->apply($this->richTextSanitizer->sanitize(nl2br(trim($body), false)));
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
            $topic->setSlug($slug.'-'.$topic->getId());
        }
    }

    private function invalidatePublicCache(): void
    {
        $this->originCachePurger->purgeAreas('forums', 'home', 'roadmap');
    }

    private function posterLabel(User $user): string
    {
        $label = $user->getPublicDisplayName();

        return $label !== '' ? $label : ('#'.(string) $user->getId());
    }
}

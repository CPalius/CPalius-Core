<?php

declare(strict_types=1);

namespace Modules\Blog\Service;

use App\Core\OriginCache\OriginCachePurger;
use App\Core\Pagination\PaginatedResult;
use App\Core\Pagination\Paginator;
use App\Core\Security\CaptchaService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Node;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\Entity\BlogComment;
use Modules\Blog\Repository\BlogCommentRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Blog comment policy, submission, threading, and Studio moderation.
 */
final class BlogCommentService
{
    public const CSRF_FRONT = 'blog_comment';
    public const CSRF_ADMIN = 'admin_blog_comment';

    private const HONEYPOT_FIELD = 'website';
    private const RATE_SESSION_KEY = 'blog_comment_last_at';
    private const MATH_SESSION_KEY = 'blog_comment_math';
    private const RATE_SECONDS = 20;

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly BlogCommentRepository $commentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Paginator $paginator,
        private readonly OriginCachePurger $originCachePurger,
        private readonly CaptchaService $captchaService,
    ) {
    }

    public function globallyEnabled(): bool
    {
        return (bool) $this->settings->get('blog.comments_enabled', true);
    }

    public function enabledForPost(Node $post): bool
    {
        if (!$this->globallyEnabled()) {
            return false;
        }

        $override = $post->getDataValue('comments_enabled');
        if ($override === null || $override === '') {
            return true;
        }

        return $this->isEnabledFlag($override);
    }

    public function guestsAllowed(): bool
    {
        return (bool) $this->settings->get('blog.comments_allow_guests', true);
    }

    public function repliesAllowed(): bool
    {
        return (bool) $this->settings->get('blog.comments_allow_replies', true);
    }

    public function authorEditAllowed(): bool
    {
        return (bool) $this->settings->get('blog.comments_allow_edit', false);
    }

    public function maxLength(): int
    {
        $n = (int) $this->settings->get('blog.comments_max_length', 4000);

        return $n > 0 ? $n : 4000;
    }

    public function perPage(): int
    {
        $n = (int) $this->settings->get('blog.comments_per_page', 20);

        return $n > 0 ? $n : 20;
    }

    public function closedNotice(): string
    {
        return trim((string) $this->settings->get('blog.comments_closed_notice', ''));
    }

    public function canSubmit(?User $user): bool
    {
        if (!$this->globallyEnabled()) {
            return false;
        }

        if ($user instanceof User) {
            return true;
        }

        return $this->guestsAllowed();
    }

    public function requiresApproval(?User $user): bool
    {
        if ($user instanceof User) {
            return (bool) $this->settings->get('blog.comments_member_require_approval', false);
        }

        return (bool) $this->settings->get('blog.comments_guest_require_approval', true);
    }

    public function requiresCaptcha(?User $user): bool
    {
        if (!(bool) $this->settings->get('blog.comments_require_captcha', true)) {
            return false;
        }

        if ($user instanceof User) {
            return (bool) $this->settings->get('blog.comments_captcha_members', false);
        }

        return true;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     canSubmit: bool,
     *     guestsAllowed: bool,
     *     repliesAllowed: bool,
     *     authorEditAllowed: bool,
     *     requiresApproval: bool,
     *     captchaRequired: bool,
     *     captcha: array{mode: string, a?: int, b?: int, provider?: string, site_key?: string, recaptcha_version?: string},
     *     closedNotice: string,
     *     maxLength: int,
     *     approvedCount: int,
     *     threads: list<array{comment: BlogComment, replies: list<BlogComment>}>,
     *     pagination: PaginatedResult<BlogComment>|null
     * }
     */
    public function buildShowContext(Node $post, Request $request, ?User $user): array
    {
        $enabled = $this->enabledForPost($post);
        $pagination = null;
        $threads = [];
        $approvedCount = 0;
        $canSubmit = $enabled && $this->canSubmit($user);
        $captchaRequired = $canSubmit && $this->requiresCaptcha($user);

        if ($enabled) {
            $approvedCount = $this->commentRepository->countApprovedForNode($post);
            $pagination = $this->paginator->paginate(
                $this->commentRepository->createApprovedTopLevelQueryBuilder($post),
                $request->query->getInt('cpage', 1),
                $this->perPage(),
            );
            $threads = $this->threadReplies($pagination->getItems());
        }

        return [
            'enabled' => $enabled,
            'canSubmit' => $canSubmit,
            'guestsAllowed' => $this->guestsAllowed(),
            'repliesAllowed' => $this->repliesAllowed(),
            'authorEditAllowed' => $this->authorEditAllowed(),
            'requiresApproval' => $this->requiresApproval($user),
            'captchaRequired' => $captchaRequired,
            'captcha' => $captchaRequired ? $this->buildCaptchaState($request) : ['mode' => 'none'],
            'closedNotice' => $this->closedNotice(),
            'maxLength' => $this->maxLength(),
            'approvedCount' => $approvedCount,
            'threads' => $threads,
            'pagination' => $pagination,
        ];
    }

    /**
     * @param list<BlogComment> $parents
     *
     * @return list<array{comment: BlogComment, replies: list<BlogComment>}>
     */
    private function threadReplies(array $parents): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (BlogComment $comment): ?int => $comment->getId(),
            $parents,
        )));

        $repliesByParent = [];
        foreach ($this->commentRepository->findApprovedRepliesForParents($ids) as $reply) {
            $parentId = $reply->getParent()?->getId();
            if ($parentId === null) {
                continue;
            }
            $repliesByParent[$parentId][] = $reply;
        }

        $threads = [];
        foreach ($parents as $parent) {
            $threads[] = [
                'comment' => $parent,
                'replies' => $repliesByParent[$parent->getId()] ?? [],
            ];
        }

        return $threads;
    }

    /**
     * @return array{ok: bool, pending: bool, message: string}
     */
    public function submitFromRequest(Node $post, Request $request, ?User $user): array
    {
        if (!$this->enabledForPost($post) || !$this->canSubmit($user)) {
            return ['ok' => false, 'pending' => false, 'message' => 'blog.comments.error.closed'];
        }

        if (trim((string) $request->request->get(self::HONEYPOT_FIELD, '')) !== '') {
            return ['ok' => true, 'pending' => false, 'message' => 'blog.comments.flash.published'];
        }

        if ($this->requiresCaptcha($user) && !$this->verifyCaptcha($request)) {
            return ['ok' => false, 'pending' => false, 'message' => 'blog.comments.error.captcha'];
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session !== null) {
            $last = (int) $session->get(self::RATE_SESSION_KEY, 0);
            if ($last > 0 && (time() - $last) < self::RATE_SECONDS) {
                return ['ok' => false, 'pending' => false, 'message' => 'blog.comments.error.too_fast'];
            }
        }

        $body = trim((string) $request->request->get('body', ''));
        $max = $this->maxLength();
        if ($body === '') {
            return ['ok' => false, 'pending' => false, 'message' => 'blog.comments.error.body_required'];
        }
        if (mb_strlen($body) > $max) {
            return ['ok' => false, 'pending' => false, 'message' => 'blog.comments.error.body_too_long'];
        }

        $guestName = null;
        $guestEmail = null;
        if (!$user instanceof User) {
            $guestName = trim((string) $request->request->get('guest_name', ''));
            $guestEmail = trim((string) $request->request->get('guest_email', ''));
            if ($guestName === '' || mb_strlen($guestName) < 2) {
                return ['ok' => false, 'pending' => false, 'message' => 'blog.comments.error.name_required'];
            }
            if ($guestEmail === '' || filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'pending' => false, 'message' => 'blog.comments.error.email_invalid'];
            }
        }

        $parent = $this->resolveParent($post, $request->request->get('parent_id'));
        if ($request->request->get('parent_id') && $parent === null) {
            return ['ok' => false, 'pending' => false, 'message' => 'blog.comments.error.reply_invalid'];
        }

        $pending = $this->requiresApproval($user);
        $comment = new BlogComment($post, $body);
        $comment->setAuthor($user);
        $comment->setGuestName($guestName);
        $comment->setGuestEmail($guestEmail);
        $comment->setParent($parent);
        $comment->setPosterIp($request->getClientIp());
        $comment->setStatus($pending ? BlogComment::STATUS_PENDING : BlogComment::STATUS_APPROVED);

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        if ($session !== null) {
            $session->set(self::RATE_SESSION_KEY, time());
        }

        if (!$pending) {
            $this->originCachePurger->purgeAreas('blog');
        }

        return [
            'ok' => true,
            'pending' => $pending,
            'message' => $pending ? 'blog.comments.flash.pending' : 'blog.comments.flash.published',
        ];
    }

    public function updateOwnComment(BlogComment $comment, User $user, string $body): bool
    {
        if (!$this->authorEditAllowed() || $comment->getAuthor()?->getId() !== $user->getId()) {
            return false;
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > $this->maxLength()) {
            return false;
        }

        $comment->setBody($body);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog');

        return true;
    }

    public function setStatus(BlogComment $comment, string $status): void
    {
        if (!\in_array($status, BlogComment::statuses(), true)) {
            return;
        }

        $comment->setStatus($status);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog');
    }

    public function updateBody(BlogComment $comment, string $body): bool
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > $this->maxLength()) {
            return false;
        }

        $comment->setBody($body);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog');

        return true;
    }

    public function replyAsModerator(BlogComment $parent, User $moderator, string $body): ?BlogComment
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > $this->maxLength()) {
            return null;
        }

        $root = $parent;
        while ($root->getParent() instanceof BlogComment) {
            $root = $root->getParent();
        }

        $reply = new BlogComment($root->getNode(), $body);
        $reply->setAuthor($moderator);
        $reply->setParent($root);
        $reply->setStatus(BlogComment::STATUS_APPROVED);
        $this->entityManager->persist($reply);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog');

        return $reply;
    }

    public function delete(BlogComment $comment): void
    {
        $this->entityManager->remove($comment);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog');
    }

    public function countPending(): int
    {
        return $this->commentRepository->countByStatus(BlogComment::STATUS_PENDING);
    }

    private function resolveParent(Node $post, mixed $rawId): ?BlogComment
    {
        if (!$this->repliesAllowed()) {
            return null;
        }

        $id = (int) $rawId;
        if ($id < 1) {
            return null;
        }

        $parent = $this->commentRepository->find($id);
        if (
            !$parent instanceof BlogComment
            || $parent->getNode()->getId() !== $post->getId()
            || !$parent->isApproved()
            || $parent->getParent() !== null
        ) {
            return null;
        }

        return $parent;
    }

    private function isEnabledFlag(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value === 1;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return array{mode: string, a?: int, b?: int, provider?: string, site_key?: string, recaptcha_version?: string}
     */
    private function buildCaptchaState(Request $request): array
    {
        $widget = $this->captchaService->getWidgetConfig();
        if ($widget['provider'] !== 'none' && $widget['site_key'] !== '') {
            return [
                'mode' => 'provider',
                'provider' => $widget['provider'],
                'site_key' => $widget['site_key'],
                'recaptcha_version' => $widget['recaptcha_version'],
            ];
        }

        $a = random_int(2, 9);
        $b = random_int(2, 9);
        if ($request->hasSession()) {
            $request->getSession()->set(self::MATH_SESSION_KEY, password_hash((string) ($a + $b), PASSWORD_DEFAULT));
        }

        return [
            'mode' => 'math',
            'a' => $a,
            'b' => $b,
        ];
    }

    private function verifyCaptcha(Request $request): bool
    {
        $widget = $this->captchaService->getWidgetConfig();
        if ($widget['provider'] !== 'none' && $widget['site_key'] !== '') {
            return $this->captchaService->verifyRequest($request);
        }

        if (!$request->hasSession()) {
            return false;
        }

        $hash = (string) $request->getSession()->get(self::MATH_SESSION_KEY, '');
        $answer = trim((string) $request->request->get('captcha_answer', ''));
        $request->getSession()->remove(self::MATH_SESSION_KEY);

        return $hash !== '' && $answer !== '' && password_verify($answer, $hash);
    }
}

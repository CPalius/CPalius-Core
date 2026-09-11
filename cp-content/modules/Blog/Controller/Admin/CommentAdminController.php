<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Pagination\Paginator;
use Modules\Blog\Entity\BlogComment;
use Modules\Blog\Repository\BlogCommentRepository;
use Modules\Blog\Service\BlogCommentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio comment queue: approve, spam, edit, reply, delete.
 */
#[Route('/admin/blog/comments', name: 'admin_blog_comments_')]
#[IsGranted('blog.comment.moderate')]
final class CommentAdminController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly BlogCommentRepository $commentRepository,
        private readonly BlogCommentService $commentService,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.blog.comments.menu', icon: 'heroicons:chat-bubble-left-ellipsis', panel: 'studio', priority: 21, capability: 'blog.comment.moderate', parent: 'admin_posts_index')]
    public function index(Request $request): Response
    {
        $status = trim((string) $request->query->get('status', 'pending'));
        if ($status === '') {
            $status = 'pending';
        }

        $result = $this->paginator->paginate(
            $this->commentRepository->createAdminQueryBuilder($status),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('@BlogModule/admin/comments/index.html.twig', [
            'comments' => $result,
            'currentStatus' => $status,
            'counts' => [
                'pending' => $this->commentRepository->countByStatus(BlogComment::STATUS_PENDING),
                'approved' => $this->commentRepository->countByStatus(BlogComment::STATUS_APPROVED),
                'spam' => $this->commentRepository->countByStatus(BlogComment::STATUS_SPAM),
                'rejected' => $this->commentRepository->countByStatus(BlogComment::STATUS_REJECTED),
            ],
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Request $request, int $id): Response
    {
        return $this->transition($request, $id, BlogComment::STATUS_APPROVED, 'studio.blog.comments.flash.approved');
    }

    #[Route('/{id}/spam', name: 'spam', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function spam(Request $request, int $id): Response
    {
        return $this->transition($request, $id, BlogComment::STATUS_SPAM, 'studio.blog.comments.flash.spam');
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(Request $request, int $id): Response
    {
        return $this->transition($request, $id, BlogComment::STATUS_REJECTED, 'studio.blog.comments.flash.rejected');
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $comment = $this->findComment($id);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            $ok = $this->commentService->updateBody($comment, (string) $request->request->get('body', ''));
            $this->addFlash(
                $ok ? 'success' : 'error',
                $this->translator->trans($ok ? 'studio.blog.comments.flash.updated' : 'studio.blog.comments.error.body'),
            );

            if ($ok) {
                return $this->redirectToRoute('admin_blog_comments_index', [
                    'status' => $comment->getStatus(),
                ]);
            }
        }

        return $this->render('@BlogModule/admin/comments/edit.html.twig', [
            'comment' => $comment,
        ]);
    }

    #[Route('/{id}/reply', name: 'reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(Request $request, int $id): Response
    {
        $this->assertCsrf($request);
        $comment = $this->findComment($id);
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $reply = $this->commentService->replyAsModerator($comment, $user, (string) $request->request->get('body', ''));
        $this->addFlash(
            $reply instanceof BlogComment ? 'success' : 'error',
            $this->translator->trans($reply instanceof BlogComment ? 'studio.blog.comments.flash.replied' : 'studio.blog.comments.error.body'),
        );

        return $this->redirectToRoute('admin_blog_comments_index', [
            'status' => $request->query->get('status', $comment->getStatus()),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $this->assertCsrf($request);
        $comment = $this->findComment($id);
        $status = $comment->getStatus();
        $this->commentService->delete($comment);
        $this->addFlash('success', $this->translator->trans('studio.blog.comments.flash.deleted'));

        return $this->redirectToRoute('admin_blog_comments_index', [
            'status' => $request->query->get('status', $status),
        ]);
    }

    private function transition(Request $request, int $id, string $status, string $flash): Response
    {
        $this->assertCsrf($request);
        $comment = $this->findComment($id);
        $this->commentService->setStatus($comment, $status);
        $this->addFlash('success', $this->translator->trans($flash));

        return $this->redirectToRoute('admin_blog_comments_index', [
            'status' => $request->query->get('status', $status),
        ]);
    }

    private function findComment(int $id): BlogComment
    {
        $comment = $this->commentRepository->find($id);
        if (!$comment instanceof BlogComment) {
            throw new NotFoundHttpException($this->translator->trans('studio.blog.comments.error.not_found'));
        }

        return $comment;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(BlogCommentService::CSRF_ADMIN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.blog.settings.csrf_invalid'));
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Blog\Controller;

use App\Entity\Node;
use App\Entity\User;
use App\Repository\NodeRepository;
use Modules\Blog\Entity\BlogComment;
use Modules\Blog\Repository\BlogCommentRepository;
use Modules\Blog\Service\BlogCommentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Front comment submit / own-edit. Listing is rendered on the post show page.
 */
final class CommentFrontController extends AbstractController
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly BlogCommentRepository $commentRepository,
        private readonly BlogCommentService $commentService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/blog/{slug}/yorum', name: 'blog_comment_create', methods: ['POST'], priority: 2)]
    public function create(Request $request, string $slug): Response
    {
        $post = $this->publishedPost($slug, $request->getLocale());
        $this->assertCsrf($request);

        $user = $this->getUser();
        $actor = $user instanceof User ? $user : null;
        $result = $this->commentService->submitFromRequest($post, $request, $actor);

        $this->addFlash($result['ok'] ? 'success' : 'error', $this->translator->trans($result['message']));

        $url = $this->generateUrl('blog_show', [
            '_locale' => $post->getLocale(),
            'slug' => $post->getSlug(),
        ]);

        return $this->redirect($url.($result['ok'] ? '#comments' : '#comments-form'));
    }

    #[Route('/blog/{slug}/yorum/{id}', name: 'blog_comment_update', methods: ['POST'], priority: 2, requirements: ['id' => '\d+'])]
    public function update(Request $request, string $slug, int $id): Response
    {
        $post = $this->publishedPost($slug, $request->getLocale());
        $this->assertCsrf($request);

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $comment = $this->commentRepository->find($id);
        if (!$comment instanceof BlogComment || $comment->getNode()->getId() !== $post->getId()) {
            throw $this->createNotFoundException();
        }

        $ok = $this->commentService->updateOwnComment(
            $comment,
            $user,
            (string) $request->request->get('body', ''),
        );

        $this->addFlash(
            $ok ? 'success' : 'error',
            $this->translator->trans($ok ? 'blog.comments.flash.updated' : 'blog.comments.error.edit_denied'),
        );

        return $this->redirect($this->generateUrl('blog_show', [
            '_locale' => $post->getLocale(),
            'slug' => $post->getSlug(),
        ]).'#comment-'.$comment->getId());
    }

    private function publishedPost(string $slug, string $locale): Node
    {
        $node = $this->nodeRepository->findOnePublishedBySlugAndLocale($slug, $locale);
        if (!$node instanceof Node || $node->getType() !== self::NODE_TYPE) {
            throw $this->createNotFoundException();
        }

        return $node;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(BlogCommentService::CSRF_FRONT, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('blog.comments.error.csrf'));
        }
    }
}

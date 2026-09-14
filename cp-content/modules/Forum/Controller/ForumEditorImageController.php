<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Core\Security\Flood\FloodService;
use App\Entity\User;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Service\ForumAttachmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CKEditor composer upload. The post does not exist yet, so this writes an
 * Asset and returns its public URL; the body <img> is what ties it to the post.
 */
final class ForumEditorImageController extends AbstractController
{
    private const CSRF_ID = 'forum_editor_image';
    private const FLOOD_EVENT = 'forum_editor_image';
    private const FLOOD_LIMIT = 20;
    private const FLOOD_WINDOW = 600;

    public function __construct(
        private readonly ForumAttachmentService $attachmentService,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly FloodService $flood,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forums/editor-image', name: 'forum_editor_image', methods: ['POST'], priority: 5)]
    #[IsGranted('IS_AUTHENTICATED')]
    public function upload(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            return $this->fail('forum.editor.image.csrf', Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->fail('forum.editor.image.denied', Response::HTTP_FORBIDDEN);
        }

        $section = $this->sectionRepository->find($request->request->getInt('section_id'));
        if ($section === null) {
            return $this->fail('forum.editor.image.section', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->attachmentService->canUploadInSection($section, $user)) {
            return $this->fail('forum.editor.image.denied', Response::HTTP_FORBIDDEN);
        }

        $identifier = $user->getUserIdentifier();
        if (!$this->flood->isAllowed(self::FLOOD_EVENT, $identifier, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
            return $this->fail('forum.editor.image.flood', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $file = $request->files->get('upload');
        if (!$file instanceof UploadedFile) {
            return $this->fail('forum.editor.image.missing', Response::HTTP_BAD_REQUEST);
        }

        $this->flood->register(self::FLOOD_EVENT, $identifier, self::FLOOD_WINDOW);

        try {
            $asset = $this->attachmentService->storeComposerImage($file);
        } catch (UnsupportedAssetTypeException) {
            return $this->fail('forum.editor.image.not_image', Response::HTTP_BAD_REQUEST);
        } catch (InvalidUploadException) {
            return $this->fail('forum.editor.image.too_large', Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'url' => '/uploads/'.$asset->getStorageKey(),
        ]);
    }

    private function fail(string $messageKey, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => $this->translator->trans($messageKey),
            ],
        ], $status);
    }
}

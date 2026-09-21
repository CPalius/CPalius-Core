<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Localization\LocaleProvider;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumAnnouncement;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Repository\ForumAnnouncementRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/forum/announcements', name: 'admin_forum_announcements_')]
#[IsGranted('forum.nodes.manage')]
final class ForumAnnouncementAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumAnnouncementRepository $announcementRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@ForumModule/admin/announcements/index.html.twig', [
            'announcements' => $this->announcementRepository->findAllForAdmin(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $body = trim((string) $request->request->get('body'));
            if ($body === '') {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.announcements.body_required'));
            }

            $row = new ForumAnnouncement($body, $this->resolveSection($request));
            $this->applyWindow($row, $request);
            $this->entityManager->persist($row);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.announcements.created'));

            return $this->redirectToRoute('admin_forum_announcements_index');
        }

        return $this->renderAnnouncementForm(null, [
            'sectionId' => null,
            'body' => '',
            'startsAt' => '',
            'endsAt' => '',
            'active' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $row = $this->findOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $body = trim((string) $request->request->get('body'));
            if ($body === '') {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.announcements.body_required'));
            }

            $row->setBody($body);
            $row->setSection($this->resolveSection($request));
            $this->applyWindow($row, $request);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.announcements.updated'));

            return $this->redirectToRoute('admin_forum_announcements_index');
        }

        return $this->renderAnnouncementForm($row, [
            'sectionId' => $row->getSection()?->getId(),
            'body' => $row->getBody(),
            'startsAt' => $this->formatDateTime($row->getStartsAt()),
            'endsAt' => $this->formatDateTime($row->getEndsAt()),
            'active' => $row->isActive(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $row = $this->findOrFail($id);
        $this->assertValidCsrf($request);
        $this->entityManager->remove($row);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('studio.forum.announcements.deleted'));

        return $this->redirectToRoute('admin_forum_announcements_index');
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderAnnouncementForm(?ForumAnnouncement $announcement, array $formValues): Response
    {
        return $this->render('@ForumModule/admin/announcements/form.html.twig', [
            'announcement' => $announcement,
            'formValues' => $formValues,
            'tree' => $this->hierarchyService->buildAdminTree($this->localeProvider->getDefaultCode()),
        ]);
    }

    private function applyWindow(ForumAnnouncement $row, Request $request): void
    {
        $row->setStartsAt($this->parseDateTime((string) $request->request->get('starts_at')));
        $row->setEndsAt($this->parseDateTime((string) $request->request->get('ends_at')));
        $row->setActive($request->request->getBoolean('is_active'));
    }

    private function resolveSection(Request $request): ?ForumSection
    {
        $raw = $request->request->get('section_id');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit((string) $raw)) {
            return null;
        }

        $section = $this->sectionRepository->find((int) $raw);

        return $section instanceof ForumSection ? $section : null;
    }

    private function parseDateTime(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function formatDateTime(?\DateTimeImmutable $value): string
    {
        return $value instanceof \DateTimeImmutable ? $value->format('Y-m-d\TH:i') : '';
    }

    private function findOrFail(int $id): ForumAnnouncement
    {
        $row = $this->announcementRepository->find($id);
        if (!$row instanceof ForumAnnouncement) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.announcements.not_found'));
        }

        return $row;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_announcement', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }
}

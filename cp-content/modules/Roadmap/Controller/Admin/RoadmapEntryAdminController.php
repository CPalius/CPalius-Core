<?php

declare(strict_types=1);

namespace Modules\Roadmap\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Core\Localization\LocaleProvider;
use Modules\Roadmap\Entity\RoadmapEntry;
use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio CRUD for roadmap entries. $body is the only |raw field — sanitize before persist (Law 5.3).
 * $summary stays plain text (auto-escaped); sanitizing it would mangle legitimate "<" characters.
 */
#[Route('/admin/roadmap', name: 'admin_roadmap_')]
#[IsGranted('roadmap.manage')]
final class RoadmapEntryAdminController extends AbstractController
{


    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoadmapEntryRepository $entryRepository,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $localeProvider,
        private readonly RichTextSanitizer $richTextSanitizer,
    ) {
    }

    /**
     * Entries are single-locale; use the active default from LocaleProvider.
     */
    private function defaultLocale(): string
    {
        return $this->localeProvider->getDefaultCode();
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'studio.roadmap.menu.entries',
        icon: 'heroicons:map',
        panel: 'studio',
        priority: 24,
        capability: 'roadmap.manage',
        group: 'İçerik',
    )]
    public function index(Request $request): Response
    {
        $status = $request->query->getString('status');
        $kind = $request->query->getString('kind');

        return $this->render('@RoadmapModule/admin/entries/index.html.twig', [
            'entries' => $this->entryRepository->findAdminList(
                $this->defaultLocale(),
                $status !== '' ? $status : null,
                $kind !== '' ? $kind : null,
            ),
            'statusFilter' => $status,
            'kindFilter' => $kind,
            'statuses' => RoadmapEntry::STATUSES,
            'kinds' => RoadmapEntry::KINDS,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $values = $this->readFormValues($request);
            $errors = $this->validate($values);

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }

                return $this->render('@RoadmapModule/admin/entries/form.html.twig', [
                    'entry' => null,
                    'formValues' => $values,
                    'statuses' => RoadmapEntry::STATUSES,
                    'kinds' => RoadmapEntry::KINDS,
                ]);
            }

            $slug = $this->resolveSlug($values['title'], $values['slug']);
            if ($this->entryRepository->findOneBySlugAndLocale($slug, $this->defaultLocale()) !== null) {
                $this->addFlash('error', $this->translator->trans('studio.roadmap.error.slug_exists'));

                return $this->render('@RoadmapModule/admin/entries/form.html.twig', [
                    'entry' => null,
                    'formValues' => $values,
                    'statuses' => RoadmapEntry::STATUSES,
                    'kinds' => RoadmapEntry::KINDS,
                ]);
            }

            $entry = new RoadmapEntry($values['title'], $slug, $this->defaultLocale());
            $this->applyValues($entry, $values);
            $this->entityManager->persist($entry);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('studio.roadmap.flash.created', [
                'title' => $entry->getTitle(),
            ]));

            return $this->redirectToRoute('admin_roadmap_index');
        }

        return $this->render('@RoadmapModule/admin/entries/form.html.twig', [
            'entry' => null,
            'formValues' => $this->defaultFormValues(),
            'statuses' => RoadmapEntry::STATUSES,
            'kinds' => RoadmapEntry::KINDS,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $entry = $this->findOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $values = $this->readFormValues($request);
            $errors = $this->validate($values);

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }

                return $this->render('@RoadmapModule/admin/entries/form.html.twig', [
                    'entry' => $entry,
                    'formValues' => $values,
                    'statuses' => RoadmapEntry::STATUSES,
                    'kinds' => RoadmapEntry::KINDS,
                ]);
            }

            $slug = $this->resolveSlug($values['title'], $values['slug']);
            $existing = $this->entryRepository->findOneBySlugAndLocale($slug, $this->defaultLocale());
            if ($existing instanceof RoadmapEntry && $existing->getId() !== $entry->getId()) {
                $this->addFlash('error', $this->translator->trans('studio.roadmap.error.slug_exists'));

                return $this->render('@RoadmapModule/admin/entries/form.html.twig', [
                    'entry' => $entry,
                    'formValues' => $values,
                    'statuses' => RoadmapEntry::STATUSES,
                    'kinds' => RoadmapEntry::KINDS,
                ]);
            }

            $entry->setTitle($values['title'])->setSlug($slug);
            $this->applyValues($entry, $values);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('studio.roadmap.flash.updated', [
                'title' => $entry->getTitle(),
            ]));

            return $this->redirectToRoute('admin_roadmap_index');
        }

        return $this->render('@RoadmapModule/admin/entries/form.html.twig', [
            'entry' => $entry,
            'formValues' => [
                'title' => $entry->getTitle(),
                'slug' => $entry->getSlug(),
                'summary' => $entry->getSummary(),
                'body' => $entry->getBody() ?? '',
                'status' => $entry->getStatus(),
                'kind' => $entry->getKind(),
                'versionLabel' => $entry->getVersionLabel() ?? '',
                'icon' => $entry->getIcon() ?? '',
                'publishedAt' => $entry->getPublishedAt()?->format('Y-m-d\TH:i') ?? '',
                'sortOrder' => (string) $entry->getSortOrder(),
                'isFeatured' => $entry->isFeatured(),
            ],
            'statuses' => RoadmapEntry::STATUSES,
            'kinds' => RoadmapEntry::KINDS,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $entry = $this->findOrFail($id);
        $this->assertValidCsrf($request);

        $title = $entry->getTitle();
        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('studio.roadmap.flash.deleted', ['title' => $title]));

        return $this->redirectToRoute('admin_roadmap_index');
    }

    private function findOrFail(int $id): RoadmapEntry
    {
        $entry = $this->entryRepository->find($id);
        if (!$entry instanceof RoadmapEntry) {
            throw new NotFoundHttpException($this->translator->trans('studio.roadmap.error.not_found'));
        }

        return $entry;
    }

    /**
     * @return array{
     *     title: string,
     *     slug: string,
     *     summary: string,
     *     body: string,
     *     status: string,
     *     kind: string,
     *     versionLabel: string,
     *     icon: string,
     *     publishedAt: string,
     *     sortOrder: string,
     *     isFeatured: bool
     * }
     */
    private function readFormValues(Request $request): array
    {
        return [
            'title' => trim((string) $request->request->get('title')),
            'slug' => trim((string) $request->request->get('slug')),
            'summary' => trim((string) $request->request->get('summary')),
            'body' => trim((string) $request->request->get('body')),
            'status' => (string) $request->request->get('status', RoadmapEntry::STATUS_PLANNED),
            'kind' => (string) $request->request->get('kind', RoadmapEntry::KIND_MILESTONE),
            'versionLabel' => trim((string) $request->request->get('versionLabel')),
            'icon' => trim((string) $request->request->get('icon')),
            'publishedAt' => trim((string) $request->request->get('publishedAt')),
            'sortOrder' => trim((string) $request->request->get('sortOrder', '0')),
            'isFeatured' => $request->request->getBoolean('isFeatured'),
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     slug: string,
     *     summary: string,
     *     body: string,
     *     status: string,
     *     kind: string,
     *     versionLabel: string,
     *     icon: string,
     *     publishedAt: string,
     *     sortOrder: string,
     *     isFeatured: bool
     * }
     */
    private function defaultFormValues(): array
    {
        return [
            'title' => '',
            'slug' => '',
            'summary' => '',
            'body' => '',
            'status' => RoadmapEntry::STATUS_PLANNED,
            'kind' => RoadmapEntry::KIND_MILESTONE,
            'versionLabel' => '',
            'icon' => 'bi-signpost-2',
            'publishedAt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'sortOrder' => '0',
            'isFeatured' => false,
        ];
    }

    /**
     * @param array{
     *     title: string,
     *     slug: string,
     *     summary: string,
     *     body: string,
     *     status: string,
     *     kind: string,
     *     versionLabel: string,
     *     icon: string,
     *     publishedAt: string,
     *     sortOrder: string,
     *     isFeatured: bool
     * } $values
     *
     * @return list<string>
     */
    private function validate(array $values): array
    {
        $errors = [];
        if ($values['title'] === '') {
            $errors[] = $this->translator->trans('studio.roadmap.error.title_required');
        }
        if ($values['summary'] === '') {
            $errors[] = $this->translator->trans('studio.roadmap.error.summary_required');
        }
        if (!\in_array($values['status'], RoadmapEntry::STATUSES, true)) {
            $errors[] = $this->translator->trans('studio.roadmap.error.invalid_status');
        }
        if (!\in_array($values['kind'], RoadmapEntry::KINDS, true)) {
            $errors[] = $this->translator->trans('studio.roadmap.error.invalid_kind');
        }

        return $errors;
    }

    /**
     * @param array{
     *     title: string,
     *     slug: string,
     *     summary: string,
     *     body: string,
     *     status: string,
     *     kind: string,
     *     versionLabel: string,
     *     icon: string,
     *     publishedAt: string,
     *     sortOrder: string,
     *     isFeatured: bool
     * } $values
     */
    private function applyValues(RoadmapEntry $entry, array $values): void
    {
        $publishedAt = null;
        if ($values['publishedAt'] !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $values['publishedAt'])
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $values['publishedAt']);
            $publishedAt = $parsed instanceof \DateTimeImmutable ? $parsed : new \DateTimeImmutable($values['publishedAt']);
        }

        $body = $this->sanitizeBody($values['body']);

        $entry
            ->setSummary($values['summary'])
            ->setBody($body !== '' ? $body : null)
            ->setStatus($values['status'])
            ->setKind($values['kind'])
            ->setVersionLabel($values['versionLabel'] !== '' ? $values['versionLabel'] : null)
            ->setIcon($values['icon'] !== '' ? $values['icon'] : null)
            ->setPublishedAt($publishedAt)
            ->setSortOrder((int) $values['sortOrder'])
            ->setIsFeatured($values['isFeatured']);
    }

    /**
     * Sanitize body at persist (applyValues), not at form read — same pattern as Blog mapDtoToNode.
     * Empty after sanitizing (e.g. script-only) is stored as null by the caller.
     */
    private function sanitizeBody(string $body): string
    {
        if (trim($body) === '') {
            return '';
        }

        return trim($this->richTextSanitizer->sanitize($body));
    }

    private function resolveSlug(string $title, string $slug): string
    {
        $slugger = new AsciiSlugger($this->defaultLocale());
        $base = $slug !== '' ? $slug : $title;

        return strtolower($slugger->slug($base)->toString());
    }

    private function assertValidCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_roadmap_form', $submitted)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}

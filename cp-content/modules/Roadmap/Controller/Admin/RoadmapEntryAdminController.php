<?php

declare(strict_types=1);

namespace Modules\Roadmap\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationGroupResolver;
use App\Core\OriginCache\OriginCachePurger;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Roadmap\Entity\RoadmapEntry;
use Modules\Roadmap\Repository\RoadmapEntryRepository;
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
 * Studio CRUD for roadmap entries. Locale is fixed after create; translations
 * link via translation_group_id (Blog category pattern).
 * $body is the only |raw field — sanitize before persist (Law 5.3).
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
        private readonly TranslationGroupResolver $translationGroupResolver,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly OriginCachePurger $originCachePurger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'studio.roadmap.menu.entries',
        icon: 'heroicons:map',
        panel: 'studio',
        priority: 24,
        capability: 'roadmap.manage',
        group: 'studio.group.content',
    )]
    public function index(Request $request): Response
    {
        $locale = $this->resolveLocale($request->query->get('locale'));
        $status = $request->query->getString('status');
        $kind = $request->query->getString('kind');

        return $this->render('@RoadmapModule/admin/entries/index.html.twig', [
            'entries' => $this->entryRepository->findAdminList(
                $locale,
                $status !== '' ? $status : null,
                $kind !== '' ? $kind : null,
            ),
            'statusFilter' => $status,
            'kindFilter' => $kind,
            'statuses' => RoadmapEntry::STATUSES,
            'kinds' => RoadmapEntry::KINDS,
            'locales' => $this->localeProvider->getLocales(),
            'currentLocale' => $locale,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $bag = $request->isMethod('POST') ? $request->request : $request->query;
        $locale = $this->resolveLocale($bag->get('locale'));
        $source = $this->findTranslationSource($bag->get('translation_of'));

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $values = $this->readFormValues($request);
            $errors = $this->validate($values);

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }

                return $this->renderForm(null, $locale, $source, $values);
            }

            if ($source instanceof RoadmapEntry && $source->getLocale() !== $locale) {
                $existing = $this->translationGroupResolver->findGroup($source)[$locale] ?? null;
                if ($existing instanceof RoadmapEntry) {
                    $this->addFlash('error', $this->translator->trans('studio.roadmap.error.translation_exists', [
                        'title' => $source->getTitle(),
                        'locale' => $locale,
                    ]));

                    return $this->redirectToRoute('admin_roadmap_edit', ['id' => $existing->getId()]);
                }
            }

            $slug = $this->resolveSlug($values['title'], $values['slug'], $locale, null);
            $entry = new RoadmapEntry($values['title'], $slug, $locale);
            $this->applyValues($entry, $values);

            if ($source instanceof RoadmapEntry && $source->getLocale() !== $locale) {
                $this->translationGroupResolver->link($source, $entry);
            }

            $this->entityManager->persist($entry);
            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('roadmap', 'home');

            $this->addFlash('success', $source instanceof RoadmapEntry
                ? $this->translator->trans('cp.translation_tabs.linked_flash', [
                    'name' => $entry->getTitle(),
                    'locale' => $locale,
                ])
                : $this->translator->trans('studio.roadmap.flash.created', [
                    'title' => $entry->getTitle(),
                ]));

            return $this->redirectToRoute('admin_roadmap_index', ['locale' => $locale]);
        }

        $formValues = $source instanceof RoadmapEntry
            ? $this->formValuesFromEntry($source)
            : $this->defaultFormValues();
        $formValues['slug'] = '';

        return $this->renderForm(null, $locale, $source, $formValues);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $entry = $this->findOrFail($id);
        $locale = $entry->getLocale();

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $values = $this->readFormValues($request);
            $errors = $this->validate($values);

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }

                return $this->renderForm($entry, $locale, null, $values);
            }

            $slug = $this->resolveSlug($values['title'], $values['slug'], $locale, $entry->getId());
            $entry->setTitle($values['title'])->setSlug($slug);
            $this->applyValues($entry, $values);
            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('roadmap', 'home');

            $this->addFlash('success', $this->translator->trans('studio.roadmap.flash.updated', [
                'title' => $entry->getTitle(),
            ]));

            return $this->redirectToRoute('admin_roadmap_index', ['locale' => $locale]);
        }

        return $this->renderForm($entry, $locale, null, $this->formValuesFromEntry($entry));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $entry = $this->findOrFail($id);
        $this->assertValidCsrf($request);

        $locale = $entry->getLocale();
        $title = $entry->getTitle();
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('roadmap', 'home');

        $this->addFlash('success', $this->translator->trans('studio.roadmap.flash.deleted', ['title' => $title]));

        return $this->redirectToRoute('admin_roadmap_index', ['locale' => $locale]);
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderForm(?RoadmapEntry $entry, string $locale, ?RoadmapEntry $source, array $formValues): Response
    {
        return $this->render('@RoadmapModule/admin/entries/form.html.twig', [
            'entry' => $entry,
            'formValues' => $formValues,
            'statuses' => RoadmapEntry::STATUSES,
            'kinds' => RoadmapEntry::KINDS,
            'locale' => $locale,
            'sourceId' => $source?->getId(),
            'translationTabs' => $entry instanceof RoadmapEntry
                ? $this->translationGroupResolver->tabsFor($entry)
                : ($source instanceof RoadmapEntry ? $this->translationGroupResolver->tabsFor($source) : []),
            'tabsSourceId' => $entry?->getId() ?? $source?->getId(),
        ]);
    }

    private function findOrFail(int $id): RoadmapEntry
    {
        $entry = $this->entryRepository->find($id);
        if (!$entry instanceof RoadmapEntry) {
            throw new NotFoundHttpException($this->translator->trans('studio.roadmap.error.not_found'));
        }

        return $entry;
    }

    private function findTranslationSource(mixed $rawId): ?RoadmapEntry
    {
        $id = $this->intOrNull($rawId);

        return $id !== null ? $this->entryRepository->find($id) : null;
    }

    private function resolveLocale(mixed $raw): string
    {
        return $this->localeProvider->resolve(\is_string($raw) ? $raw : null);
    }

    private function intOrNull(mixed $raw): ?int
    {
        return $raw !== null && ctype_digit((string) $raw) ? (int) $raw : null;
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
    private function formValuesFromEntry(RoadmapEntry $entry): array
    {
        return [
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
     */
    private function sanitizeBody(string $body): string
    {
        if (trim($body) === '') {
            return '';
        }

        return trim($this->richTextSanitizer->sanitize($body));
    }

    private function resolveSlug(string $title, string $slug, string $locale, ?int $excludeId): string
    {
        $slugger = new AsciiSlugger($locale);
        $base = $slug !== '' ? $slug : $title;
        $baseSlug = strtolower($slugger->slug($base)->toString());
        if ($baseSlug === '') {
            $baseSlug = 'r-'.substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $candidate = $baseSlug;
        $suffix = 2;
        while ($this->entryRepository->slugExists($candidate, $locale, $excludeId)) {
            $candidate = $baseSlug.'-'.$suffix;
            ++$suffix;
        }

        return $candidate;
    }

    private function assertValidCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_roadmap_form', $submitted)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}

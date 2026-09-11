<?php

declare(strict_types=1);

namespace Modules\Pages\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\SlugGenerator;
use App\Core\Localization\LocaleProvider;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Pages\Field\PageFieldNormalizer;
use Modules\Pages\Field\PageFieldPresets;
use Modules\Pages\Field\PageFieldType;
use Modules\Pages\Form\DTO\FieldGroupFormModel;
use Modules\Pages\Form\FieldGroupType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/pages/field-groups', name: 'admin_page_field_groups_')]
#[IsGranted('pages.field_group.manage')]
final class PageFieldGroupAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRepository $nodeRepository,
        private readonly SlugGenerator $slugGenerator,
        private readonly PageFieldNormalizer $fieldNormalizer,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.pages.field_groups.menu', icon: 'heroicons:squares-plus', panel: 'studio', priority: 16, capability: 'pages.field_group.manage', parent: 'admin_pages_index')]
    public function index(): Response
    {
        $locale = $this->localeProvider->getDefaultCode();
        $groups = $this->nodeRepository->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', PageFieldNormalizer::NODE_TYPE_FIELD_GROUP)
            ->orderBy('n.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('@PagesModule/admin/field-groups/index.html.twig', [
            'groups' => $groups,
            'locale' => $locale,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $locale = $this->localeProvider->resolve(trim((string) $request->query->get('locale')));
        $dto = new FieldGroupFormModel();
        $form = $this->createForm(FieldGroupType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = trim((string) $dto->slug);
            $base = $submitted !== '' ? $submitted : $dto->title;
            if (!str_starts_with($base, 'fg-')) {
                $base = 'fg-'.$base;
            }
            $slug = $this->slugGenerator->generate($base, $locale);

            $node = new Node($dto->title, $slug, PageFieldNormalizer::NODE_TYPE_FIELD_GROUP, $locale);
            $node->publish();
            $user = $this->getUser();
            if ($user instanceof User) {
                $node->setAuthor($user);
            }
            $node->setDataValue('fields', $this->decodeFields($dto->fieldsJson));

            $this->entityManager->persist($node);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('pages.field_groups.flash.created', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_page_field_groups_index');
        }

        return $this->render('@PagesModule/admin/field-groups/form.html.twig', [
            'group' => null,
            'form' => $form,
            'fieldTypeChoices' => PageFieldType::choices(),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $node = $this->findGroupOrFail($id);
        $dto = new FieldGroupFormModel();
        $dto->title = $node->getTitle();
        $dto->slug = $node->getSlug();
        $fields = $node->getDataValue('fields', []);
        $dto->fieldsJson = json_encode(\is_array($fields) ? $fields : [], \JSON_UNESCAPED_UNICODE) ?: '[]';

        $form = $this->createForm(FieldGroupType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submittedSlug = trim((string) $dto->slug);
            if ($submittedSlug !== '' && $submittedSlug !== $node->getSlug()) {
                if (!str_starts_with($submittedSlug, 'fg-')) {
                    $submittedSlug = 'fg-'.$submittedSlug;
                }
                $slug = $this->slugGenerator->generate($submittedSlug, $node->getLocale(), $node->getId());
            } else {
                $slug = $node->getSlug();
            }

            $node->setTitle($dto->title);
            $node->setSlug($slug);
            $node->setDataValue('fields', $this->decodeFields($dto->fieldsJson));
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('pages.field_groups.flash.updated', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_page_field_groups_index');
        }

        return $this->render('@PagesModule/admin/field-groups/form.html.twig', [
            'group' => $node,
            'form' => $form,
            'fieldTypeChoices' => PageFieldType::choices(),
        ]);
    }

    #[Route('/install-presets', name: 'install_presets', methods: ['POST'])]
    public function installPresets(Request $request): Response
    {
        $this->assertValidCsrf($request, 'admin_page_field_group_form');
        $locale = $this->localeProvider->getDefaultCode();
        $created = 0;

        foreach (PageFieldPresets::all($this->translator, $locale) as $preset) {
            if ($this->nodeRepository->slugExists($preset['identifier'], $locale)) {
                continue;
            }

            $node = new Node($preset['title'], $preset['identifier'], PageFieldNormalizer::NODE_TYPE_FIELD_GROUP, $locale);
            $node->publish();
            $user = $this->getUser();
            if ($user instanceof User) {
                $node->setAuthor($user);
            }
            $node->setDataValue('fields', $this->fieldNormalizer->normalizeList($preset['fields'], false));
            $this->entityManager->persist($node);
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        $this->addFlash(
            'success',
            $this->translator->trans('pages.field_groups.flash.presets_installed', ['count' => $created]),
        );

        return $this->redirectToRoute('admin_page_field_groups_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $node = $this->findGroupOrFail($id);
        $this->assertValidCsrf($request, 'admin_page_field_group_form');
        $node->softDelete();
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('pages.field_groups.flash.deleted', ['title' => $node->getTitle()]));

        return $this->redirectToRoute('admin_page_field_groups_index');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeFields(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = [];
        }

        if (!\is_array($decoded)) {
            $decoded = [];
        }

        /** @var list<mixed> $decoded */
        return $this->fieldNormalizer->normalizeList($decoded, false);
    }

    private function findGroupOrFail(int $id): Node
    {
        $node = $this->nodeRepository->find($id);
        if (!$node instanceof Node || $node->getType() !== PageFieldNormalizer::NODE_TYPE_FIELD_GROUP || $node->getDeletedAt() !== null) {
            throw new NotFoundHttpException($this->translator->trans('pages.field_groups.error.not_found'));
        }

        return $node;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $submitted)) {
            throw $this->createAccessDeniedException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}

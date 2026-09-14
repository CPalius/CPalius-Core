<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Entity\UrlAlias;
use App\Repository\CategoryRepository;
use App\Repository\LocaleRepository;
use App\Repository\NodeRepository;
use App\Repository\UrlAliasRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * URL alias admin nested under Araçlar; manual CSRF, core.url_alias.manage capability.
 */
#[Route('/aacp/url-aliases', name: 'aacp_url_alias_')]
#[IsGranted('core.url_alias.manage')]
final class UrlAliasAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlAliasRepository $urlAliasRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly LocaleRepository $localeRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.url_aliases', icon: 'heroicons:link', panel: 'aacp', priority: 66, capability: 'core.url_alias.manage', parent: 'aacp_hub_structure')]
    public function index(): Response
    {
        return $this->render('aacp/url_aliases/index.html.twig', [
            'aliases' => $this->urlAliasRepository->findAllOrdered(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'aacp_url_alias_form');

            $formValues = $this->extractFormValues($request);
            $error = $this->validateFormValues($formValues);

            if ($error !== null) {
                $this->addFlash('error', $error);

                return $this->render('aacp/url_aliases/create.html.twig', $this->targetPickerContext($formValues));
            }

            $alias = new UrlAlias($formValues['aliasPath'], $formValues['locale'], $formValues['targetType']);
            $this->applyTarget($alias, $formValues);
            $alias->setIsActive($formValues['isActive']);

            $this->entityManager->persist($alias);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('aacp.url_alias.create_success', ['aliasPath' => $formValues['aliasPath']]));

            return $this->redirectToRoute('aacp_url_alias_index');
        }

        return $this->render('aacp/url_aliases/create.html.twig', $this->targetPickerContext([
            'aliasPath' => '', 'locale' => '', 'targetType' => UrlAlias::TARGET_NODE, 'targetNodeId' => '', 'targetCategoryId' => '', 'targetRouteName' => '', 'isActive' => true,
        ]));
    }

    /**
     * @param array<string, mixed> $formValues
     *
     * @return array<string, mixed>
     */
    private function targetPickerContext(array $formValues): array
    {
        return [
            'formValues' => $formValues,
            'locales' => $this->localeRepository->findBy([], ['sortOrder' => 'ASC']),
            'nodes' => $this->nodeRepository->findBy(['status' => 'published'], ['title' => 'ASC'], 200),
            'categories' => $this->categoryRepository->findAllSorted(200),
        ];
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $alias = $this->findAliasOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'aacp_url_alias_form');

            $formValues = $this->extractFormValues($request);
            $error = $this->validateFormValues($formValues, excludeId: $alias->getId());

            if ($error !== null) {
                $this->addFlash('error', $error);

                return $this->render('aacp/url_aliases/edit.html.twig', $this->targetPickerContext($formValues) + ['alias' => $alias]);
            }

            $alias->setAliasPath($formValues['aliasPath']);
            $alias->setLocale($formValues['locale']);
            $alias->setTargetType($formValues['targetType']);
            $this->applyTarget($alias, $formValues);
            $alias->setIsActive($formValues['isActive']);

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('aacp.url_alias.update_success'));

            return $this->redirectToRoute('aacp_url_alias_index');
        }

        $formValues = [
            'aliasPath' => $alias->getAliasPath(),
            'locale' => $alias->getLocale(),
            'targetType' => $alias->getTargetType(),
            'targetNodeId' => (string) ($alias->getTargetNodeId() ?? ''),
            'targetCategoryId' => (string) ($alias->getTargetCategoryId() ?? ''),
            'targetRouteName' => $alias->getTargetRouteName() ?? '',
            'isActive' => $alias->isActive(),
        ];

        return $this->render('aacp/url_aliases/edit.html.twig', $this->targetPickerContext($formValues) + ['alias' => $alias]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $alias = $this->findAliasOrFail($id);
        $this->assertValidCsrf($request, 'aacp_url_alias_form');

        $this->entityManager->remove($alias);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('aacp.url_alias.delete_success'));

        return $this->redirectToRoute('aacp_url_alias_index');
    }

    /**
     * @return array{aliasPath: string, locale: string, targetType: string, targetNodeId: string, targetCategoryId: string, targetRouteName: string, isActive: bool}
     */
    private function extractFormValues(Request $request): array
    {
        return [
            'aliasPath' => ltrim(trim((string) $request->request->get('alias_path')), '/'),
            'locale' => trim((string) $request->request->get('locale')),
            'targetType' => trim((string) $request->request->get('target_type')),
            'targetNodeId' => trim((string) $request->request->get('target_node_id')),
            'targetCategoryId' => trim((string) $request->request->get('target_category_id')),
            'targetRouteName' => trim((string) $request->request->get('target_route_name')),
            'isActive' => $request->request->getBoolean('is_active'),
        ];
    }

    /**
     * @param array{aliasPath: string, locale: string, targetType: string, targetNodeId: string, targetCategoryId: string, targetRouteName: string, isActive: bool} $formValues
     */
    private function validateFormValues(array $formValues, ?int $excludeId = null): ?string
    {
        if ($formValues['aliasPath'] === '' || $formValues['locale'] === '') {
            return $this->translator->trans('aacp.url_alias.validation.path_locale_required');
        }

        if (!\in_array($formValues['targetType'], [UrlAlias::TARGET_NODE, UrlAlias::TARGET_CATEGORY, UrlAlias::TARGET_ROUTE], true)) {
            return $this->translator->trans('aacp.url_alias.validation.invalid_target_type');
        }

        if ($formValues['targetType'] === UrlAlias::TARGET_NODE && $formValues['targetNodeId'] === '') {
            return $this->translator->trans('aacp.url_alias.validation.target_node_required');
        }

        if ($formValues['targetType'] === UrlAlias::TARGET_CATEGORY && $formValues['targetCategoryId'] === '') {
            return $this->translator->trans('aacp.url_alias.validation.target_category_required');
        }

        if ($formValues['targetType'] === UrlAlias::TARGET_ROUTE && $formValues['targetRouteName'] === '') {
            return $this->translator->trans('aacp.url_alias.validation.target_route_required');
        }

        if ($this->urlAliasRepository->pathExists($formValues['aliasPath'], $formValues['locale'], $excludeId)) {
            return $this->translator->trans('aacp.url_alias.validation.path_taken', ['aliasPath' => $formValues['aliasPath']]);
        }

        return null;
    }

    /**
     * @param array{targetType: string, targetNodeId: string, targetCategoryId: string, targetRouteName: string} $formValues
     */
    private function applyTarget(UrlAlias $alias, array $formValues): void
    {
        $alias->setTargetNodeId(null);
        $alias->setTargetCategoryId(null);
        $alias->setTargetRouteName(null);

        match ($formValues['targetType']) {
            UrlAlias::TARGET_NODE => $alias->setTargetNodeId((int) $formValues['targetNodeId']),
            UrlAlias::TARGET_CATEGORY => $alias->setTargetCategoryId((int) $formValues['targetCategoryId']),
            UrlAlias::TARGET_ROUTE => $alias->setTargetRouteName($formValues['targetRouteName']),
            default => null,
        };
    }

    private function findAliasOrFail(int $id): UrlAlias
    {
        $alias = $this->urlAliasRepository->find($id);
        if (!$alias instanceof UrlAlias) {
            throw new NotFoundHttpException($this->translator->trans('aacp.url_alias.not_found'));
        }

        return $alias;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $submitted)) {
            throw $this->createAccessDeniedException($this->translator->trans('aacp.url_alias.invalid_csrf'));
        }
    }
}

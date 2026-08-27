<?php

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\SlugGenerator;
use App\Entity\Category;
use App\Repository\CategoryRepository;
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
 * Blog kategorilerinin (hiyerarşik) yönetim ekranı. Category entity'si
 * çekirdekte (App\Entity\Category) yaşar — Node'un hem tekil "birincil
 * kategori" hem de çoklu (node_category) ilişkisi bu tablo üzerinden
 * kurulur (bkz. PostAdminController::applySeoAndTaxonomy).
 */
#[Route('/admin/categories', name: 'admin_categories_')]
#[IsGranted('blog.category.manage')]
final class CategoryAdminController extends AbstractController
{
    private const DEFAULT_LOCALE = 'tr';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categoryRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Kategoriler', icon: 'heroicons:folder', panel: 'studio', priority: 21, capability: 'blog.category.manage', group: 'İçerik')]
    public function index(): Response
    {
        return $this->render('@BlogModule/admin/categories/index.html.twig', [
            'categories' => $this->categoryRepository->findBy(['locale' => self::DEFAULT_LOCALE]),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_category_form');

            $name = trim((string) $request->request->get('name'));
            $description = trim((string) $request->request->get('description'));
            $parentId = $request->request->get('parent_id');
            $parentId = $parentId !== null && ctype_digit((string) $parentId) ? (int) $parentId : null;

            if ($name === '') {
                $this->addFlash('error', $this->translator->trans('blog.categories.error.name_required'));

                return $this->render('@BlogModule/admin/categories/form.html.twig', [
                    'category' => null,
                    'categories' => $this->categoryRepository->findBy(['locale' => self::DEFAULT_LOCALE]),
                    'formValues' => ['name' => $name, 'description' => $description, 'parentId' => $parentId],
                ]);
            }

            $slugger = new AsciiSlugger(self::DEFAULT_LOCALE);
            $slug = strtolower($slugger->slug($name)->toString());

            $category = new Category($name, $slug, self::DEFAULT_LOCALE);
            $category->setDescription($description !== '' ? $description : null);
            if ($parentId !== null) {
                $category->setParent($this->categoryRepository->find($parentId));
            }

            $this->entityManager->persist($category);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('blog.categories.flash.created', ['name' => $name]));

            return $this->redirectToRoute('admin_categories_index');
        }

        return $this->render('@BlogModule/admin/categories/form.html.twig', [
            'category' => null,
            'categories' => $this->categoryRepository->findBy(['locale' => self::DEFAULT_LOCALE]),
            'formValues' => ['name' => '', 'description' => '', 'parentId' => null],
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $category = $this->findCategoryOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_category_form');

            $name = trim((string) $request->request->get('name'));
            $description = trim((string) $request->request->get('description'));
            $parentId = $request->request->get('parent_id');
            $parentId = $parentId !== null && ctype_digit((string) $parentId) ? (int) $parentId : null;

            if ($name === '') {
                $this->addFlash('error', $this->translator->trans('blog.categories.error.name_required'));

                return $this->render('@BlogModule/admin/categories/form.html.twig', [
                    'category' => $category,
                    'categories' => $this->categoryRepository->findBy(['locale' => self::DEFAULT_LOCALE]),
                    'formValues' => ['name' => $name, 'description' => $description, 'parentId' => $parentId],
                ]);
            }

            $category->setName($name);
            $category->setDescription($description !== '' ? $description : null);
            $category->setParent($parentId !== null && $parentId !== $category->getId() ? $this->categoryRepository->find($parentId) : null);

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('blog.categories.flash.updated', ['name' => $name]));

            return $this->redirectToRoute('admin_categories_index');
        }

        return $this->render('@BlogModule/admin/categories/form.html.twig', [
            'category' => $category,
            'categories' => array_filter(
                $this->categoryRepository->findBy(['locale' => self::DEFAULT_LOCALE]),
                static fn (Category $c) => $c->getId() !== $category->getId(),
            ),
            'formValues' => [
                'name' => $category->getName(),
                'description' => $category->getDescription() ?? '',
                'parentId' => $category->getParent()?->getId(),
            ],
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $category = $this->findCategoryOrFail($id);
        $this->assertValidCsrf($request, 'admin_category_form');

        $this->entityManager->remove($category);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('blog.categories.flash.deleted', ['name' => $category->getName()]));

        return $this->redirectToRoute('admin_categories_index');
    }

    private function findCategoryOrFail(int $id): Category
    {
        $category = $this->categoryRepository->find($id);
        if (!$category instanceof Category) {
            throw new NotFoundHttpException($this->translator->trans('blog.categories.error.not_found'));
        }

        return $category;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $submitted)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}

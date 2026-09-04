<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationGroupResolver;
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
 * Hierarchical category admin. Locale is fixed after create; translations link via translation_group_id.
 * List filters by ?locale=; "add translation" uses translation_of + TranslationGroupResolver::link().
 */
#[Route('/admin/categories', name: 'admin_categories_')]
#[IsGranted('blog.category.manage')]
final class CategoryAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categoryRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationGroupResolver $translationGroupResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Kategoriler', icon: 'heroicons:folder', panel: 'studio', priority: 21, capability: 'blog.category.manage', parent: 'admin_posts_index')]
    public function index(Request $request): Response
    {
        $locale = $this->resolveLocale($request->query->get('locale'));

        return $this->render('@BlogModule/admin/categories/index.html.twig', [
            'categories' => $this->categoriesFor($locale),
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
            $this->assertValidCsrf($request, 'admin_category_form');

            $name = trim((string) $request->request->get('name'));
            $description = trim((string) $request->request->get('description'));
            $parentId = $this->intOrNull($request->request->get('parent_id'));

            if ($name === '') {
                $this->addFlash('error', $this->translator->trans('blog.categories.error.name_required'));

                return $this->renderForm(null, $locale, $source, [
                    'name' => $name,
                    'description' => $description,
                    'parentId' => $parentId,
                ]);
            }

            $category = new Category($name, $this->buildSlug($name, $locale), $locale);
            $category->setDescription($description !== '' ? $description : null);

            if ($parentId !== null) {
                $category->setParent($this->findParentInLocale($parentId, $locale));
            }

            // Join the translation group; link() creates one if needed.
            if ($source instanceof Category && $source->getLocale() !== $locale) {
                $this->translationGroupResolver->link($source, $category);
            }

            $this->entityManager->persist($category);
            $this->entityManager->flush();

            $this->addFlash('success', $source instanceof Category
                ? $this->translator->trans('cp.translation_tabs.linked_flash', ['name' => $name, 'locale' => $locale])
                : $this->translator->trans('blog.categories.flash.created', ['name' => $name]));

            return $this->redirectToRoute('admin_categories_index', ['locale' => $locale]);
        }

        return $this->renderForm(null, $locale, $source, [
            // Prefill the source name so the translator starts from real text.
            'name' => $source?->getName() ?? '',
            'description' => $source?->getDescription() ?? '',
            'parentId' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $category = $this->findCategoryOrFail($id);
        $locale = $category->getLocale();

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_category_form');

            $name = trim((string) $request->request->get('name'));
            $description = trim((string) $request->request->get('description'));
            $parentId = $this->intOrNull($request->request->get('parent_id'));

            if ($name === '') {
                $this->addFlash('error', $this->translator->trans('blog.categories.error.name_required'));

                return $this->renderForm($category, $locale, null, [
                    'name' => $name,
                    'description' => $description,
                    'parentId' => $parentId,
                ]);
            }

            $category->setName($name);
            $category->setDescription($description !== '' ? $description : null);
            $category->setParent(
                $parentId !== null && $parentId !== $category->getId()
                    ? $this->findParentInLocale($parentId, $locale)
                    : null,
            );

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('blog.categories.flash.updated', ['name' => $name]));

            return $this->redirectToRoute('admin_categories_index', ['locale' => $locale]);
        }

        return $this->renderForm($category, $locale, null, [
            'name' => $category->getName(),
            'description' => $category->getDescription() ?? '',
            'parentId' => $category->getParent()?->getId(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $category = $this->findCategoryOrFail($id);
        $this->assertValidCsrf($request, 'admin_category_form');

        $locale = $category->getLocale();

        $this->entityManager->remove($category);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('blog.categories.flash.deleted', ['name' => $category->getName()]));

        return $this->redirectToRoute('admin_categories_index', ['locale' => $locale]);
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderForm(?Category $category, string $locale, ?Category $source, array $formValues): Response
    {
        $parentOptions = $this->categoriesFor($locale);

        if ($category instanceof Category) {
            $parentOptions = array_values(array_filter(
                $parentOptions,
                static fn (Category $c): bool => $c->getId() !== $category->getId(),
            ));
        }

        return $this->render('@BlogModule/admin/categories/form.html.twig', [
            'category' => $category,
            'categories' => $parentOptions,
            'formValues' => $formValues,
            'locale' => $locale,
            'sourceId' => $source?->getId(),
            // Translation tabs only make sense on edit / add-translation flows.
            'translationTabs' => $category instanceof Category
                ? $this->translationGroupResolver->tabsFor($category)
                : ($source instanceof Category ? $this->translationGroupResolver->tabsFor($source) : []),
            'tabsSourceId' => $category?->getId() ?? $source?->getId(),
        ]);
    }

    /**
     * @return list<Category>
     */
    private function categoriesFor(string $locale): array
    {
        return array_values($this->categoryRepository->findBy(['locale' => $locale], ['name' => 'ASC']));
    }

    /**
     * Parent categories must share the same locale to avoid mixed breadcrumbs.
     */
    private function findParentInLocale(int $parentId, string $locale): ?Category
    {
        $parent = $this->categoryRepository->find($parentId);

        return $parent instanceof Category && $parent->getLocale() === $locale ? $parent : null;
    }

    private function findTranslationSource(mixed $rawId): ?Category
    {
        $id = $this->intOrNull($rawId);

        return $id !== null ? $this->categoryRepository->find($id) : null;
    }

    private function buildSlug(string $name, string $locale): string
    {
        $slugger = new AsciiSlugger($locale);

        return strtolower($slugger->slug($name)->toString());
    }

    private function resolveLocale(mixed $raw): string
    {
        return $this->localeProvider->resolve(\is_string($raw) ? $raw : null);
    }

    private function intOrNull(mixed $raw): ?int
    {
        return $raw !== null && ctype_digit((string) $raw) ? (int) $raw : null;
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

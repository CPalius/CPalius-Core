<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationGroupResolver;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Taxonomy\Entity\Term;
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
 * Hierarchical blog category admin backed by Vocabulary terms (blog_category).
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
        private readonly OriginCachePurger $originCachePurger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'blog.categories.header', icon: 'heroicons:folder', panel: 'studio', priority: 21, capability: 'blog.category.manage', parent: 'admin_posts_index')]
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

            $category = new Term($this->categoryRepository->vocabulary(), $name, $this->buildSlug($name, $locale), $locale);
            $category->setDescription($description !== '' ? $description : null);

            if ($parentId !== null) {
                $category->setParent($this->findParentInLocale($parentId, $locale));
            }

            if ($source instanceof Term && $source->getLocale() !== $locale) {
                $this->translationGroupResolver->link($source, $category);
            }

            $this->entityManager->persist($category);
            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

            $this->addFlash('success', $source instanceof Term
                ? $this->translator->trans('cp.translation_tabs.linked_flash', ['name' => $name, 'locale' => $locale])
                : $this->translator->trans('blog.categories.flash.created', ['name' => $name]));

            return $this->redirectToRoute('admin_categories_index', ['locale' => $locale]);
        }

        return $this->renderForm(null, $locale, $source, [
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
            $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

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
        $name = $category->getName();

        $this->entityManager->remove($category);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

        $this->addFlash('success', $this->translator->trans('blog.categories.flash.deleted', ['name' => $name]));

        return $this->redirectToRoute('admin_categories_index', ['locale' => $locale]);
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderForm(?Term $category, string $locale, ?Term $source, array $formValues): Response
    {
        $parentOptions = $this->categoriesFor($locale);

        if ($category instanceof Term) {
            $parentOptions = array_values(array_filter(
                $parentOptions,
                static fn (Term $c): bool => $c->getId() !== $category->getId(),
            ));
        }

        return $this->render('@BlogModule/admin/categories/form.html.twig', [
            'category' => $category,
            'categories' => $parentOptions,
            'formValues' => $formValues,
            'locale' => $locale,
            'sourceId' => $source?->getId(),
            'translationTabs' => $category instanceof Term
                ? $this->translationGroupResolver->tabsFor($category)
                : ($source instanceof Term ? $this->translationGroupResolver->tabsFor($source) : []),
            'tabsSourceId' => $category?->getId() ?? $source?->getId(),
        ]);
    }

    /**
     * @return list<Term>
     */
    private function categoriesFor(string $locale): array
    {
        return $this->categoryRepository->findByLocale($locale);
    }

    private function findParentInLocale(int $parentId, string $locale): ?Term
    {
        $parent = $this->categoryRepository->find($parentId);

        return $parent instanceof Term && $parent->getLocale() === $locale ? $parent : null;
    }

    private function findTranslationSource(mixed $rawId): ?Term
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

    private function findCategoryOrFail(int $id): Term
    {
        $category = $this->categoryRepository->find($id);
        if (!$category instanceof Term) {
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

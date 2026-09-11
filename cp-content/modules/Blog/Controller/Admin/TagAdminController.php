<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationGroupResolver;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Taxonomy\Entity\Term;
use App\Repository\TagRepository;
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
 * Flat blog tag admin backed by Vocabulary terms (blog_tag).
 */
#[Route('/admin/tags', name: 'admin_tags_')]
#[IsGranted('blog.category.manage')]
final class TagAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagRepository $tagRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationGroupResolver $translationGroupResolver,
        private readonly TranslatorInterface $translator,
        private readonly OriginCachePurger $originCachePurger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'blog.tags.header', icon: 'heroicons:hashtag', panel: 'studio', priority: 22, capability: 'blog.category.manage', parent: 'admin_posts_index')]
    public function index(Request $request): Response
    {
        $locale = $this->resolveLocale($request->query->get('locale'));

        return $this->render('@BlogModule/admin/tags/index.html.twig', [
            'tags' => $this->tagRepository->findByLocale($locale),
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
            $this->assertValidCsrf($request, 'admin_tag_form');

            $name = trim((string) $request->request->get('name'));

            if ($name === '') {
                $this->addFlash('error', $this->translator->trans('blog.tags.error.name_required'));

                return $this->renderForm(null, $locale, $source, ['name' => $name]);
            }

            $slug = $this->buildSlug($name, $locale);

            if ($this->tagRepository->findOneBySlug($slug, $locale) !== null) {
                $this->addFlash('error', $this->translator->trans('blog.tags.error.already_exists', ['name' => $name]));

                return $this->renderForm(null, $locale, $source, ['name' => $name]);
            }

            $tag = new Term($this->tagRepository->vocabulary(), $name, $slug, $locale);

            if ($source instanceof Term && $source->getLocale() !== $locale) {
                $this->translationGroupResolver->link($source, $tag);
            }

            $this->entityManager->persist($tag);
            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

            $this->addFlash('success', $source instanceof Term
                ? $this->translator->trans('cp.translation_tabs.linked_flash', ['name' => $name, 'locale' => $locale])
                : $this->translator->trans('blog.tags.flash.created', ['name' => $name]));

            return $this->redirectToRoute('admin_tags_index', ['locale' => $locale]);
        }

        return $this->renderForm(null, $locale, $source, ['name' => $source?->getName() ?? '']);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $tag = $this->findTagOrFail($id);
        $locale = $tag->getLocale();

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_tag_form');

            $name = trim((string) $request->request->get('name'));
            if ($name === '') {
                $this->addFlash('error', $this->translator->trans('blog.tags.error.name_required'));

                return $this->renderForm($tag, $locale, null, ['name' => $name]);
            }

            $tag->setName($name);
            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

            $this->addFlash('success', $this->translator->trans('blog.tags.flash.updated', ['name' => $name]));

            return $this->redirectToRoute('admin_tags_index', ['locale' => $locale]);
        }

        return $this->renderForm($tag, $locale, null, ['name' => $tag->getName()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $tag = $this->findTagOrFail($id);
        $this->assertValidCsrf($request, 'admin_tag_form');

        $locale = $tag->getLocale();
        $name = $tag->getName();

        $this->entityManager->remove($tag);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

        $this->addFlash('success', $this->translator->trans('blog.tags.flash.deleted', ['name' => $name]));

        return $this->redirectToRoute('admin_tags_index', ['locale' => $locale]);
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderForm(?Term $tag, string $locale, ?Term $source, array $formValues): Response
    {
        return $this->render('@BlogModule/admin/tags/form.html.twig', [
            'tag' => $tag,
            'formValues' => $formValues,
            'locale' => $locale,
            'sourceId' => $source?->getId(),
            'translationTabs' => $tag instanceof Term
                ? $this->translationGroupResolver->tabsFor($tag)
                : ($source instanceof Term ? $this->translationGroupResolver->tabsFor($source) : []),
            'tabsSourceId' => $tag?->getId() ?? $source?->getId(),
        ]);
    }

    private function findTranslationSource(mixed $rawId): ?Term
    {
        $id = $this->intOrNull($rawId);

        return $id !== null ? $this->tagRepository->find($id) : null;
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

    private function findTagOrFail(int $id): Term
    {
        $tag = $this->tagRepository->find($id);
        if (!$tag instanceof Term) {
            throw new NotFoundHttpException($this->translator->trans('blog.tags.error.not_found'));
        }

        return $tag;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $submitted)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}

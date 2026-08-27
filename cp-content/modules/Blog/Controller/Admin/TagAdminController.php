<?php

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Entity\Tag;
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
 * Tag (App\Entity\Tag) Category'den farklı olarak hiyerarşisizdir — bu
 * yönetim ekranı da buna uygun, sade bir düz liste + CRUD sunar.
 */
#[Route('/admin/tags', name: 'admin_tags_')]
#[IsGranted('blog.category.manage')]
final class TagAdminController extends AbstractController
{
    private const DEFAULT_LOCALE = 'tr';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagRepository $tagRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Etiketler', icon: 'heroicons:tag', panel: 'studio', priority: 22, capability: 'blog.category.manage', group: 'İçerik')]
    public function index(): Response
    {
        return $this->render('@BlogModule/admin/tags/index.html.twig', [
            'tags' => $this->tagRepository->findBy(['locale' => self::DEFAULT_LOCALE]),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_tag_form');

            $name = trim((string) $request->request->get('name'));

            if ($name === '') {
                $this->addFlash('error', $this->translator->trans('blog.tags.error.name_required'));

                return $this->render('@BlogModule/admin/tags/form.html.twig', [
                    'tag' => null,
                    'formValues' => ['name' => $name],
                ]);
            }

            $slugger = new AsciiSlugger(self::DEFAULT_LOCALE);
            $slug = strtolower($slugger->slug($name)->toString());

            if ($this->tagRepository->findOneBySlug($slug, self::DEFAULT_LOCALE) !== null) {
                $this->addFlash('error', $this->translator->trans('blog.tags.error.already_exists', ['name' => $name]));

                return $this->render('@BlogModule/admin/tags/form.html.twig', [
                    'tag' => null,
                    'formValues' => ['name' => $name],
                ]);
            }

            $tag = new Tag($name, $slug, self::DEFAULT_LOCALE);
            $this->entityManager->persist($tag);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('blog.tags.flash.created', ['name' => $name]));

            return $this->redirectToRoute('admin_tags_index');
        }

        return $this->render('@BlogModule/admin/tags/form.html.twig', [
            'tag' => null,
            'formValues' => ['name' => ''],
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $tag = $this->findTagOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_tag_form');

            $name = trim((string) $request->request->get('name'));

            if ($name === '') {
                $this->addFlash('error', $this->translator->trans('blog.tags.error.name_required'));

                return $this->render('@BlogModule/admin/tags/form.html.twig', [
                    'tag' => $tag,
                    'formValues' => ['name' => $name],
                ]);
            }

            $tag->setName($name);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('blog.tags.flash.updated', ['name' => $name]));

            return $this->redirectToRoute('admin_tags_index');
        }

        return $this->render('@BlogModule/admin/tags/form.html.twig', [
            'tag' => $tag,
            'formValues' => ['name' => $tag->getName()],
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $tag = $this->findTagOrFail($id);
        $this->assertValidCsrf($request, 'admin_tag_form');

        $this->entityManager->remove($tag);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('blog.tags.flash.deleted', ['name' => $tag->getName()]));

        return $this->redirectToRoute('admin_tags_index');
    }

    private function findTagOrFail(int $id): Tag
    {
        $tag = $this->tagRepository->find($id);
        if (!$tag instanceof Tag) {
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

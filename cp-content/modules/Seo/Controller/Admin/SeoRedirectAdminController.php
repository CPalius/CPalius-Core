<?php

declare(strict_types=1);

namespace Modules\Seo\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Seo\Entity\SeoRedirect;
use Modules\Seo\Redirect\SeoRedirectPath;
use Modules\Seo\Repository\SeoRedirectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/seo/redirects', name: 'admin_seo_redirects_')]
#[IsGranted('seo.manage')]
final class SeoRedirectAdminController extends AbstractController
{
    private const CSRF = 'admin_seo_redirects';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeoRedirectRepository $redirects,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'studio.seo.menu.redirects',
        icon: 'heroicons:arrows-right-left',
        panel: 'studio',
        priority: 33,
        capability: 'seo.manage',
        group: 'studio.group.content',
    )]
    public function index(): Response
    {
        return $this->render('@SeoModule/admin/redirects/index.html.twig', [
            'redirects' => $this->redirects->findAllOrdered(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $values = $this->blankValues();

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            $values = $this->readForm($request);
            $error = $this->validate($values, null);
            if ($error !== null) {
                $this->addFlash('error', $error);

                return $this->render('@SeoModule/admin/redirects/form.html.twig', [
                    'redirect' => null,
                    'values' => $values,
                ]);
            }

            $row = new SeoRedirect($values['sourcePath'], $values['targetUrl'], $values['statusCode']);
            $row->setIsActive($values['isActive']);
            $this->entityManager->persist($row);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.seo.redirects.created'));

            return $this->redirectToRoute('admin_seo_redirects_index');
        }

        return $this->render('@SeoModule/admin/redirects/form.html.twig', [
            'redirect' => null,
            'values' => $values,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $row = $this->findOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            $values = $this->readForm($request);
            $error = $this->validate($values, $row->getId());
            if ($error !== null) {
                $this->addFlash('error', $error);

                return $this->render('@SeoModule/admin/redirects/form.html.twig', [
                    'redirect' => $row,
                    'values' => $values,
                ]);
            }

            $row->setSourcePath($values['sourcePath']);
            $row->setTargetUrl($values['targetUrl']);
            $row->setStatusCode($values['statusCode']);
            $row->setIsActive($values['isActive']);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.seo.redirects.updated'));

            return $this->redirectToRoute('admin_seo_redirects_index');
        }

        return $this->render('@SeoModule/admin/redirects/form.html.twig', [
            'redirect' => $row,
            'values' => [
                'sourcePath' => $row->getSourcePath(),
                'targetUrl' => $row->getTargetUrl(),
                'statusCode' => $row->getStatusCode(),
                'isActive' => $row->isActive(),
            ],
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $this->assertCsrf($request);
        $row = $this->findOrFail($id);
        $this->entityManager->remove($row);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('studio.seo.redirects.deleted'));

        return $this->redirectToRoute('admin_seo_redirects_index');
    }

    /**
     * @return array{sourcePath: string, targetUrl: string, statusCode: int, isActive: bool}
     */
    private function blankValues(): array
    {
        return [
            'sourcePath' => '',
            'targetUrl' => '',
            'statusCode' => SeoRedirect::STATUS_PERMANENT,
            'isActive' => true,
        ];
    }

    /**
     * @return array{sourcePath: string, targetUrl: string, statusCode: int, isActive: bool}
     */
    private function readForm(Request $request): array
    {
        return [
            'sourcePath' => (string) $request->request->get('source_path', ''),
            'targetUrl' => (string) $request->request->get('target_url', ''),
            'statusCode' => SeoRedirectPath::statusCode((string) $request->request->get('status_code', '301')),
            'isActive' => $request->request->get('is_active') !== null,
        ];
    }

    /**
     * @param array{sourcePath: string, targetUrl: string, statusCode: int, isActive: bool} $values
     */
    private function validate(array &$values, ?int $exceptId): ?string
    {
        $source = SeoRedirectPath::normalizeSource($values['sourcePath']);
        $target = SeoRedirectPath::normalizeTarget($values['targetUrl']);

        if ($source === null) {
            return $this->translator->trans('studio.seo.redirects.error.source');
        }

        if ($target === null) {
            return $this->translator->trans('studio.seo.redirects.error.target');
        }

        if (ltrim($target, '/') === $source) {
            return $this->translator->trans('studio.seo.redirects.error.loop');
        }

        if ($this->redirects->sourceTaken($source, $exceptId)) {
            return $this->translator->trans('studio.seo.redirects.error.taken');
        }

        $values['sourcePath'] = $source;
        $values['targetUrl'] = $target;

        return null;
    }

    private function findOrFail(int $id): SeoRedirect
    {
        $row = $this->redirects->find($id);
        if (!$row instanceof SeoRedirect) {
            throw new NotFoundHttpException($this->translator->trans('studio.seo.redirects.not_found'));
        }

        return $row;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}

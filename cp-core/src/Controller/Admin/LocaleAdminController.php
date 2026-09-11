<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationManager;
use App\Core\Localization\TranslationManagerException;
use App\Entity\Locale;
use App\Repository\LocaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Locale form/AJAX endpoints; list UI lives on advanced management page.
 * No delete action — nodes reference locale strings; deactivation is enough.
 */
final class LocaleAdminController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly LocaleRepository $localeRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationManager $translationManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/advanced/locales/create', name: 'aacp_locales_create', methods: ['GET', 'POST'])]
    #[IsGranted('system.settings.manage')]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $code = strtolower(trim((string) $request->request->get('code')));
            $name = trim((string) $request->request->get('name'));
            $nativeName = trim((string) $request->request->get('native_name'));

            $error = $this->validate($code, $name, $nativeName);
            if ($error !== null) {
                return $this->render('aacp/locales/form.html.twig', [
                    'locale' => null,
                    'formValues' => ['code' => $code, 'name' => $name, 'nativeName' => $nativeName],
                    'error' => $error,
                ]);
            }

            $locale = new Locale($code, $name, $nativeName);
            $locale->setSortOrder(\count($this->localeRepository->findAll()));

            $this->entityManager->persist($locale);
            $this->entityManager->flush();
            $this->localeProvider->invalidate();

            try {
                $this->translationManager->seedLocale($code);
            } catch (TranslationManagerException) {
                // Locale row persists even if seed fails; files appear on first edit.
            }

            return new Response('', Response::HTTP_FOUND, ['Location' => '/aacp/advanced/management']);
        }

        return $this->render('aacp/locales/form.html.twig', [
            'locale' => null,
            'formValues' => ['code' => '', 'name' => '', 'nativeName' => ''],
            'error' => null,
        ]);
    }

    #[Route('/aacp/advanced/locales/{id}/edit', name: 'aacp_locales_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.settings.manage')]
    public function edit(int $id, Request $request): Response
    {
        $locale = $this->findLocaleOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $code = strtolower(trim((string) $request->request->get('code')));
            $name = trim((string) $request->request->get('name'));
            $nativeName = trim((string) $request->request->get('native_name'));

            $error = $this->validate($code, $name, $nativeName, $locale->getId());
            if ($error !== null) {
                return $this->render('aacp/locales/form.html.twig', [
                    'locale' => $locale,
                    'formValues' => ['code' => $code, 'name' => $name, 'nativeName' => $nativeName],
                    'error' => $error,
                ]);
            }

            $locale->setCode($code);
            $locale->setName($name);
            $locale->setNativeName($nativeName);

            $this->entityManager->flush();
            $this->localeProvider->invalidate();

            return new Response('', Response::HTTP_FOUND, ['Location' => '/aacp/advanced/management']);
        }

        return $this->render('aacp/locales/form.html.twig', [
            'locale' => $locale,
            'formValues' => ['code' => $locale->getCode(), 'name' => $locale->getName(), 'nativeName' => $locale->getNativeName()],
            'error' => null,
        ]);
    }

    /**
     * Clears isDefault on all rows in a transaction, then sets the target default.
     */
    #[Route('/aacp/advanced/locales/{id}/set-default', name: 'aacp_locales_set_default', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.settings.manage')]
    public function setDefault(int $id, Request $request): JsonResponse
    {
        $this->assertValidCsrfAjax($request);
        $locale = $this->findLocaleOrFail($id);

        $this->entityManager->wrapInTransaction(function () use ($locale): void {
            foreach ($this->localeRepository->findAll() as $existing) {
                $existing->setIsDefault(false);
            }
            $locale->setIsDefault(true);
            $this->entityManager->flush();
        });

        $this->localeProvider->invalidate();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/aacp/advanced/locales/{id}/toggle-active', name: 'aacp_locales_toggle_active', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.settings.manage')]
    public function toggleActive(int $id, Request $request): JsonResponse
    {
        $this->assertValidCsrfAjax($request);
        $locale = $this->findLocaleOrFail($id);

        if ($locale->isDefault() && $locale->isActive()) {
            return new JsonResponse(['error' => $this->translator->trans('aacp.locales.default_cannot_deactivate')], Response::HTTP_BAD_REQUEST);
        }

        $locale->setIsActive(!$locale->isActive());
        $this->entityManager->flush();
        $this->localeProvider->invalidate();

        return new JsonResponse(['success' => true, 'isActive' => $locale->isActive()]);
    }

    private function validate(string $code, string $name, string $nativeName, ?int $excludeId = null): ?string
    {
        if ($code === '' || $name === '' || $nativeName === '') {
            return $this->translator->trans('aacp.locales.validation.required');
        }

        if (preg_match('/^[a-z]{2,5}$/', $code) !== 1) {
            return $this->translator->trans('aacp.locales.validation.invalid_code');
        }

        if ($this->localeRepository->codeExists($code, $excludeId)) {
            return $this->translator->trans('aacp.locales.validation.code_taken', ['code' => $code]);
        }

        return null;
    }

    private function findLocaleOrFail(int $id): Locale
    {
        $locale = $this->localeRepository->find($id);
        if (!$locale instanceof Locale) {
            throw new NotFoundHttpException($this->translator->trans('aacp.locales.not_found'));
        }

        return $locale;
    }

    private function render(string $template, array $context): Response
    {
        return new Response($this->twig->render($template, $context + [
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_locales')->getValue(),
        ]));
    }

    private function assertValidCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_locales', $submitted))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.locales.invalid_csrf'));
        }
    }

    private function assertValidCsrfAjax(Request $request): void
    {
        $submitted = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token'));
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_locales', $submitted))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.locales.invalid_csrf'));
        }
    }
}

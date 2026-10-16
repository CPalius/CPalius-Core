<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Security\Http\LoginTargetPath;
use App\Core\Security\Reauth\RecentAuth;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * "Confirm it is you" before a sensitive panel action (see RequiresRecentAuth).
 */
final class AACPReauthController
{
    private const CSRF_TOKEN_ID = 'aacp_reauth';

    public function __construct(
        private readonly Environment $twig,
        private readonly RecentAuth $recentAuth,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(RecentAuth::CONFIRM_PATH, name: 'aacp_reauth', methods: ['GET', 'POST'])]
    #[IsGranted('system.aacp.access')]
    public function confirm(Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $next = $this->safeNext((string) $request->query->get('next', ''));
        if ($this->recentAuth->isFresh($request->getSession())) {
            return new RedirectResponse($next);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token')))) {
                throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
            }
            $next = $this->safeNext((string) $request->request->get('next', $next));

            if ($this->recentAuth->confirm($user, (string) $request->request->get('password', ''), trim((string) $request->request->get('code', '')), $request)) {
                return new RedirectResponse($next);
            }
            $error = $this->translator->trans($this->recentAuth->isLocked($user) ? 'aacp.reauth.locked' : 'aacp.reauth.wrong');
        }

        $response = new Response($this->twig->render('aacp/reauth.html.twig', [
            'error' => $error,
            'next' => $next,
            'second_factor' => $this->recentAuth->needsSecondFactor($user),
            'csrf_token' => $this->csrf->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]), $this->recentAuth->isLocked($user) ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Only a relative /aacp path, never the confirmation page itself and never a protocol-relative URL. */
    private function safeNext(string $candidate): string
    {
        if ($candidate === '' || !str_starts_with($candidate, '/aacp') || str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return '/aacp';
        }
        if (!LoginTargetPath::isNavigable($candidate) || str_starts_with($candidate, RecentAuth::CONFIRM_PATH)) {
            return '/aacp';
        }

        return $candidate;
    }
}

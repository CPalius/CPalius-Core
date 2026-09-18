<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Security\Gate\AacpGate;
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
 * The panel gate question.
 *
 * Exempt from AacpGateSubscriber, so it re-checks the session state itself
 * instead of trusting that the guard already did.
 */
final class AACPGateController
{
    private const CSRF_TOKEN_ID = 'aacp_gate';

    public function __construct(
        private readonly Environment $twig,
        private readonly AacpGate $gate,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(AacpGate::GATE_PATH, name: 'aacp_gate', methods: ['GET', 'POST'])]
    #[IsGranted('system.aacp.access')]
    public function gate(Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $next = $this->safeNext((string) $request->query->get('next', ''));

        // Nothing to ask: an inactive gate must never strand an operator on a
        // page whose only button cannot let them through.
        if (!$this->gate->isActive()) {
            return new RedirectResponse($next);
        }

        $session = $request->getSession();
        if ($this->gate->isPassed($session)) {
            return new RedirectResponse($next);
        }

        $error = null;
        $lockedUntil = $this->gate->lockedUntil($user);

        if ($request->isMethod('POST') && $lockedUntil === null) {
            $this->assertCsrf($request);

            $next = $this->safeNext((string) $request->request->get('next', $next));

            if ($this->gate->verify($user, (string) $request->request->get('answer', ''), $request)) {
                $this->gate->markPassed($session);

                return new RedirectResponse($next);
            }

            $lockedUntil = $this->gate->lockedUntil($user);
            $error = $lockedUntil !== null
                ? $this->translator->trans('aacp.gate.locked')
                : $this->translator->trans('aacp.gate.wrong_answer');
        }

        // Arriving while already locked out must say so, not show a form whose
        // every submission is silently ignored.
        if ($lockedUntil !== null && $error === null) {
            $error = $this->translator->trans('aacp.gate.locked');
        }

        return new Response($this->twig->render('aacp/gate.html.twig', [
            'question' => $this->gate->question(),
            'error' => $error,
            'next' => $next,
            'lockedMinutes' => $lockedUntil !== null ? (int) ceil(($lockedUntil - time()) / 60) : 0,
            'remaining' => $this->gate->remainingAttempts($user),
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]), $lockedUntil !== null ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_OK);
    }

    /**
     * Only a relative /aacp path is honoured. An attacker who can plant ?next=
     * would otherwise turn the gate into an open redirect that looks like it
     * belongs to the panel.
     */
    private function safeNext(string $candidate): string
    {
        if ($candidate === '' || !str_starts_with($candidate, '/aacp')) {
            return '/aacp';
        }

        // "//evil.host" and "/\evil.host" are both read as protocol-relative URLs
        // by browsers, and neither starts with a second slash by accident.
        if (str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return '/aacp';
        }

        return str_starts_with($candidate, AacpGate::GATE_PATH) ? '/aacp' : $candidate;
    }

    private function assertCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submitted))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }
    }
}

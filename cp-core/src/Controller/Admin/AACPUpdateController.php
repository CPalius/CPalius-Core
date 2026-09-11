<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Update\UpdateRunner;
use App\Core\Update\UpdateStepResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The browser face of cp:update (T3.6).
 *
 * The screen opens on a dry run, never on an action. An operator arriving here
 * after a deploy wants to know what is outstanding before deciding anything,
 * and a page that applied changes merely by being visited would be a page
 * nobody could safely open on production.
 *
 * Applying is a POST with a CSRF token for the same reason: an update must
 * never be reachable by following a link, from a crawler or from a mistyped
 * URL.
 */
#[Route('/aacp/updates', name: 'aacp_updates_')]
#[IsGranted('system.update.manage')]
final class AACPUpdateController extends AbstractController
{
    private const CSRF_APPLY = 'aacp_updates_apply';

    public function __construct(
        private readonly UpdateRunner $runner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.updates.menu', icon: 'heroicons:arrow-path', panel: 'aacp', priority: 36, capability: 'system.update.manage', parent: 'aacp_tools')]
    public function index(): Response
    {
        $results = $this->runner->run(dryRun: true);

        return $this->render('aacp/updates/index.html.twig', [
            'results' => $results,
            'pending' => $this->pendingCount($results),
            'hasFailure' => $this->hasFailure($results),
            'token' => $this->csrfTokenManager->getToken(self::CSRF_APPLY)->getValue(),
            'applied' => null,
        ]);
    }

    #[Route('/apply', name: 'apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_APPLY, (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $results = $this->runner->run();

        // Rendered rather than redirected: the per-step detail is the whole
        // point of having run it, and a redirect would reduce it to a flash
        // message. A re-run is safe anyway — completed work is skipped.
        return $this->render('aacp/updates/index.html.twig', [
            'results' => $this->runner->run(dryRun: true),
            'pending' => 0,
            'hasFailure' => $this->hasFailure($results),
            'token' => $this->csrfTokenManager->getToken(self::CSRF_APPLY)->getValue(),
            'applied' => $results,
        ]);
    }

    /**
     * @param list<UpdateStepResult> $results
     */
    private function pendingCount(array $results): int
    {
        $count = 0;

        foreach ($results as $result) {
            // The cache step always reports a change in a dry run (it would
            // always clear), so counting it would make an up-to-date
            // installation look like it had work waiting.
            if ($result->step === UpdateRunner::STEP_CACHE) {
                continue;
            }

            if ($result->changedAnything()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<UpdateStepResult> $results
     */
    private function hasFailure(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->isFailure()) {
                return true;
            }
        }

        return false;
    }
}

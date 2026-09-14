<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\TextFormat\Filter\MarkdownFilter;
use App\Core\TextFormat\TextFilterContext;
use App\Core\Update\UpdateRunner;
use App\Core\Update\UpdateStepResult;
use App\Core\Version\CoreUpdater;
use App\Core\Version\CpVersion;
use App\Core\Version\PatchChecker;
use App\Core\Version\PatchInstaller;
use App\Core\Version\ReleaseChecker;
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
    private const CSRF_UPGRADE = 'aacp_updates_upgrade';
    private const CSRF_PATCH = 'aacp_updates_patch';
    private const CSRF_RECOVER = 'aacp_updates_recover';

    /**
     * How stale the stored release check may be before opening this screen
     * refreshes it. Short enough that arriving here shows current information,
     * long enough that reloading the page is free.
     */
    private const CHECK_MAX_AGE = 900;

    public function __construct(
        private readonly UpdateRunner $runner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly ReleaseChecker $releases,
        private readonly CoreUpdater $updater,
        private readonly PatchChecker $patches,
        private readonly PatchInstaller $patchInstaller,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.updates.menu', icon: 'heroicons:arrow-path', panel: 'aacp', priority: 25, capability: 'system.update.manage', parent: 'aacp_hub_system')]
    public function index(): Response
    {
        // Opening the screen is the operator asking "where do I stand?", so the
        // answer is refreshed here rather than left until tomorrow's cron. The
        // staleness window keeps a reload from becoming a request to GitHub.
        $this->releases->refreshIfStale(self::CHECK_MAX_AGE);
        $this->patches->refreshIfStale(self::CHECK_MAX_AGE);

        $results = $this->runner->run(dryRun: true);

        return $this->render('aacp/updates/index.html.twig', $this->viewData([
            'results' => $results,
            'pending' => $this->pendingCount($results),
            'hasFailure' => $this->hasFailure($results),
            'applied' => null,
            'upgradeLog' => null,
            'upgradeError' => null,
            'patchLog' => null,
            'patchError' => null,
        ]));
    }

    /**
     * Applies the one pending file patch.
     *
     * A POST with a CSRF token and nothing else — no version parameter. The
     * installer decides which patch is next from the running version, so there
     * is no request field an attacker could steer, and no way for a stale form
     * left open in a tab to install something other than what the page showed.
     */
    #[Route('/patch', name: 'patch', methods: ['POST'])]
    public function patch(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_PATCH, (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $log = null;
        $error = null;

        try {
            $log = $this->patchInstaller->apply();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $results = $this->runner->run(dryRun: true);

        return $this->render('aacp/updates/index.html.twig', $this->viewData([
            'results' => $results,
            'pending' => $this->pendingCount($results),
            'hasFailure' => $this->hasFailure($results),
            'applied' => null,
            'upgradeLog' => null,
            'upgradeError' => null,
            'patchLog' => $log,
            'patchError' => $error,
        ]));
    }

    /**
     * Finishes or undoes an update that never reported back.
     *
     * One route with an action field rather than two, because the two are the
     * same decision seen from opposite ends and an operator choosing between
     * them is choosing once. The field is validated against a closed list —
     * anything else is a bad request, not a default.
     */
    #[Route('/recover', name: 'recover', methods: ['POST'])]
    public function recover(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_RECOVER, (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $action = (string) $request->request->get('action');

        if (!\in_array($action, ['resume', 'rollback'], true)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.version.recovery.unknown_action'));
        }

        $log = null;
        $error = null;

        try {
            $log = $action === 'resume' ? $this->updater->resume() : $this->updater->rollback();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $results = $this->runner->run(dryRun: true);

        return $this->render('aacp/updates/index.html.twig', $this->viewData([
            'results' => $results,
            'pending' => $this->pendingCount($results),
            'hasFailure' => $this->hasFailure($results),
            'applied' => null,
            'upgradeLog' => $log,
            'upgradeError' => $error,
            'patchLog' => null,
            'patchError' => null,
        ]));
    }

    #[Route('/upgrade', name: 'upgrade', methods: ['POST'])]
    public function upgrade(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_UPGRADE, (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $log = null;
        $error = null;

        try {
            $log = $this->updater->apply();

            // The new files are on disk but this process is still running the
            // old ones. Migrations and update hooks are the second half of the
            // job and belong to the code that just landed, so they run on the
            // next request via the existing apply step rather than here.
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $results = $this->runner->run(dryRun: true);

        return $this->render('aacp/updates/index.html.twig', $this->viewData([
            'results' => $results,
            'pending' => $this->pendingCount($results),
            'hasFailure' => $this->hasFailure($results),
            'applied' => null,
            'upgradeLog' => $log,
            'upgradeError' => $error,
            'patchLog' => null,
            'patchError' => null,
        ]));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function viewData(array $extra): array
    {
        $status = $this->releases->status();

        // The manifest is fetched so the screen can list the exact files a patch
        // would replace. A patch nobody can inspect before agreeing to it is a
        // patch nobody should be asked to agree to.
        //
        // Failure is caught rather than propagated: a malformed or unreachable
        // manifest must render as "this patch cannot be offered, here is why",
        // not as a 500 on the one screen an operator opens when something is
        // already wrong.
        $patch = null;
        $patchPlanError = null;

        try {
            $patch = $this->patchInstaller->plan();
        } catch (\Throwable $e) {
            $patchPlanError = $e->getMessage();
        }

        return array_merge([
            'token' => $this->csrfTokenManager->getToken(self::CSRF_APPLY)->getValue(),
            'upgradeToken' => $this->csrfTokenManager->getToken(self::CSRF_UPGRADE)->getValue(),
            'patchToken' => $this->csrfTokenManager->getToken(self::CSRF_PATCH)->getValue(),
            'recoverToken' => $this->csrfTokenManager->getToken(self::CSRF_RECOVER)->getValue(),
            'interrupted' => $this->updater->interrupted(),
            'currentVersion' => CpVersion::VERSION,
            'currentReleasedAt' => CpVersion::RELEASED_AT,
            'channel' => CpVersion::CHANNEL,
            'release' => $status,
            'blockers' => $this->updater->blockers(),
            'currentNotes' => $this->renderNotes(CpVersion::VERSION),
            'latestNotes' => $status !== null && $status['outdated'] ? $this->renderNotes($status['version']) : null,
            'patch' => $patch,
            'patchPlanError' => $patchPlanError,
            'patchBlockers' => $this->patchInstaller->blockers(),
            'patchQueued' => $this->patches->queued(),
            'patchCheckedAt' => $this->patches->checkedAt(),
            'patchApplied' => $this->patchInstaller->appliedLedger(),
        ], $extra);
    }

    /**
     * Release notes as HTML, or null when they cannot be fetched.
     *
     * MarkdownFilter escapes the input before converting, so prose fetched from
     * the release feed cannot introduce markup into the admin panel even if the
     * feed is compromised.
     */
    private function renderNotes(string $version): ?string
    {
        $markdown = $this->releases->fetchNotes($version);

        if ($markdown === null) {
            return null;
        }

        return (new MarkdownFilter())->process(
            $markdown,
            new TextFilterContext('markdown', TextFilterContext::PHASE_OUTPUT),
        );
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
        return $this->render('aacp/updates/index.html.twig', $this->viewData([
            'results' => $this->runner->run(dryRun: true),
            'pending' => 0,
            'hasFailure' => $this->hasFailure($results),
            'applied' => $results,
            'upgradeLog' => null,
            'upgradeError' => null,
            'patchLog' => null,
            'patchError' => null,
        ]));
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

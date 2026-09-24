<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Cache\CacheRebuildManager;
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
use Symfony\Component\HttpFoundation\RequestStack;
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
    private const CSRF_CHECK = 'aacp_updates_check';

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
        private readonly RequestStack $requestStack,
        private readonly CacheRebuildManager $cacheRebuild,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.updates.menu', icon: 'heroicons:arrow-path', panel: 'aacp', priority: 25, capability: 'system.update.manage', parent: 'aacp_hub_system')]
    public function index(Request $request): Response
    {
        // Opening the screen is the operator asking "where do I stand?", so the
        // answer is refreshed here rather than left until tomorrow's cron. The
        // staleness window keeps a reload from becoming a request to GitHub.
        $this->releases->refreshIfStale(self::CHECK_MAX_AGE);
        $this->patches->refreshIfStale(self::CHECK_MAX_AGE);

        $results = $this->runner->run(dryRun: true);

        $flashed = $request->getSession()->getFlashBag()->get('patch_log');
        $checkLog = $request->getSession()->getFlashBag()->get('check_log');
        $checkError = $request->getSession()->getFlashBag()->get('check_error');

        return $this->render('aacp/updates/index.html.twig', $this->viewData([
            'results' => $results,
            'pending' => $this->pendingCount($results),
            'hasFailure' => $this->hasFailure($results),
            'applied' => null,
            'upgradeLog' => null,
            'upgradeError' => null,
            'patchLog' => $flashed !== [] ? $flashed : null,
            'patchError' => null,
            'checkLog' => $checkLog[0] ?? null,
            'checkError' => $checkError[0] ?? null,
        ]));
    }

    /**
     * Asks the release feed now, ignoring the staleness window.
     *
     * Opening the screen refreshes only when the stored answer is old. An
     * operator who just published a release, or who landed here inside that
     * window, needs a way to ask again without waiting.
     */
    #[Route('/check', name: 'check', methods: ['POST'])]
    public function check(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_CHECK, (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $line = $this->releases->refresh();
        $this->patches->refresh();

        if (str_starts_with($line, 'Release check failed')) {
            $this->addFlash('check_error', $this->translator->trans('aacp.version.check.failed', [
                'reason' => $line,
            ]));
        } else {
            $status = $this->releases->status();
            if ($status !== null && $status['outdated']) {
                $this->addFlash('check_log', $this->translator->trans('aacp.version.check.found', [
                    'version' => $status['version'],
                ]));
            } else {
                $this->addFlash('check_log', $this->translator->trans('aacp.version.check.current', [
                    'version' => CpVersion::VERSION,
                ]));
            }
        }

        return $this->redirectToRoute('aacp_updates_index');
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

        try {
            $log = $this->patchInstaller->apply();
        } catch (\Throwable $e) {
            // The installer restored the previous files before throwing, so the
            // tree matches the compiled container again and it is safe to render
            // the full screen with the reason on it.
            $results = $this->runner->run(dryRun: true);

            return $this->render('aacp/updates/index.html.twig', $this->viewData([
                'results' => $results,
                'pending' => $this->pendingCount($results),
                'hasFailure' => $this->hasFailure($results),
                'applied' => null,
                'upgradeLog' => null,
                'upgradeError' => null,
                'patchLog' => null,
                'patchError' => $e->getMessage(),
            ]));
        }

        /*
         * Success is a REDIRECT and nothing else, deliberately.
         *
         * The moment apply() returns, the files on disk are the new version and
         * the compiled container still describes the old one — the kernel cache
         * purge is deferred to shutdown so that this request does not pull the
         * container out from under itself. Everything executed between here and
         * that shutdown therefore runs NEW class files through an OLD factory.
         *
         * This used to run UpdateRunner and render the whole updates screen in
         * that window. 1.1.2 changed a service constructor from six arguments to
         * seven and the screen died with ArgumentCountError, because the cached
         * factory still passed six — with the patch already applied, so a retry
         * could not help either.
         *
         * A RedirectResponse needs the router and nothing else, both long since
         * instantiated. The purge then runs at shutdown and the GET that follows
         * is served by a container rebuilt from the new code.
         */
        foreach ($log as $line) {
            $this->addFlash('patch_log', $line);
        }

        return $this->redirectToRoute('aacp_updates_index');
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
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        /*
         * Schema first, in this request, before anything renders.
         *
         * The old shape of this method left migrations to "the next request via
         * the existing apply step" and then rendered the updates screen. That
         * screen is served by the code that just landed, against a database that
         * has not moved yet. It held until 2.0.0, which renames every table:
         * the render died with "Table 'cp_users' doesn't exist", and because the
         * panel was what died, the operator could never reach the button that
         * would have run the migrations. The site was down with no way back in.
         *
         * Migrations are the one step that survives the window. They need the
         * DBAL connection and the migration files on disk; neither depends on
         * the compiled container that is about to be thrown away. Update hooks,
         * module upgrades and the cache rebuild still wait for the next request,
         * because those DO run project services through the old factory.
         *
         * A failure here is reported and not retried: the files are already the
         * new version, so the honest thing is to say the schema did not move and
         * let the operator run it from the apply step once they know why.
         */
        $migrationError = null;
        if ($error === null) {
            try {
                $result = $this->runner->migrateOnly();
                if ($result->isFailure()) {
                    $migrationError = $result->summary;
                }
            } catch (\Throwable $e) {
                $migrationError = $e->getMessage();
            }
        }

        /*
         * Redirect rather than render, for the same reason patch() does since
         * 1.1.3: a RedirectResponse needs the router and nothing else, both long
         * since instantiated, while rendering this screen walks half the service
         * graph through a container that no longer matches the code on disk.
         */
        foreach ((array) $log as $line) {
            $this->addFlash('upgrade_log', (string) $line);
        }
        if ($error !== null) {
            $this->addFlash('upgrade_error', $error);
        }
        if ($migrationError !== null) {
            $this->addFlash('upgrade_error', $this->translator->trans('aacp.version.upgrade.migration_failed', ['error' => $migrationError]));
        }

        return $this->redirectToRoute('aacp_updates_index');
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
            'checkToken' => $this->csrfTokenManager->getToken(self::CSRF_CHECK)->getValue(),
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
            // Set only when the deferred purge after the last patch failed. The
            // site is then running new files through an old container, which is
            // the one state an operator must not have to guess at.
            'cachePurgeFailure' => $this->cacheRebuild->lastPurgeFailure(),
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
        // The panel renders in the operator's locale, so the notes should too.
        // ReleaseChecker asks for "<version>-<locale>.md" and falls back to the
        // English default when a release was never translated.
        $markdown = $this->releases->fetchNotes(
            $version,
            $this->requestStack->getCurrentRequest()?->getLocale(),
        );

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

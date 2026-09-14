<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Module\ModuleActivator;
use App\Core\Module\ModulePackageContract;
use App\Core\Module\ModulePackageService;
use App\Core\Module\ModuleRegistry;
use App\Core\Module\ModuleTranslationContract;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
 * WordPress-style module packages: list, ZIP install, activate, deactivate, edit, delete.
 */
final class AACPModuleController
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ModuleActivator $moduleActivator,
        private readonly ModulePackageService $modulePackageService,
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly string $projectDir,
    ) {
    }

    #[Route('/aacp/modules', name: 'aacp_modules', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.modules', icon: 'heroicons:puzzle-piece', panel: 'aacp', priority: 21, capability: 'system.module.manage', parent: 'aacp_hub_system')]
    #[IsGranted('system.module.manage')]
    public function index(Request $request): Response
    {
        $html = $this->twig->render('aacp/modules.html.twig', [
            'modules' => $this->moduleRegistry->discoverAllModules(),
            'csrf_token' => $this->csrfToken($request),
            'notice' => (string) $request->query->get('notice', ''),
            'error' => (string) $request->query->get('error', ''),
            'problems' => $this->pullProblems($request),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/modules/upload', name: 'aacp_module_upload', methods: ['POST'])]
    #[IsGranted('system.module.manage')]
    public function upload(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $file = $request->files->get('package');
        if (!$file instanceof UploadedFile) {
            return $this->back($request, error: $this->translator->trans('aacp.modules.upload.missing'));
        }

        $overwrite = $request->request->getBoolean('overwrite');
        $activate = $request->request->getBoolean('activate');
        $result = $this->modulePackageService->installFromUpload($file, $overwrite);

        if (!$result['success']) {
            $this->stashProblems($request, $result['problems'] ?? []);

            return $this->back($request, error: $result['message']);
        }

        $dirName = (string) $result['dirName'];
        if ($activate && $dirName !== '') {
            $activation = $this->moduleActivator->activate($dirName);
            if (!$activation['success']) {
                $this->stashProblems($request, $activation['problems'] ?? []);

                return $this->back($request, error: $activation['message'], notice: 'uploaded');
            }

            return $this->back($request, notice: 'uploaded_activated');
        }

        return $this->back($request, notice: 'uploaded');
    }

    #[Route('/aacp/modules/{dirName}/activate', name: 'aacp_module_activate', methods: ['POST'])]
    #[IsGranted('system.module.manage')]
    public function activate(string $dirName, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $result = $this->moduleActivator->activate($dirName);
        if (!$result['success']) {
            $this->stashProblems($request, $result['problems'] ?? []);

            return $this->back($request, error: $result['message']);
        }

        return $this->back($request, notice: 'activated');
    }

    #[Route('/aacp/modules/{dirName}/deactivate', name: 'aacp_module_deactivate', methods: ['POST'])]
    #[IsGranted('system.module.manage')]
    public function deactivate(string $dirName, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $result = $this->moduleActivator->deactivate($dirName, $request->request->getBoolean('purge'));
        if (!$result['success']) {
            $this->stashProblems($request, $result['problems'] ?? []);

            return $this->back($request, error: $result['message']);
        }

        return $this->back($request, notice: $request->request->getBoolean('purge') ? 'purged' : 'deactivated');
    }

    #[Route('/aacp/modules/{dirName}', name: 'aacp_module_show', methods: ['GET'])]
    #[IsGranted('system.module.manage')]
    public function show(string $dirName, Request $request): Response
    {
        $module = $this->findModule($dirName);
        $moduleDir = $this->projectDir.'/cp-content/modules/'.$dirName;
        $contractProblems = is_dir($moduleDir) ? ModulePackageContract::problems($moduleDir) : ['Module directory is missing.'];

        $html = $this->twig->render('aacp/module_edit.html.twig', [
            'module' => $module,
            'csrf_token' => $this->csrfToken($request),
            'contractProblems' => $contractProblems,
            'hasEnglish' => is_file(ModuleTranslationContract::englishCataloguePath($moduleDir)),
            'hasInstaller' => is_file(ModuleTranslationContract::installerPath($moduleDir)),
            'notice' => (string) $request->query->get('notice', ''),
            'error' => (string) $request->query->get('error', ''),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/modules/{dirName}/update', name: 'aacp_module_update', methods: ['POST'])]
    #[IsGranted('system.module.manage')]
    public function update(string $dirName, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $this->findModule($dirName);

        $result = $this->modulePackageService->updateManifest($dirName, [
            'description' => trim((string) $request->request->get('description', '')),
            'author' => trim((string) $request->request->get('author', '')),
        ]);

        $target = '/aacp/modules/'.$dirName;
        if (!$result['success']) {
            return new RedirectResponse($target.'?error='.rawurlencode($result['message']));
        }

        return new RedirectResponse($target.'?notice=updated');
    }

    #[Route('/aacp/modules/{dirName}/delete', name: 'aacp_module_delete', methods: ['POST'])]
    #[IsGranted('system.module.manage')]
    public function delete(string $dirName, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $result = $this->modulePackageService->delete($dirName, $request->request->getBoolean('purge'));
        if (!$result['success']) {
            $this->stashProblems($request, $result['problems'] ?? []);

            return $this->back($request, error: $result['message']);
        }

        return $this->back($request, notice: 'deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function findModule(string $dirName): array
    {
        foreach ($this->moduleRegistry->discoverAllModules() as $module) {
            if (strcasecmp((string) $module['dirName'], $dirName) === 0) {
                return $module;
            }
        }

        throw new NotFoundHttpException($this->translator->trans('aacp.modules.not_found'));
    }

    private function assertCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_module_deactivate', $submitted))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }
    }

    private function csrfToken(Request $request): string
    {
        return $this->csrfTokenManager->getToken('aacp_module_deactivate')->getValue();
    }

    /**
     * @param list<string> $problems
     */
    private function stashProblems(Request $request, array $problems): void
    {
        if ($problems === [] || !$request->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->set('aacp_module_problems', $problems);
    }

    /**
     * @return list<string>
     */
    private function pullProblems(Request $request): array
    {
        if (!$request->hasSession()) {
            return [];
        }

        $raw = $request->getSession()->getFlashBag()->get('aacp_module_problems');
        $out = [];
        foreach ($raw as $item) {
            if (\is_array($item)) {
                foreach ($item as $line) {
                    if (\is_string($line) && $line !== '') {
                        $out[] = $line;
                    }
                }
                continue;
            }
            if (\is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function back(Request $request, string $notice = '', string $error = ''): RedirectResponse
    {
        $redirectTo = (string) $request->request->get('_redirect', '/aacp/modules');
        $target = str_starts_with($redirectTo, '/aacp') ? $redirectTo : '/aacp/modules';
        $query = [];
        if ($notice !== '') {
            $query['notice'] = $notice;
        }
        if ($error !== '') {
            $query['error'] = $error;
        }

        if ($query === []) {
            return new RedirectResponse($target);
        }

        $separator = str_contains($target, '?') ? '&' : '?';

        return new RedirectResponse($target.$separator.http_build_query($query));
    }
}

<?php

declare(strict_types=1);

namespace Modules\DnsTools\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use Modules\DnsTools\Catalog\ToolCatalog;
use Modules\DnsTools\Catalog\ToolDefinition;
use Modules\DnsTools\Service\ForumBridge;
use Modules\DnsTools\Service\ToolConfigStore;
use Modules\DnsTools\Service\ToolRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/dnstools/tools', name: 'admin_dnstools_tools_')]
#[IsGranted('dnstools.manage')]
final class DnsToolsToolsAdminController extends AbstractController
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly ToolRegistry $registry,
        private readonly ToolConfigStore $config,
        private readonly LocaleProvider $locales,
        private readonly ForumBridge $forum,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'dnstools.menu.tools',
        icon: 'heroicons:wrench-screwdriver',
        panel: 'studio',
        priority: 41,
        capability: 'dnstools.manage',
        group: 'studio.group.content',
        parent: 'admin_dnstools_index',
    )]
    public function index(): Response
    {
        $rows = [];
        foreach ($this->catalog->all() as $tool) {
            $rows[] = $this->registry->present($tool);
        }

        return $this->render('@DnsToolsModule/admin/tools/index.html.twig', [
            'tools' => $rows,
            'categories' => $this->catalog->categories(),
        ]);
    }

    #[Route('/pages', name: 'pages', methods: ['GET', 'POST'], priority: 5)]
    public function pages(Request $request): Response
    {
        $localeCodes = array_map(static fn ($locale) => $locale->code, $this->locales->getLocales());
        if ($localeCodes === []) {
            $localeCodes = ['tr', 'en'];
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_dnstools_pages', (string) $request->request->get('_token'))) {
                throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
            }
            $this->config->savePages($this->sanitizePages($request->request->all('pages'), $localeCodes));
            $this->config->saveForum(
                $request->request->getBoolean('forum_enabled'),
                trim((string) $request->request->get('forum_section')),
            );
            $this->addFlash('success', $this->translator->trans('dnstools.settings.saved'));

            return $this->redirectToRoute('admin_dnstools_tools_pages');
        }

        $pages = [];
        foreach (['index' => 'dnstools.seo.index', 'all' => 'dnstools.seo.all'] as $page => $prefix) {
            foreach ($localeCodes as $code) {
                $pages[$page][$code] = $this->registry->pageCopy($page, $prefix, $code);
            }
        }

        return $this->render('@DnsToolsModule/admin/tools/pages.html.twig', [
            'pages' => $pages,
            'locales' => $this->locales->getLocales(),
            'forumEnabled' => $this->config->forumEnabled(),
            'forumSection' => $this->config->forumSection(),
            'boards' => $this->forum->boards($this->locales->getDefaultCode()),
        ]);
    }

    #[Route('/{slug}', name: 'edit', methods: ['GET', 'POST'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function edit(string $slug, Request $request): Response
    {
        $tool = $this->catalog->get($slug);
        if (!$tool instanceof ToolDefinition) {
            throw $this->createNotFoundException();
        }

        $row = $this->config->tool($slug, $tool);
        $localeCodes = array_map(static fn ($locale) => $locale->code, $this->locales->getLocales());
        if ($localeCodes === []) {
            $localeCodes = ['tr', 'en'];
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_dnstools_tool_'.$slug, (string) $request->request->get('_token'))) {
                throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
            }

            $locales = [];
            foreach ($localeCodes as $code) {
                $submitted = $request->request->all('locales')[$code] ?? [];
                if (!\is_array($submitted)) {
                    continue;
                }
                $locales[$code] = [
                    'title' => mb_substr(trim((string) ($submitted['title'] ?? '')), 0, 160),
                    'subtitle' => mb_substr(trim((string) ($submitted['subtitle'] ?? '')), 0, 220),
                    'description' => mb_substr(trim((string) ($submitted['description'] ?? '')), 0, 400),
                    'keywords' => mb_substr(trim((string) ($submitted['keywords'] ?? '')), 0, 250),
                ];
            }

            $this->config->saveTool($slug, [
                'enabled' => $request->request->getBoolean('enabled'),
                'featured' => $request->request->getBoolean('featured'),
                'is_new' => $request->request->getBoolean('is_new'),
                'locales' => $locales,
            ]);
            $this->addFlash('success', $this->translator->trans('dnstools.tools.saved'));

            return $this->redirectToRoute('admin_dnstools_tools_edit', ['slug' => $slug]);
        }

        $presented = [];
        foreach ($localeCodes as $code) {
            $presented[$code] = $this->registry->present($tool, $code);
        }

        return $this->render('@DnsToolsModule/admin/tools/edit.html.twig', [
            'tool' => $tool,
            'row' => $row,
            'presented' => $presented,
            'locales' => $this->locales->getLocales(),
        ]);
    }

    #[Route('/{slug}/toggle', name: 'toggle', methods: ['POST'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function toggle(string $slug, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_dnstools_toggle_'.$slug, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $tool = $this->catalog->get($slug);
        if (!$tool instanceof ToolDefinition) {
            throw $this->createNotFoundException();
        }

        $row = $this->config->tool($slug, $tool);
        $row['enabled'] = !$row['enabled'];
        $this->config->saveTool($slug, $row);
        $this->addFlash('success', $this->translator->trans('dnstools.tools.saved'));

        return $this->redirectToRoute('admin_dnstools_tools_index');
    }

    /**
     * @param array<string, mixed> $pages
     * @param list<string>         $localeCodes
     * @return array<string, mixed>
     */
    private function sanitizePages(array $pages, array $localeCodes): array
    {
        $clean = [];
        foreach (['index', 'all'] as $page) {
            foreach ($localeCodes as $code) {
                $row = \is_array($pages[$page][$code] ?? null) ? $pages[$page][$code] : [];
                $clean[$page][$code] = [
                    'title' => mb_substr(trim((string) ($row['title'] ?? '')), 0, 160),
                    'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 400),
                    'keywords' => mb_substr(trim((string) ($row['keywords'] ?? '')), 0, 250),
                ];
            }
        }

        return $clean;
    }
}

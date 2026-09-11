<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Hook\HookManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * Phase 7A read-only hook explorer (flat-file and attribute hooks).
 * Plain Twig controller, same convention as AACPCronController.
 */
final class AACPHookController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly HookManager $hookManager,
    ) {
    }

    #[Route('/aacp/hooks', name: 'aacp_hooks', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.hooks', icon: 'heroicons:bolt', panel: 'aacp', priority: 23, capability: 'system.hooks.manage', parent: 'aacp_tools')]
    #[IsGranted('system.hooks.manage')]
    public function index(): Response
    {
        $hooks = $this->hookManager->discoverAll();

        $html = $this->twig->render('aacp/hooks/index.html.twig', [
            'hooks' => $hooks,
            'flatFileCount' => count(array_filter($hooks, static fn (array $h): bool => $h['type'] === 'flat-file')),
            'attributeCount' => count(array_filter($hooks, static fn (array $h): bool => $h['type'] === 'attribute')),
        ]);

        return new Response($html);
    }
}

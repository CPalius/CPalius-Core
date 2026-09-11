<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sidebar hub for AACP ops tools. The label only expands the submenu; this route is a fallback.
 */
final class AACPToolsController
{
    #[Route('/aacp/tools', name: 'aacp_tools', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'aacp.menu.tools',
        icon: 'heroicons:wrench-screwdriver',
        panel: 'aacp',
        priority: 20,
        capability: 'system.aacp.access',
        group: 'aacp.group.system',
    )]
    #[IsGranted('system.aacp.access')]
    public function index(): RedirectResponse
    {
        return new RedirectResponse('/aacp');
    }
}

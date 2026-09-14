<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sidebar folder hubs. Each route only expands the submenu; the path is a fallback redirect.
 */
final class AACPToolsController
{
    #[Route('/aacp/tools', name: 'aacp_tools', methods: ['GET'])]
    #[IsGranted('system.aacp.access')]
    public function index(): RedirectResponse
    {
        return new RedirectResponse('/aacp');
    }

    #[Route('/aacp/system', name: 'aacp_hub_system', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.hub.system', icon: 'heroicons:cpu-chip', panel: 'aacp', priority: 20, capability: 'system.aacp.access')]
    #[IsGranted('system.aacp.access')]
    public function system(): RedirectResponse
    {
        return new RedirectResponse('/aacp');
    }

    #[Route('/aacp/structure', name: 'aacp_hub_structure', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.hub.structure', icon: 'heroicons:squares-2x2', panel: 'aacp', priority: 60, capability: 'system.aacp.access')]
    #[IsGranted('system.aacp.access')]
    public function structure(): RedirectResponse
    {
        return new RedirectResponse('/aacp');
    }

    #[Route('/aacp/automation', name: 'aacp_hub_automation', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.hub.automation', icon: 'heroicons:bolt', panel: 'aacp', priority: 70, capability: 'system.aacp.access')]
    #[IsGranted('system.aacp.access')]
    public function automation(): RedirectResponse
    {
        return new RedirectResponse('/aacp');
    }

    #[Route('/aacp/maintenance', name: 'aacp_hub_maintenance', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.hub.maintenance', icon: 'heroicons:wrench-screwdriver', panel: 'aacp', priority: 80, capability: 'system.aacp.access')]
    #[IsGranted('system.aacp.access')]
    public function maintenance(): RedirectResponse
    {
        return new RedirectResponse('/aacp');
    }
}

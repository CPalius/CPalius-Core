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
 * Faz 7A: AACP "Kancalar & Hook Gezgini" — sistemde o an kayıtlı olan
 * flat-file (Cotonti tarzı) ve attribute (Symfony tarzı) TÜM hook
 * noktalarını tek bir salt-okunur, canlı tabloda listeler.
 *
 * AACPCronController ile aynı iskelet (plain class + inject edilen
 * Twig\Environment, AbstractController KULLANILMAZ) — cp-core controller'ları
 * arasındaki mevcut konvansiyon.
 */
final class AACPHookController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly HookManager $hookManager,
    ) {
    }

    #[Route('/aacp/hooks', name: 'aacp_hooks', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.hooks', icon: 'heroicons:bolt', panel: 'aacp', priority: 22, capability: 'system.hooks.manage', group: 'aacp.group.system')]
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

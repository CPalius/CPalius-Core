<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Pagination\Paginator;
use App\Core\Trash\Entity\TrashItem;
use App\Core\Trash\Entity\TrashRule;
use App\Core\Trash\Repository\TrashItemRepository;
use App\Core\Trash\Repository\TrashRuleRepository;
use App\Core\Trash\SoftDeletedBin;
use App\Core\Trash\TrashManager;
use App\Core\Trash\TrashPolicy;
use App\Core\Trash\TrashTypeLabels;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * System-wide recycle bin: browse, restore, permanently delete, and set
 * per-type retention. Capture itself is TrashCaptureListener.
 */
#[Route('/aacp/trash', name: 'aacp_trash_')]
#[IsGranted('system.trash.manage')]
final class AACPTrashController extends AbstractController
{
    public const CSRF = 'aacp_trash';
    private const PER_PAGE = 30;

    public function __construct(
        private readonly TrashItemRepository $items,
        private readonly TrashRuleRepository $rules,
        private readonly TrashManager $trash,
        private readonly TrashPolicy $policy,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
        private readonly SoftDeletedBin $softBin,
        private readonly TrashTypeLabels $typeLabels,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.trash.menu', icon: 'heroicons:trash', panel: 'aacp', priority: 86, capability: 'system.trash.manage', parent: 'aacp_hub_maintenance')]
    public function index(Request $request): Response
    {
        $filters = [
            'type' => $this->nullableString($request->query->get('type')),
            'q' => $this->nullableString($request->query->get('q')),
        ];

        // Two halves of one bin: rows that were deleted (cp_trash_items) and rows that were only
        // marked deleted (posts, pages, forum topics...), which never reach cp_trash_items.
        $types = $this->items->countByType();
        foreach ($this->softBin->counts() as $name => $count) {
            $types[$name] = ($types[$name] ?? 0) + $count;
        }
        ksort($types, \SORT_NATURAL | \SORT_FLAG_CASE);

        $items = $this->paginator->paginate(
            $this->items->createFilteredQueryBuilder($filters),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );
        $soft = $this->softBin->items($filters['type'], $filters['q']);

        $names = array_keys($types);
        foreach ($items as $item) {
            $names[] = $item->getResourceName();
        }

        return $this->render('aacp/trash/index.html.twig', [
            'items' => $items,
            'softItems' => $soft,
            'filters' => $filters,
            'types' => $types,
            'typeLabels' => $this->typeLabels->labels(array_unique($names)),
            'enabled' => $this->policy->isEnabled(TrashRule::GLOBAL),
        ]);
    }

    #[Route('/soft/restore', name: 'soft_restore', methods: ['POST'])]
    public function softRestore(Request $request): RedirectResponse
    {
        $this->assertToken($request);

        try {
            $label = $this->softBin->restore((string) $request->request->get('key'));
            $this->addFlash('success', $this->translator->trans('aacp.trash.flash.restored', ['label' => $label]));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('aacp.trash.flash.restore_failed', ['error' => $e->getMessage()]));
        }

        return $this->redirectToRoute('aacp_trash_index');
    }

    #[Route('/soft/delete', name: 'soft_delete', methods: ['POST'])]
    #[IsGranted('system.trash.purge')]
    public function softDelete(Request $request): RedirectResponse
    {
        $this->assertToken($request);

        try {
            $this->softBin->purge((string) $request->request->get('key'));
            $this->addFlash('success', $this->translator->trans('aacp.trash.flash.deleted'));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('aacp.trash.flash.restore_failed', ['error' => $e->getMessage()]));
        }

        return $this->redirectToRoute('aacp_trash_index');
    }

    #[Route('/{id}/restore', name: 'restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(int $id, Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $item = $this->findItem($id);

        if ($request->request->getBoolean('with_batch')) {
            $result = $this->trash->restoreBatch($item->getBatchId());
            $this->flashBatch($result);
        } else {
            try {
                $this->trash->restore($item);
                $this->addFlash('success', $this->translator->trans('aacp.trash.flash.restored', ['label' => $item->getLabel()]));
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $this->translator->trans('aacp.trash.flash.restore_failed', ['error' => $e->getMessage()]));
            }
        }

        return $this->redirectToRoute('aacp_trash_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.trash.purge')]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $item = $this->findItem($id);
        $this->trash->delete($item);
        $this->addFlash('success', $this->translator->trans('aacp.trash.flash.deleted'));

        return $this->redirectToRoute('aacp_trash_index');
    }

    #[Route('/empty', name: 'empty', methods: ['POST'])]
    #[IsGranted('system.trash.purge')]
    public function empty(Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $total = $this->trash->emptyBin($this->nullableString($request->request->get('type')));
        $this->addFlash('success', $this->translator->trans('aacp.trash.flash.emptied', ['total' => $total]));

        return $this->redirectToRoute('aacp_trash_index');
    }

    #[Route('/settings', name: 'settings', methods: ['GET', 'POST'])]
    #[IsGranted('system.trash.purge')]
    public function settings(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertToken($request);

            $globalDays = max(0, min(3650, $request->request->getInt('global_days', TrashPolicy::DEFAULT_RETENTION_DAYS)));
            $perType = [];
            foreach ($this->trash->knownTypes() as $i => $name) {
                $raw = $request->request->all('rules')[$i] ?? [];
                $days = trim((string) ($raw['days'] ?? ''));
                $perType[$name] = [
                    'enabled' => !empty($raw['enabled']),
                    'days' => $days === '' ? null : max(0, min(3650, (int) $days)),
                ];
            }

            $this->trash->saveRules($request->request->getBoolean('global_enabled'), $globalDays, $perType);
            $this->addFlash('success', $this->translator->trans('aacp.trash.flash.settings_saved'));

            return $this->redirectToRoute('aacp_trash_settings');
        }

        $stored = [];
        foreach ($this->rules->findAll() as $rule) {
            $stored[$rule->getResourceName()] = $rule;
        }
        $global = $stored[TrashRule::GLOBAL] ?? new TrashRule(TrashRule::GLOBAL);

        return $this->render('aacp/trash/settings.html.twig', [
            'global' => $global,
            'types' => $this->trash->knownTypes(),
            'typeLabels' => $this->typeLabels->labels($this->trash->knownTypes()),
            'rules' => $stored,
            'counts' => $this->items->countByType(),
        ]);
    }

    /**
     * @param array{restored: int, failed: list<array{item: TrashItem, error: string}>} $result
     */
    private function flashBatch(array $result): void
    {
        if ($result['restored'] > 0) {
            $this->addFlash('success', $this->translator->trans('aacp.trash.flash.batch_restored', ['count' => $result['restored']]));
        }

        foreach ($result['failed'] as $failure) {
            $this->addFlash('error', $this->translator->trans('aacp.trash.flash.restore_failed', [
                'error' => $failure['item']->getLabel().': '.$failure['error'],
            ]));
        }
    }

    private function findItem(int $id): TrashItem
    {
        $item = $this->items->find($id);
        if (!$item instanceof TrashItem) {
            throw new NotFoundHttpException($this->translator->trans('aacp.trash.error.not_found'));
        }

        return $item;
    }

    private function assertToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }

    private function nullableString(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}

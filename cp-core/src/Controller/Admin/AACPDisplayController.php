<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Display\Entity\EntityDisplay;
use App\Core\Display\EntityDisplayRegistry;
use App\Core\Display\Repository\EntityDisplayRepository;
use App\Core\Display\ViewModeRegistry;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\Repository\FieldDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * T2.2 view mode display management — one matrix per bundle (rows = fields,
 * columns = every registered view mode at once), unlike Drupal's one-view-
 * mode-per-screen "Manage Display" tabs. Same plain-Twig POST-redirect-GET
 * convention as AACPFieldController; capability piggybacks on
 * system.fields.manage since this only ever configures fields that already
 * exist there.
 */
final class AACPDisplayController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly FieldDefinitionRepository $fieldRepository,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly EntityDisplayRepository $displayRepository,
        private readonly EntityDisplayRegistry $displayRegistry,
        private readonly ViewModeRegistry $viewModes,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/aacp/display', name: 'aacp_display', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.display', icon: 'heroicons:eye', panel: 'aacp', priority: 63, capability: 'system.fields.manage', parent: 'aacp_hub_structure')]
    #[IsGranted('system.fields.manage')]
    public function index(): Response
    {
        return new Response($this->twig->render('aacp/display/index.html.twig', [
            'bundles' => $this->fieldRepository->countPerBundle(),
        ]));
    }

    #[Route('/aacp/display/{bundle}', name: 'aacp_display_bundle', methods: ['GET'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}'])]
    #[IsGranted('system.fields.manage')]
    public function bundle(string $bundle): Response
    {
        $fields = $this->fieldDefinitions->getFieldsForBundle($bundle);
        $viewModeIds = $this->viewModes->ids();

        // matrix[fieldName][viewMode] = ['visible' => bool, 'weight' => int, 'label' => string]
        $matrix = [];
        foreach ($fields as $definition) {
            foreach ($viewModeIds as $viewMode) {
                $matrix[$definition->getName()][$viewMode] = ['visible' => true, 'weight' => $definition->getWeight(), 'label' => EntityDisplay::LABEL_ABOVE];
            }
        }
        foreach ($this->displayRepository->findByBundle($bundle) as $row) {
            if (isset($matrix[$row->getFieldName()][$row->getViewMode()])) {
                $matrix[$row->getFieldName()][$row->getViewMode()] = [
                    'visible' => $row->isVisible(),
                    'weight' => $row->getWeight(),
                    'label' => $row->getLabelDisplay(),
                ];
            }
        }

        return new Response($this->twig->render('aacp/display/bundle.html.twig', [
            'bundle' => $bundle,
            'fields' => $fields,
            'viewModes' => $this->viewModes->all(),
            'matrix' => $matrix,
            'labelChoices' => [EntityDisplay::LABEL_ABOVE, EntityDisplay::LABEL_INLINE, EntityDisplay::LABEL_HIDDEN],
            'csrf_token' => $this->token(),
        ]));
    }

    #[Route('/aacp/display/{bundle}/save', name: 'aacp_display_save', methods: ['POST'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}'])]
    #[IsGranted('system.fields.manage')]
    public function save(string $bundle, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $fieldNames = array_map(
            static fn ($definition): string => $definition->getName(),
            $this->fieldDefinitions->getFieldsForBundle($bundle),
        );

        /** @var array<string, array<string, mixed>> $submitted viewMode => fieldName => row */
        $submitted = (array) $request->request->all('display');

        foreach ($this->viewModes->ids() as $viewMode) {
            $rows = \is_array($submitted[$viewMode] ?? null) ? $submitted[$viewMode] : [];

            foreach ($fieldNames as $fieldName) {
                $row = \is_array($rows[$fieldName] ?? null) ? $rows[$fieldName] : [];

                $display = $this->displayRepository->findOneByBundleViewModeAndField($bundle, $viewMode, $fieldName)
                    ?? new EntityDisplay($bundle, $viewMode, $fieldName);

                $display
                    ->setVisible(($row['visible'] ?? null) !== null)
                    ->setWeight((int) ($row['weight'] ?? 0))
                    ->setLabelDisplay((string) ($row['label'] ?? EntityDisplay::LABEL_ABOVE));

                $this->entityManager->persist($display);
            }
        }

        $this->entityManager->flush();
        $this->displayRegistry->invalidate($bundle);

        return new RedirectResponse('/aacp/display/'.$bundle);
    }

    private function token(): string
    {
        return $this->csrfTokenManager->getToken('aacp_display')->getValue();
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_display', (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}

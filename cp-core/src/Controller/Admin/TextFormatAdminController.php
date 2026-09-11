<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\TextFormat\Entity\TextFormat;
use App\Core\TextFormat\Repository\TextFormatRepository;
use App\Core\TextFormat\TextFormatRegistry;
use App\Core\TextFormat\TextFormatSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One screen per named text format (filter enablement + HTML allowlist).
 * Core formats are locked (cannot be deleted); YAML is the seed.
 */
#[Route('/aacp/text-formats', name: 'aacp_text_format_')]
#[IsGranted('core.text_format.manage')]
final class TextFormatAdminController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'aacp_text_format_form';

    public function __construct(
        private readonly TextFormatRegistry $registry,
        private readonly TextFormatRepository $repository,
        private readonly TextFormatSeeder $seeder,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.text_formats', icon: 'heroicons:document-text', panel: 'aacp', priority: 26, capability: 'core.text_format.manage', parent: 'aacp_tools')]
    public function index(): Response
    {
        $this->seeder->ensureCatalog();

        return $this->render('aacp/text_formats/index.html.twig', [
            'formats' => $this->registry->all(),
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]);
    }

    #[Route('/{id}/save', name: 'save', methods: ['POST'], requirements: ['id' => '[a-z][a-z0-9_]{0,31}'])]
    public function save(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException($this->translator->trans('aacp.text_format.invalid_csrf'));
        }

        $catalog = $this->registry->get($id);
        if ($catalog === null) {
            throw new NotFoundHttpException($this->translator->trans('aacp.text_format.unknown', ['id' => $id]));
        }

        $posted = $request->request->all('filters');
        $posted = \is_array($posted) ? $posted : [];

        $filters = [];
        foreach ($catalog->filters as $i => $spec) {
            $row = \is_array($posted[$spec['id']] ?? null) ? $posted[$spec['id']] : [];
            $settings = $spec['settings'];
            if ($spec['id'] === 'html_restrict') {
                $settings = ['allow_elements' => $this->parseAllowTags((string) ($row['allow_tags'] ?? ''))];
                if ($settings['allow_elements'] === []) {
                    $settings = $spec['settings'];
                }
            }
            $filters[] = [
                'id' => $spec['id'],
                'enabled' => isset($row['enabled']),
                'weight' => isset($row['weight']) ? (int) $row['weight'] : $spec['weight'],
                'settings' => $settings,
            ];
            unset($i);
        }

        $row = $this->repository->findOneByMachineName($id);
        if ($row === null) {
            $row = new TextFormat($id, $catalog->label);
            $row->setLocked(true);
            $this->entityManager->persist($row);
        }

        $row->setLabel($catalog->label)
            ->setDescription($catalog->description)
            ->setWysiwyg($catalog->wysiwyg)
            ->setFilters($this->registry->normalizeFilters($filters));

        $this->entityManager->flush();
        $this->registry->invalidate();

        $this->addFlash('success', $this->translator->trans('aacp.text_format.save_success', ['id' => $id]));

        return $this->redirectToRoute('aacp_text_format_index');
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseAllowTags(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\s]+/', strtolower($raw)) ?: [] as $tag) {
            $tag = trim($tag);
            if ($tag === '' || preg_match('/^[a-z][a-z0-9]{0,15}$/', $tag) !== 1) {
                continue;
            }
            $out[$tag] = $this->defaultAttrs($tag);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function defaultAttrs(string $tag): array
    {
        return match ($tag) {
            'a' => ['href', 'title', 'rel', 'target'],
            'img' => ['src', 'alt', 'width', 'height', 'class'],
            'td', 'th' => ['colspan', 'rowspan'],
            'div', 'span', 'blockquote', 'figure' => ['class'],
            default => [],
        };
    }
}

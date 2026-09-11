<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Module\ModuleContributionCatalog;
use App\Core\PathAlias\Entity\PathAliasPattern;
use App\Core\PathAlias\PathAliasGenerator;
use App\Core\PathAlias\Repository\PathAliasPatternRepository;
use App\Core\Token\TokenReplacer;
use App\Core\Token\TokenTypeRegistry;
use App\Entity\Node;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One matrix screen for every node bundle that has a public show route (Drupal: a separate
 * Pathauto pattern form per content type) — same "one screen instead of tab-per-item"
 * convention T2.2 established for view modes. Manual CSRF, core.path_pattern.manage
 * capability, POST-redirect-GET (UrlAliasAdminController convention).
 */
#[Route('/aacp/path-patterns', name: 'aacp_path_pattern_')]
#[IsGranted('core.path_pattern.manage')]
final class PathPatternAdminController extends AbstractController
{
    private const ENTITY_TYPE = 'node';

    private const CSRF_TOKEN_ID = 'aacp_path_pattern_form';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PathAliasPatternRepository $patterns,
        private readonly TokenTypeRegistry $tokenCatalog,
        private readonly ModuleContributionCatalog $contributions,
        private readonly NodeRepository $nodeRepository,
        private readonly PathAliasGenerator $generator,
        private readonly TokenReplacer $tokenReplacer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.path_patterns', icon: 'heroicons:map', panel: 'aacp', priority: 27, capability: 'core.path_pattern.manage', parent: 'aacp_tools')]
    public function index(): Response
    {
        $byBundle = [];
        foreach ($this->patterns->findAllOrdered() as $pattern) {
            if ($pattern->getEntityType() === self::ENTITY_TYPE) {
                $byBundle[$pattern->getBundle()] = $pattern;
            }
        }

        $rows = [];
        foreach ($this->patternableBundles() as $bundle => $label) {
            $rows[] = [
                'bundle' => $bundle,
                'label' => $label,
                'pattern' => $byBundle[$bundle]?->getPattern() ?? '',
                'enabled' => $byBundle[$bundle]?->isEnabled() ?? true,
            ];
        }

        return $this->render('aacp/path_patterns/index.html.twig', [
            'rows' => $rows,
            'tokenCatalog' => $this->tokenCatalog->all(),
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]);
    }

    #[Route('/{bundle}/save', name: 'save', methods: ['POST'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}'])]
    public function save(string $bundle, Request $request): Response
    {
        $this->assertValidCsrf($request);
        $this->assertKnownBundle($bundle);

        $patternText = trim((string) $request->request->get('pattern'));
        $enabled = $request->request->getBoolean('enabled');

        if ($patternText !== '') {
            $errors = $this->tokenReplacer->validate($patternText);
            if ($errors !== []) {
                $this->addFlash('error', $this->translator->trans('aacp.path_pattern.validation.invalid_tokens', [
                    'bundle' => $bundle,
                    'errors' => implode(', ', $errors),
                ]));

                return $this->redirectToRoute('aacp_path_pattern_index');
            }
        }

        $pattern = $this->patterns->findOneByTypeAndBundle(self::ENTITY_TYPE, $bundle);

        if ($patternText === '') {
            if ($pattern !== null) {
                $this->entityManager->remove($pattern);
                $this->entityManager->flush();
            }

            $this->addFlash('success', $this->translator->trans('aacp.path_pattern.save_success.cleared', ['bundle' => $bundle]));

            return $this->redirectToRoute('aacp_path_pattern_index');
        }

        if ($pattern === null) {
            $pattern = new PathAliasPattern(self::ENTITY_TYPE, $bundle, $patternText);
            $this->entityManager->persist($pattern);
        } else {
            $pattern->setPattern($patternText);
        }
        $pattern->setEnabled($enabled);

        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('aacp.path_pattern.save_success', ['bundle' => $bundle]));

        return $this->redirectToRoute('aacp_path_pattern_index');
    }

    /**
     * Bulk fast-follow for content saved BEFORE a pattern existed — not required for
     * correctness (old links keep working via UrlAliasListener's live slug resolution
     * regardless), purely a convenience to also give existing content a pretty alias.
     */
    #[Route('/{bundle}/regenerate', name: 'regenerate', methods: ['POST'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}'])]
    public function regenerate(string $bundle, Request $request): Response
    {
        $this->assertValidCsrf($request);
        $this->assertKnownBundle($bundle);

        $pattern = $this->patterns->findOneByTypeAndBundle(self::ENTITY_TYPE, $bundle);
        if ($pattern === null || !$pattern->isEnabled()) {
            $this->addFlash('error', $this->translator->trans('aacp.path_pattern.regenerate.no_pattern', ['bundle' => $bundle]));

            return $this->redirectToRoute('aacp_path_pattern_index');
        }

        $count = 0;
        foreach ($this->nodeRepository->findBy(['type' => $bundle, 'status' => Node::STATUS_PUBLISHED]) as $node) {
            if ($this->generator->generateForNode($node) !== null) {
                ++$count;
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('aacp.path_pattern.regenerate.done', ['bundle' => $bundle, 'count' => $count]));

        return $this->redirectToRoute('aacp_path_pattern_index');
    }

    /**
     * @return array<string, string> bundle => label, only types with a real front-facing
     *                               show route (a pattern for a route-less bundle would
     *                               just generate dead aliases)
     */
    private function patternableBundles(): array
    {
        $labels = $this->contributions->studioTypeLabels();
        $routes = $this->contributions->nodeShowRoutes();

        return array_intersect_key($labels, $routes);
    }

    private function assertKnownBundle(string $bundle): void
    {
        if (!\array_key_exists($bundle, $this->patternableBundles())) {
            throw new NotFoundHttpException($this->translator->trans('aacp.path_pattern.unknown_bundle', ['bundle' => $bundle]));
        }
    }

    private function assertValidCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $submitted)) {
            throw $this->createAccessDeniedException($this->translator->trans('aacp.path_pattern.invalid_csrf'));
        }
    }
}

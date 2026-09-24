<?php

declare(strict_types=1);

namespace Modules\DnsTools\Controller;

use Modules\DnsTools\Catalog\PresentedTool;
use Modules\DnsTools\Service\DnsToolsTemplateResolver;
use Modules\DnsTools\Service\ForumBridge;
use Modules\DnsTools\Service\ToolRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dns-tools')]
final class DnsToolsFrontController extends AbstractController
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly DnsToolsTemplateResolver $templates,
        private readonly ForumBridge $forum,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('', name: 'dnstools_index', methods: ['GET'])]
    public function index(): Response
    {
        $quick = $this->registry->get('dns-lookup') ?? $this->registry->featured()[0] ?? ($this->registry->all()[0] ?? null);

        return $this->render($this->templates->resolve('index'), [
            'dnsToolsParentLayout' => $this->templates->layout(),
            'featured' => $this->registry->featured(),
            'categories' => $this->registry->categories(),
            'toolsByCategory' => $this->grouped(),
            'toolCount' => \count($this->registry->all()),
            'pageCopy' => $this->registry->pageCopy('index', 'dnstools.seo.index'),
            'quickTool' => $quick,
            'forumSearchUrl' => $this->forum->enabled() ? $this->forum->searchUrl() : null,
            'forumComposeUrl' => $this->forum->enabled() ? $this->forum->newTopicTemplate() : null,
            'forumBoards' => $this->forumBoards(),
        ]);
    }

    #[Route('/ask', name: 'dnstools_forum_ask', methods: ['GET'], priority: 8)]
    public function forumAsk(Request $request): Response
    {
        if (!$this->forum->enabled()) {
            throw $this->createNotFoundException();
        }

        $title = mb_substr(trim((string) $request->query->get('title')), 0, 180);
        $prefill = (string) $request->query->get('pf');
        $boards = $this->forumBoards();
        if ($boards === []) {
            $fallback = $this->forum->searchHref($title) ?? $this->generateUrl('dnstools_index');

            return $this->redirect($fallback);
        }

        if (\count($boards) === 1) {
            $href = $this->forum->newTopicHref($boards[0]['slug'], $title, $prefill);
            if ($href !== null) {
                return $this->redirect($href);
            }
        }

        return $this->render($this->templates->resolve('forum_ask'), [
            'dnsToolsParentLayout' => $this->templates->layout(),
            'title' => $title,
            'prefill' => $prefill,
            'boards' => $boards,
        ]);
    }

    #[Route('/forum-share', name: 'dnstools_forum_share', methods: ['POST'], priority: 8)]
    public function forumShare(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('dnstools_query', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $token = bin2hex(random_bytes(8));
        $request->getSession()->set('dnstools_forum_prefill', [
            'token' => $token,
            'title' => mb_substr(trim((string) $request->request->get('title')), 0, 180),
            'body' => mb_substr(trim((string) $request->request->get('body')), 0, 8000),
        ]);

        return new JsonResponse(['ok' => true, 'token' => $token]);
    }

    #[Route('/all', name: 'dnstools_all', methods: ['GET'])]
    public function all(): Response
    {
        return $this->render($this->templates->resolve('all'), [
            'dnsToolsParentLayout' => $this->templates->layout(),
            'categories' => $this->registry->categories(),
            'toolsByCategory' => $this->grouped(),
            'toolCount' => \count($this->registry->all()),
            'pageCopy' => $this->registry->pageCopy('all', 'dnstools.seo.all'),
        ]);
    }

    #[Route('/{slug}', name: 'dnstools_tool', methods: ['GET'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function tool(string $slug): Response
    {
        $tool = $this->registry->get($slug);
        if (!$tool instanceof PresentedTool) {
            throw $this->createNotFoundException();
        }

        return $this->render($this->templates->resolve('tool'), [
            'dnsToolsParentLayout' => $this->templates->layout(),
            'tool' => $tool,
            'related' => $this->registry->byCategory($tool->category),
            'apiUrl' => $this->generateUrl('dnstools_api', ['slug' => $slug]),
            'forumSearchUrl' => $this->forum->enabled() ? $this->forum->searchUrl() : null,
            'forumComposeUrl' => $this->forum->enabled() ? $this->forum->newTopicTemplate() : null,
            'forumBoards' => $this->forumBoards(),
        ]);
    }

    /**
     * @return list<array{slug: string, title: string}>
     */
    private function forumBoards(): array
    {
        if (!$this->forum->enabled()) {
            return [];
        }

        return $this->forum->boards($this->requestStack->getCurrentRequest()?->getLocale() ?? 'tr');
    }

    /**
     * @return array<string, list<PresentedTool>>
     */
    private function grouped(): array
    {
        $grouped = [];
        foreach ($this->registry->categories() as $id => $label) {
            $grouped[$id] = $this->registry->byCategory($id);
        }

        return $grouped;
    }
}

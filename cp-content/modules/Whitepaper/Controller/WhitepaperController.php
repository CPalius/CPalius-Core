<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Controller;

use Modules\Whitepaper\Content\WhitepaperContent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public whitepaper page.
 *
 * The route used to live on core's ThemeController, which meant core knew the
 * word "whitepaper". It belongs to whoever publishes the document, so it moved
 * here with everything else: deactivating this module now removes the page
 * instead of leaving a route that renders an empty document.
 */
final class WhitepaperController extends AbstractController
{
    public function __construct(
        private readonly WhitepaperContent $content,
    ) {
    }

    #[Route('/whitepaper', name: 'whitepaper_show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        return $this->render('@Theme/whitepaper.html.twig', [
            'doc' => $this->content->forLocale($request->getLocale()),
        ]);
    }
}

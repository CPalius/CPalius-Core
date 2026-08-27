<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Locale'siz /forum isteklerini kanonik /tr/forum yoluna yönlendirir.
 */
final class LegacyForumRedirectController extends AbstractController
{
    #[Route('/forum', name: 'forum_index_legacy', priority: 10)]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('forum_index', ['_locale' => 'tr'], 301);
    }
}

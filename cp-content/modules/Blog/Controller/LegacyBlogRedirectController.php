<?php

declare(strict_types=1);

namespace Modules\Blog\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Blog front route'ları bilinçli olarak /{_locale}/blog öneki taşır (bkz.
 * Resources/config/routes.yaml). Menü seed verisi veya dış linkler bazen
 * locale'siz /blog kullanır; bu controller onları kanonik URL'e yönlendirir.
 */
final class LegacyBlogRedirectController extends AbstractController
{
    #[Route('/blog', name: 'blog_index_legacy', priority: 10)]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('blog_index', ['_locale' => 'tr'], 301);
    }
}

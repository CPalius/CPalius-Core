<?php

declare(strict_types=1);

namespace Modules\Blog\Controller;

use App\Core\Localization\LocaleProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redirect locale-less /blog links to the canonical /{_locale}/blog path.
 */
final class LegacyBlogRedirectController extends AbstractController
{
    public function __construct(
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[Route('/blog', name: 'blog_index_legacy', priority: 10)]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('blog_index', ['_locale' => $this->localeProvider->getDefaultCode()], 301);
    }
}

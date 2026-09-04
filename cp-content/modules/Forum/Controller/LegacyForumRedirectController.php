<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Localization\LocaleProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redirect locale-less /forum and /forums to the canonical /{_locale}/forums path.
 */
final class LegacyForumRedirectController extends AbstractController
{
    public function __construct(
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[Route('/forum', name: 'forum_index_legacy', priority: 10)]
    #[Route('/forums', name: 'forums_index_legacy', priority: 10)]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('forum_index', ['_locale' => $this->localeProvider->getDefaultCode()], 301);
    }
}

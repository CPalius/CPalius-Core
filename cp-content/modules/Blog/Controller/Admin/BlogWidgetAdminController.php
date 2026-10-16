<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use Modules\Blog\Widget\BlogWidgetAreaProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Widgets" entry of the Studio → Blog menu. The widget screens belong to the Widget module (they
 * manage every area, not just the blog's); this takes the blog's menu item straight to the blog sidebar
 * there, or says so when the Widget module is switched off.
 */
#[Route('/admin/blog/widgets', name: 'admin_blog_widgets_')]
#[IsGranted('blog.category.manage')]
final class BlogWidgetAdminController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.blog.widgets.menu', icon: 'heroicons:rectangle-group', panel: 'studio', priority: 24, capability: 'blog.category.manage', parent: 'admin_posts_index')]
    public function index(): RedirectResponse
    {
        try {
            return $this->redirectToRoute('admin_widgets_index', ['area' => BlogWidgetAreaProvider::SIDEBAR]);
        } catch (RouteNotFoundException) {
            $this->addFlash('error', $this->translator->trans('studio.blog.widgets.module_off'));

            return $this->redirectToRoute('admin_posts_index');
        }
    }
}

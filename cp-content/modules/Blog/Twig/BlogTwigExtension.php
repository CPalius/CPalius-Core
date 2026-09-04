<?php

declare(strict_types=1);

namespace Modules\Blog\Twig;

use App\Entity\Node;
use Modules\Blog\PostSubType;
use Modules\Blog\Service\BlogPostPresentationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig bridge for PostSubType::label() and related presentation helpers.
 */
final class BlogTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly BlogPostPresentationService $presentationService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('blog_post_sub_type_label', [PostSubType::class, 'label']),
            new TwigFunction('blog_featured_image_url', $this->resolveFeaturedImageUrl(...)),
            new TwigFunction('blog_list_excerpt', $this->resolveListExcerpt(...)),
        ];
    }

    public function resolveFeaturedImageUrl(Node $post): ?string
    {
        return $this->presentationService->resolveFeaturedImageUrl($post);
    }

    public function resolveListExcerpt(Node $post): ?string
    {
        return $this->presentationService->resolveListExcerpt($post);
    }
}

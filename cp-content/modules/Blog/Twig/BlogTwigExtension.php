<?php

declare(strict_types=1);

namespace Modules\Blog\Twig;

use App\Core\Account\UserAvatarService;
use App\Entity\Node;
use App\Entity\User;
use Modules\Blog\PostSubType;
use Modules\Blog\Service\BlogPostPresentationService;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig bridge for PostSubType::label() and related presentation helpers.
 */
final class BlogTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly BlogPostPresentationService $presentationService,
        private readonly UserAvatarService $avatarService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('blog_post_sub_type_label', $this->translatePostSubTypeLabel(...)),
            new TwigFunction('blog_featured_image_url', $this->resolveFeaturedImageUrl(...)),
            new TwigFunction('blog_list_excerpt', $this->resolveListExcerpt(...)),
            new TwigFunction('blog_user_avatar_url', $this->resolveUserAvatarUrl(...)),
            new TwigFunction('blog_author_profile_url', $this->resolveAuthorProfileUrl(...)),
            new TwigFunction('blog_public_display_name', $this->resolvePublicDisplayName(...)),
        ];
    }

    public function translatePostSubTypeLabel(string $subType): string
    {
        return $this->translator->trans(PostSubType::label($subType));
    }

    public function resolveFeaturedImageUrl(Node $post): ?string
    {
        return $this->presentationService->resolveFeaturedImageUrl($post);
    }

    public function resolveListExcerpt(Node $post): ?string
    {
        return $this->presentationService->resolveListExcerpt($post);
    }

    public function resolveUserAvatarUrl(?User $user): ?string
    {
        return $this->avatarService->resolveUrl($user);
    }

    public function resolvePublicDisplayName(?User $user): string
    {
        if (!$user instanceof User) {
            return '';
        }

        return $user->getPublicDisplayName();
    }

    public function resolveAuthorProfileUrl(?User $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }

        $slug = $user->getProfileSlug();
        if ($slug === '') {
            return null;
        }

        try {
            return $this->urlGenerator->generate('forum_profile', ['username' => $slug]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}

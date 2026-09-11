<?php

declare(strict_types=1);

namespace Modules\Pages\Twig;

use App\Entity\Node;
use Modules\Pages\Service\PagePresentationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PageTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly PagePresentationService $presentationService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('page_featured_image_url', $this->resolveFeaturedImageUrl(...)),
            new TwigFunction('page_field', $this->resolveFieldValue(...)),
        ];
    }

    public function resolveFeaturedImageUrl(Node $page): ?string
    {
        return $this->presentationService->resolveFeaturedImageUrl($page);
    }

    public function resolveFieldValue(Node $page, string $key, mixed $default = null): mixed
    {
        foreach ($this->presentationService->presentFields($page) as $field) {
            if ($field['key'] === $key) {
                return $field['value'] ?? $default;
            }
        }

        return $default;
    }
}

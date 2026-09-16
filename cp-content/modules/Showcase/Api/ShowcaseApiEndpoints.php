<?php

declare(strict_types=1);

namespace Modules\Showcase\Api;

use App\Core\Api\Attribute\CpApi;
use App\Core\Localization\LocaleProvider;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;
use Modules\Showcase\Service\ShowcasePresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Read-only JSON over published showcase entries, under /api.
 *
 * Public on purpose — everything returned is already on a public page — but only
 * published, non-expired entries are ever serialized, and the payload is built
 * field by field rather than dumping the entity, so a future column cannot leak
 * by accident.
 */
final class ShowcaseApiEndpoints
{
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcasePresenter $presenter,
        private readonly LocaleProvider $localeProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[CpApi(path: '/showcase/types', methods: ['GET'], public: true)]
    public function listTypes(Request $request): JsonResponse
    {
        $locale = $this->localeProvider->resolve($request->query->get('locale'));

        return new JsonResponse([
            'data' => array_map(
                static fn ($type): array => [
                    'machine_name' => $type->getMachineName(),
                    'label' => $type->label($locale),
                    'description' => $type->description($locale),
                ],
                $this->types->findEnabled(),
            ),
        ]);
    }

    #[CpApi(path: '/showcase/items', methods: ['GET'], public: true)]
    public function listItems(Request $request): JsonResponse
    {
        $locale = $this->localeProvider->resolve($request->query->get('locale'));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 10)));
        $typeName = (string) $request->query->get('type', '');
        $type = $typeName !== '' ? $this->types->findOneByMachineName($typeName) : null;

        $items = $this->items->findVisible(
            $locale,
            $limit,
            $request->query->getBoolean('featured'),
            $type,
        );

        return new JsonResponse([
            'data' => array_values(array_map(
                fn (ShowcaseItem $item): array => $this->serialize($item, $locale),
                $items,
            )),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ShowcaseItem $item, string $locale): array
    {
        return [
            'id' => $item->getId(),
            'type' => $item->getType()->getMachineName(),
            'title' => $item->getTitle(),
            'slug' => $item->getSlug(),
            'locale' => $item->getLocale(),
            'summary' => $this->presenter->excerpt($item, 200),
            'price' => $this->presenter->price($item, $locale),
            'price_amount' => $item->getPriceFloat(),
            'price_currency' => $item->getPriceCurrency(),
            'rating' => $item->getRatingAverage(),
            'rating_count' => $item->getRatingCount(),
            'featured' => $item->isFeatured(),
            'url' => $this->itemUrl($item),
            'published_at' => $item->getPublishedAt()?->format(\DATE_ATOM),
        ];
    }

    private function itemUrl(ShowcaseItem $item): ?string
    {
        try {
            return $this->urlGenerator->generate(
                'showcase_show',
                ['_locale' => $item->getLocale(), 'slug' => $item->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (RoutingException) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Field\FieldDefinitionRegistry;
use App\Repository\AssetRepository;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-side helpers shared by the templates and the Twig extension.
 *
 * Price formatting lives here rather than in Twig because "what does 0 mean"
 * depends on the price mode: free, negotiable and "no price" are three different
 * statements, and rendering all of them as "0,00" would misprice a listing.
 */
final class ShowcasePresenter
{
    /**
     * Cover asset id per item id, filled by preload(). A key present with a null
     * value means "resolved, and this item has no usable image" — distinct from
     * "not preloaded", which is why isset() is never used to read it.
     *
     * @var array<int, int|null>
     */
    private array $coverMemo = [];

    public function __construct(
        private readonly AssetRepository $assets,
        private readonly ShowcaseItemRepository $items,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly TranslatorInterface $translator,
        private readonly ShowcaseUrlValidator $urls,
    ) {
    }

    /**
     * Resolves the covers for a whole page of entries in two queries.
     *
     * Rendering a grid of cards otherwise costs one query per card — the guard in
     * Law 6.1 catches it at eleven and throws, which is how this was found. Two
     * things are batched here:
     *
     *   1. the first gallery image of every item, as scalars, so the lazy
     *      media collection is never touched inside the loop;
     *   2. the Asset entities themselves, which lands them in Doctrine's identity
     *      map. That last part matters beyond this class: core's `cp_thumb`
     *      filter looks an asset up by id too, and once it is in the map that
     *      lookup resolves without SQL.
     *
     * Safe to call more than once; already-resolved items are skipped.
     *
     * @param iterable<mixed> $items
     */
    public function preload(iterable $items): void
    {
        $pending = [];
        $assetIds = [];

        foreach ($items as $item) {
            if (!$item instanceof ShowcaseItem) {
                continue;
            }

            $id = $item->getId();

            if ($id === null || \array_key_exists($id, $this->coverMemo)) {
                continue;
            }

            $pending[$id] = $item;
            $cover = $item->getCoverAssetId();

            if ($cover !== null) {
                $assetIds[$cover] = $cover;
            }
        }

        if ($pending === []) {
            return;
        }

        $firstMedia = $this->items->firstMediaAssetIds(array_keys($pending));

        foreach ($firstMedia as $assetId) {
            $assetIds[$assetId] = $assetId;
        }

        $present = [];

        if ($assetIds !== []) {
            foreach ($this->assets->findBy(['id' => array_values($assetIds)]) as $asset) {
                $assetId = $asset->getId();

                if ($assetId !== null) {
                    $present[$assetId] = true;
                }
            }
        }

        foreach ($pending as $id => $item) {
            $cover = $item->getCoverAssetId();

            if ($cover !== null && isset($present[$cover])) {
                $this->coverMemo[$id] = $cover;
                continue;
            }

            // The stored cover is gone from the library; fall back to the first
            // slide rather than showing a card with a hole in it.
            $fallback = $firstMedia[$id] ?? null;
            $this->coverMemo[$id] = $fallback !== null && isset($present[$fallback]) ? $fallback : null;
        }
    }

    /**
     * Human price string, or null when the item deliberately shows no price.
     */
    public function price(ShowcaseItem $item, ?string $locale = null): ?string
    {
        $locale ??= $item->getLocale();
        $mode = $item->getPriceMode();

        if ($mode === ShowcaseItem::PRICE_NONE) {
            return null;
        }

        if ($mode === ShowcaseItem::PRICE_FREE) {
            return $this->translator->trans('showcase.price.free', [], null, $locale);
        }

        if ($mode === ShowcaseItem::PRICE_NEGOTIABLE) {
            return $this->translator->trans('showcase.price.negotiable', [], null, $locale);
        }

        $amount = $item->getPriceFloat();

        if ($amount === null) {
            return $this->translator->trans('showcase.price.negotiable', [], null, $locale);
        }

        $formatted = $this->formatAmount($amount, $item->getPriceCurrency(), $locale);

        return match ($mode) {
            ShowcaseItem::PRICE_STARTING => $this->translator->trans('showcase.price.starting_from', ['amount' => $formatted], null, $locale),
            ShowcaseItem::PRICE_SUBSCRIPTION => $this->translator->trans('showcase.price.subscription', ['amount' => $formatted], null, $locale),
            default => $formatted,
        };
    }

    /**
     * Cover image asset id, falling back to the first gallery image so a listing
     * card is never blank when the owner forgot to pick a cover.
     */
    public function coverAssetId(ShowcaseItem $item): ?int
    {
        $id = $item->getId();

        // array_key_exists, not isset: a preloaded item with no image is stored
        // as null, and isset() would treat that as "not resolved" and fall
        // through to the per-item queries this exists to avoid.
        if ($id !== null && \array_key_exists($id, $this->coverMemo)) {
            return $this->coverMemo[$id];
        }

        $coverId = $item->getCoverAssetId();

        if ($coverId !== null && $this->assets->find($coverId) !== null) {
            return $coverId;
        }

        // Single-entry path only (a detail page, an embedded card). A listing
        // must go through preload(), or this loop initialises the lazy media
        // collection once per card.
        foreach ($item->getMedia() as $media) {
            if ($this->assets->find($media->getAssetId()) !== null) {
                return $media->getAssetId();
            }
        }

        return null;
    }

    /**
     * Short text for cards and meta descriptions: the summary when there is one,
     * otherwise the body stripped back to plain text.
     */
    public function excerpt(ShowcaseItem $item, int $length = 160): string
    {
        $summary = $item->getSummary();

        if ($summary !== null && $summary !== '') {
            return mb_substr($summary, 0, $length);
        }

        $body = trim(strip_tags((string) $item->getBody()));

        if ($body === '') {
            return '';
        }

        $body = (string) preg_replace('/\s+/u', ' ', $body);

        return mb_strlen($body) <= $length ? $body : rtrim(mb_substr($body, 0, $length)).'…';
    }

    /**
     * Field groups defined on a type, so a detail page can render "Specification"
     * and "Licensing" as separate blocks instead of one long list.
     *
     * @return list<string>
     */
    public function fieldGroups(ShowcaseType $type): array
    {
        $groups = [];

        foreach ($this->fieldDefinitions->getFieldsForBundle($type->fieldBundle()) as $definition) {
            $group = $definition->getFieldGroup();

            if ($group !== null && !\in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function externalHost(ShowcaseItem $item): ?string
    {
        return $this->urls->displayHost($item->getExternalUrl());
    }

    private function formatAmount(float $amount, ?string $currency, string $locale): string
    {
        $currency ??= '';

        if ($currency !== '' && class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency($amount, $currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        // intl missing or an unknown currency code: a readable number beats an
        // exception on a product page.
        $number = number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2, ',', '.');

        return $currency !== '' ? $number.' '.$currency : $number;
    }
}

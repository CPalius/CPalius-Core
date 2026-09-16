<?php

declare(strict_types=1);

namespace Modules\Showcase\Dto;

use Modules\Showcase\Entity\ShowcaseItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * One submitted showcase form, already pulled out of the request bag.
 *
 * Member-facing and admin-facing screens post the same field names, so they can
 * share this object — and more importantly share ShowcaseItemManager, which means
 * validation and sanitization cannot be stronger on one screen than the other.
 *
 * Nothing here is trusted: this object only carries raw strings. Coercion,
 * sanitization and validation all happen in ShowcaseItemManager.
 */
final class ShowcaseItemInput
{
    /**
     * @param array<string, mixed> $fields   custom field values keyed by field name
     * @param list<int>            $termIds
     */
    public function __construct(
        public readonly string $title = '',
        public readonly string $summary = '',
        public readonly string $body = '',
        public readonly string $bodyFormat = '',
        public readonly string $slug = '',
        public readonly string $priceMode = ShowcaseItem::PRICE_NONE,
        public readonly string $price = '',
        public readonly string $priceCurrency = '',
        public readonly string $externalUrl = '',
        public readonly string $demoUrl = '',
        public readonly string $contactEmail = '',
        public readonly string $contactPhone = '',
        public readonly string $location = '',
        public readonly array $fields = [],
        public readonly array $termIds = [],
        public readonly ?int $coverAssetId = null,
        public readonly string $expiresAt = '',
    ) {
    }

    /**
     * Copy with custom-field values replaced. Used after file-backed fields have
     * been uploaded and turned into asset ids, which cannot happen while the
     * request is being read because uploading is a side effect.
     *
     * @param array<string, mixed> $fields
     */
    public function withFields(array $fields): self
    {
        return new self(
            title: $this->title,
            summary: $this->summary,
            body: $this->body,
            bodyFormat: $this->bodyFormat,
            slug: $this->slug,
            priceMode: $this->priceMode,
            price: $this->price,
            priceCurrency: $this->priceCurrency,
            externalUrl: $this->externalUrl,
            demoUrl: $this->demoUrl,
            contactEmail: $this->contactEmail,
            contactPhone: $this->contactPhone,
            location: $this->location,
            fields: $fields,
            termIds: $this->termIds,
            coverAssetId: $this->coverAssetId,
            expiresAt: $this->expiresAt,
        );
    }

    public static function fromRequest(Request $request): self
    {
        $bag = $request->request;

        $rawFields = $bag->all('fields');
        $rawTerms = $bag->all('terms');

        return new self(
            title: (string) $bag->get('title', ''),
            summary: (string) $bag->get('summary', ''),
            body: (string) $bag->get('body', ''),
            bodyFormat: (string) $bag->get('body_format', ''),
            slug: (string) $bag->get('slug', ''),
            priceMode: (string) $bag->get('price_mode', ShowcaseItem::PRICE_NONE),
            price: (string) $bag->get('price', ''),
            priceCurrency: (string) $bag->get('price_currency', ''),
            externalUrl: (string) $bag->get('external_url', ''),
            demoUrl: (string) $bag->get('demo_url', ''),
            contactEmail: (string) $bag->get('contact_email', ''),
            contactPhone: (string) $bag->get('contact_phone', ''),
            location: (string) $bag->get('location', ''),
            fields: $rawFields,
            termIds: self::intList($rawTerms),
            coverAssetId: self::intOrNull($bag->get('cover_asset_id')),
            expiresAt: (string) $bag->get('expires_at', ''),
        );
    }

    /**
     * @param array<int|string, mixed> $raw
     *
     * @return list<int>
     */
    private static function intList(array $raw): array
    {
        $out = [];

        foreach ($raw as $value) {
            $id = self::intOrNull($value);

            if ($id !== null) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    private static function intOrNull(mixed $raw): ?int
    {
        if (\is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        return \is_string($raw) && ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
    }
}

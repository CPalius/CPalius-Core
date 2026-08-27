<?php

namespace App\Core\Content;

use App\Entity\Node;
use App\Repository\AssetRepository;

/**
 * Node::data['seo'] içindeki alanlardan Schema.org JSON-LD verisi üretir.
 * Generic tutulur (herhangi bir Node type için kullanılabilir) — node.type
 * 'post' ise BlogPosting, aksi halde WebPage üretir; ileride yeni type'lar
 * için switch/match genişletilebilir.
 */
final class SchemaOrgBuilder
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Node $node): array
    {
        $seo = $node->getDataValue('seo', []);
        $schemaType = is_array($seo) ? ($seo['schema_type'] ?? $this->defaultSchemaType($node)) : $this->defaultSchemaType($node);

        $data = [
            '@context' => 'https://schema.org',
            '@type' => $schemaType,
            'headline' => $node->getTitle(),
            'inLanguage' => $node->getLocale(),
            'dateModified' => $node->getUpdatedAt()->format(\DATE_ATOM),
        ];

        if ($node->getPublishedAt() !== null) {
            $data['datePublished'] = $node->getPublishedAt()->format(\DATE_ATOM);
        }

        if (is_array($seo) && !empty($seo['meta_description'])) {
            $data['description'] = $seo['meta_description'];
        }

        $imageUrl = $this->resolveImageUrl($node, is_array($seo) ? $seo : []);
        if ($imageUrl !== null) {
            $data['image'] = $imageUrl;
        }

        $author = $node->getAuthor();
        if ($author !== null) {
            $data['author'] = [
                '@type' => 'Person',
                'name' => $author->getEmail(),
            ];
        }

        return $data;
    }

    public function buildJson(Node $node): string
    {
        return (string) json_encode($this->build($node), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    private function defaultSchemaType(Node $node): string
    {
        return $node->getType() === 'post' ? 'BlogPosting' : 'WebPage';
    }

    /**
     * @param array<string, mixed> $seo
     */
    private function resolveImageUrl(Node $node, array $seo): ?string
    {
        $assetId = $seo['og_image_asset_id'] ?? $node->getDataValue('featured_image_asset_id');
        if (!is_int($assetId) && !is_numeric($assetId)) {
            return null;
        }

        $asset = $this->assetRepository->find((int) $assetId);

        return $asset?->getStorageKey() !== null ? '/uploads/'.$asset->getStorageKey() : null;
    }
}

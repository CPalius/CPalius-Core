<?php

declare(strict_types=1);

namespace Modules\Blog\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Node;
use App\Repository\AssetRepository;

/**
 * Front list presentation: cover image URL resolution and excerpt truncation.
 */
final class BlogPostPresentationService
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    public function resolveFeaturedImageUrl(Node $post): ?string
    {
        $assetId = $post->getDataValue('featured_image_asset_id');
        if (!is_numeric($assetId)) {
            return null;
        }

        $asset = $this->assetRepository->find((int) $assetId);

        return $asset?->getStorageKey() !== null ? '/uploads/'.$asset->getStorageKey() : null;
    }

    public function resolveListExcerpt(Node $post): ?string
    {
        $source = trim((string) ($post->getDataValue('excerpt') ?? ''));
        if ($source === '') {
            $seo = $post->getDataValue('seo');
            if (is_array($seo) && !empty($seo['meta_description'])) {
                $source = trim(strip_tags((string) $seo['meta_description']));
            }
        }
        if ($source === '') {
            $body = (string) ($post->getDataValue('body') ?? '');
            $source = trim(strip_tags($body));
        }

        if ($source === '') {
            return null;
        }

        $length = (int) $this->settingsRegistry->get('blog.list_excerpt_length');
        if ($length <= 0) {
            $length = 160;
        }

        $unit = (string) $this->settingsRegistry->get('blog.list_excerpt_unit');
        if ($unit !== 'words') {
            $unit = 'chars';
        }

        return $this->truncate($source, $length, $unit);
    }

    private function truncate(string $text, int $length, string $unit): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if ($unit === 'words') {
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (\count($words) <= $length) {
                return $text;
            }

            return implode(' ', \array_slice($words, 0, $length)).'…';
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length)).'…';
    }
}

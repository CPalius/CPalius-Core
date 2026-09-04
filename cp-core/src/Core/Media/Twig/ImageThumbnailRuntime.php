<?php

declare(strict_types=1);

namespace App\Core\Media\Twig;

use App\Core\Media\ImageProcessor;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Resolves the {{ ...|cp_thumb(w, h) }} argument (an Asset, an asset id, a
 * storage key or a "/uploads/..." URL) into a resized derivative URL.
 */
final class ImageThumbnailRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly ImageProcessor $imageProcessor,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    /**
     * @param Asset|string|int|null $source
     */
    public function thumb(Asset|string|int|null $source, int $width, int $height, string $mode = 'crop'): string
    {
        $key = $this->resolveKey($source);

        if ($key === null || $key === '') {
            return '';
        }

        return $this->imageProcessor->thumbnail($key, $width, $height, $mode);
    }

    private function resolveKey(Asset|string|int|null $source): ?string
    {
        if ($source instanceof Asset) {
            return $source->getStorageKey();
        }

        if (\is_int($source) || (\is_string($source) && ctype_digit($source))) {
            $asset = $this->assetRepository->find((int) $source);

            return $asset?->getStorageKey();
        }

        if (\is_string($source) && $source !== '') {
            return $source;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Media\Twig;

use App\Core\Media\AssetUrlGenerator;
use App\Core\Media\ImageProcessor;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Resolves the {{ ...|cp_thumb(w, h) }} argument (an Asset, an asset id, a
 * storage key or a "/uploads/..." URL) into a resized derivative URL.
 *
 * This is also where the CDN is applied. ImageProcessor deals in files on a
 * disk — it decides whether a derivative exists and generates it if not — and
 * giving it an opinion about hostnames would mean the class that writes to
 * public/uploads also had to know where public/uploads is published. The Twig
 * runtime is the boundary where a path becomes something a browser will fetch,
 * so the rewrite belongs here.
 */
final class ImageThumbnailRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly ImageProcessor $imageProcessor,
        private readonly AssetRepository $assetRepository,
        private readonly AssetUrlGenerator $urls,
    ) {
    }

    public function thumb(Asset|string|int|null $source, int $width, int $height, string $mode = 'crop'): string
    {
        $key = $this->resolveKey($source);

        if ($key === null || $key === '') {
            return '';
        }

        // A remote image (Gravatar, a CDN URL, a pasted link) has no local
        // original to resize; prefixing it with /uploads/ would break it.
        if (str_contains($key, '://') || str_starts_with($key, '//') || str_starts_with($key, 'data:')) {
            return $key;
        }

        // The derivative is generated (or found) locally first and only then
        // renamed onto the CDN. Ordering it the other way round would hand the
        // browser a CDN URL for a file that does not exist yet on this origin
        // for the CDN to pull.
        return $this->urls->rewrite($this->imageProcessor->thumbnail($key, $width, $height, $mode));
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

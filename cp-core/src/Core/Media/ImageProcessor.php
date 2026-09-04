<?php

declare(strict_types=1);

namespace App\Core\Media;

/**
 * On-demand image resizing/cropping with a persistent disk cache.
 * Derivatives are written under public/uploads/cache/ and then served
 * directly by the web server on every subsequent request.
 */
final class ImageProcessor
{
    private const CACHE_PREFIX = 'cache';
    private const MIN_DIMENSION = 1;
    private const MAX_DIMENSION = 5000;
    private const MODE_CROP = 'crop';
    private const MODE_FIT = 'fit';

    /** @var array<string, int> detected MIME => GD image type constant */
    private const SUPPORTED = [
        'image/jpeg' => \IMAGETYPE_JPEG,
        'image/png' => \IMAGETYPE_PNG,
        'image/gif' => \IMAGETYPE_GIF,
        'image/webp' => \IMAGETYPE_WEBP,
    ];

    private readonly string $uploadsDir;

    public function __construct(string $projectDir)
    {
        $this->uploadsDir = rtrim(str_replace('\\', '/', $projectDir), '/').'/public/uploads';
    }

    /**
     * Returns the /uploads-rooted URL of a <width>x<height> derivative of
     * $source, generating and caching it on first call. Falls back to the
     * original URL whenever the file is missing or cannot be processed.
     */
    public function thumbnail(string $source, int $width, int $height, string $mode = self::MODE_CROP): string
    {
        $key = $this->normalizeKey($source);
        $width = $this->clampDimension($width);
        $height = $this->clampDimension($height);
        $mode = $mode === self::MODE_FIT ? self::MODE_FIT : self::MODE_CROP;

        // An already-processed derivative is returned untouched.
        if ($key === '' || str_starts_with($key, self::CACHE_PREFIX.'/')) {
            return '/uploads/'.$key;
        }

        $sourcePath = $this->uploadsDir.'/'.$key;
        if (!is_file($sourcePath)) {
            return '/uploads/'.$key;
        }

        $cacheKey = sprintf('%s/%dx%d-%s/%s', self::CACHE_PREFIX, $width, $height, $mode, $key);
        $cachePath = $this->uploadsDir.'/'.$cacheKey;

        if (is_file($cachePath) && filemtime($cachePath) >= filemtime($sourcePath)) {
            return '/uploads/'.$cacheKey;
        }

        try {
            $this->render($sourcePath, $cachePath, $width, $height, $mode);
        } catch (\Throwable) {
            // A single failed image must never break page rendering.
            return '/uploads/'.$key;
        }

        return '/uploads/'.$cacheKey;
    }

    /**
     * Removes every cached derivative of a single source key (all sizes).
     * Called when an asset is replaced or deleted so stale thumbnails go.
     */
    public function purge(string $source): void
    {
        $key = $this->normalizeKey($source);
        if ($key === '' || str_starts_with($key, self::CACHE_PREFIX.'/')) {
            return;
        }

        $cacheRoot = $this->uploadsDir.'/'.self::CACHE_PREFIX;
        if (!is_dir($cacheRoot)) {
            return;
        }

        foreach (scandir($cacheRoot) ?: [] as $bucket) {
            if ($bucket === '.' || $bucket === '..') {
                continue;
            }

            $candidate = $cacheRoot.'/'.$bucket.'/'.$key;
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }

    private function render(string $sourcePath, string $cachePath, int $width, int $height, string $mode): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('The GD extension is required to generate thumbnails.');
        }

        $info = getimagesize($sourcePath);
        if ($info === false) {
            throw new \RuntimeException('Uploaded file is not a decodable image.');
        }

        [$srcW, $srcH] = $info;
        $mime = (string) ($info['mime'] ?? '');

        if (!isset(self::SUPPORTED[$mime]) || $srcW < 1 || $srcH < 1) {
            throw new \RuntimeException('Unsupported image type: '.$mime);
        }

        $source = $this->createFromFile($sourcePath, $mime);

        try {
            $canvas = $mode === self::MODE_FIT
                ? $this->resizeToFit($source, $srcW, $srcH, $width, $height, $mime)
                : $this->resizeToCrop($source, $srcW, $srcH, $width, $height, $mime);

            try {
                $this->ensureDir(\dirname($cachePath));
                $this->writeImage($canvas, $cachePath, $mime);
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Contain: scales the source down to fit inside the box while keeping
     * the aspect ratio; never upscales beyond the original size.
     */
    private function resizeToFit(\GdImage $source, int $srcW, int $srcH, int $width, int $height, string $mime): \GdImage
    {
        $ratio = min($width / $srcW, $height / $srcH, 1.0);
        $dstW = max(1, (int) round($srcW * $ratio));
        $dstH = max(1, (int) round($srcH * $ratio));

        $canvas = $this->createCanvas($dstW, $dstH, $mime);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $canvas;
    }

    /**
     * Cover: scales the source to fill the box, then centre-crops the
     * overflow so the output is exactly <width>x<height>.
     */
    private function resizeToCrop(\GdImage $source, int $srcW, int $srcH, int $width, int $height, string $mime): \GdImage
    {
        $scale = max($width / $srcW, $height / $srcH);
        $cropW = min($srcW, (int) ceil($width / $scale));
        $cropH = min($srcH, (int) ceil($height / $scale));
        $srcX = (int) max(0, (int) round(($srcW - $cropW) / 2));
        $srcY = (int) max(0, (int) round(($srcH - $cropH) / 2));

        $canvas = $this->createCanvas($width, $height, $mime);
        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $width, $height, $cropW, $cropH);

        return $canvas;
    }

    private function createFromFile(string $path, string $mime): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => imagecreatefromwebp($path),
            default => false,
        };

        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Could not read source image: '.$path);
        }

        return $image;
    }

    private function createCanvas(int $width, int $height, string $mime): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) {
            throw new \RuntimeException('Could not allocate the thumbnail canvas.');
        }

        // Preserve transparency for the formats that support an alpha channel.
        if ($mime === 'image/png' || $mime === 'image/gif' || $mime === 'image/webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefill($canvas, 0, 0, $transparent);
            }
        }

        return $canvas;
    }

    private function writeImage(\GdImage $canvas, string $path, string $mime): void
    {
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($canvas, $path, 82),
            'image/png' => imagepng($canvas, $path, 6),
            'image/gif' => imagegif($canvas, $path),
            'image/webp' => imagewebp($canvas, $path, 82),
            default => false,
        };

        if ($ok === false) {
            throw new \RuntimeException('Could not write the processed image to: '.$path);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the thumbnail cache directory: '.$dir);
        }
    }

    /**
     * Accepts a raw storage key ("2026/07/hash.jpg") or a "/uploads/..."
     * URL and returns a safe, traversal-free key relative to public/uploads.
     */
    private function normalizeKey(string $source): string
    {
        $key = trim($source);

        if (str_starts_with($key, '/uploads/')) {
            $key = substr($key, \strlen('/uploads/'));
        }

        $key = ltrim(str_replace('\\', '/', $key), '/');

        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            return '';
        }

        return $key;
    }

    private function clampDimension(int $value): int
    {
        return max(self::MIN_DIMENSION, min(self::MAX_DIMENSION, $value));
    }
}

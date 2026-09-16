<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Media\AssetManager;
use App\Entity\Asset;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Draws placeholder images for the demo seeder and registers them as real Media
 * assets.
 *
 * Generated rather than downloaded on purpose: a seeder that reaches out to a
 * placeholder service fails on an offline machine and quietly ships someone
 * else's URLs into the database. These are drawn locally with GD and go through
 * the normal AssetManager upload, so the rows, the storage layout and the MIME
 * checks are identical to a real upload — the demo exercises the same path a
 * member would.
 */
final class ShowcaseDemoImageFactory
{
    private const WIDTH = 1200;
    private const HEIGHT = 800;

    /** Background gradients, one per slide, so a gallery reads as several images. */
    private const PALETTES = [
        [[38, 70, 104], [74, 124, 155]],
        [[45, 83, 71], [39, 174, 96]],
        [[92, 61, 46], [200, 168, 110]],
        [[60, 48, 92], [163, 113, 247]],
        [[90, 42, 52], [192, 57, 43]],
        [[33, 47, 61], [139, 157, 175]],
    ];

    public function __construct(
        private readonly AssetManager $assetManager,
    ) {
    }

    public function isAvailable(): bool
    {
        return \extension_loaded('gd') && \function_exists('imagejpeg');
    }

    /**
     * Creates one image and returns the stored asset, or null when GD is missing
     * or the upload was refused.
     *
     * @param int $variant slide number; picks the palette and is drawn on the image
     */
    public function create(string $title, int $variant = 0): ?Asset
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $path = null;

        try {
            $path = $this->draw($title, $variant);

            if ($path === null) {
                return null;
            }

            // test: true — there is no real HTTP upload behind this file, and
            // without it UploadedFile::isValid() rejects it on is_uploaded_file().
            $file = new UploadedFile($path, $this->fileName($title, $variant), 'image/jpeg', null, true);

            return $this->assetManager->upload($file, ['image/'], 4 * 1024 * 1024);
        } catch (\Throwable) {
            return null;
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @return list<Asset>
     */
    public function createSet(string $title, int $count): array
    {
        $assets = [];

        for ($i = 0; $i < max(1, $count); ++$i) {
            $asset = $this->create($title, $i);

            if ($asset instanceof Asset) {
                $assets[] = $asset;
            }
        }

        return $assets;
    }

    /**
     * Draws a gradient card with the title on it and returns the temp file path.
     */
    private function draw(string $title, int $variant): ?string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($image === false) {
            return null;
        }

        try {
            [$from, $to] = self::PALETTES[$variant % \count(self::PALETTES)];

            // Vertical gradient, one allocated colour per row.
            for ($y = 0; $y < self::HEIGHT; ++$y) {
                $ratio = $y / self::HEIGHT;
                $colour = imagecolorallocate(
                    $image,
                    (int) ($from[0] + ($to[0] - $from[0]) * $ratio),
                    (int) ($from[1] + ($to[1] - $from[1]) * $ratio),
                    (int) ($from[2] + ($to[2] - $from[2]) * $ratio),
                );

                if ($colour !== false) {
                    imageline($image, 0, $y, self::WIDTH, $y, $colour);
                }
            }

            $this->drawGrid($image);
            $this->drawLabel($image, $title, $variant);

            $path = tempnam(sys_get_temp_dir(), 'showcase-demo-');

            if ($path === false) {
                return null;
            }

            // The temp file has no extension; AssetManager reads the real type
            // from content with finfo, so that is fine.
            if (!imagejpeg($image, $path, 82)) {
                @unlink($path);

                return null;
            }

            return $path;
        } finally {
            imagedestroy($image);
        }
    }

    private function drawGrid(\GdImage $image): void
    {
        $line = imagecolorallocatealpha($image, 255, 255, 255, 110);

        if ($line === false) {
            return;
        }

        for ($x = 0; $x < self::WIDTH; $x += 60) {
            imageline($image, $x, 0, $x, self::HEIGHT, $line);
        }

        for ($y = 0; $y < self::HEIGHT; $y += 60) {
            imageline($image, 0, $y, self::WIDTH, $y, $line);
        }
    }

    private function drawLabel(\GdImage $image, string $title, int $variant): void
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        $shadow = imagecolorallocatealpha($image, 0, 0, 0, 80);

        if ($white === false) {
            return;
        }

        // imagestring() only draws ASCII, so Turkish characters are folded rather
        // than dropped — a demo image reading "Konut Projesi" beats one reading
        // "K?n?t Pr?j?si".
        $text = $this->asciiFold($title);
        $text = mb_strlen($text) > 34 ? mb_substr($text, 0, 31).'...' : $text;

        $font = 5;
        $textWidth = imagefontwidth($font) * \strlen($text);
        $x = (int) ((self::WIDTH - $textWidth) / 2);
        $y = (int) (self::HEIGHT / 2) - 20;

        if ($shadow !== false) {
            imagestring($image, $font, $x + 2, $y + 2, $text, $shadow);
        }

        imagestring($image, $font, $x, $y, $text, $white);

        $caption = sprintf('DEMO %d', $variant + 1);
        $captionWidth = imagefontwidth(3) * \strlen($caption);
        imagestring($image, 3, (int) ((self::WIDTH - $captionWidth) / 2), $y + 40, $caption, $white);
    }

    private function asciiFold(string $value): string
    {
        $map = [
            'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
            'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U',
        ];

        $folded = strtr($value, $map);

        return (string) preg_replace('/[^\x20-\x7E]/', '', $folded);
    }

    private function fileName(string $title, int $variant): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $this->asciiFold($title)));
        $slug = trim($slug, '-');

        return ($slug !== '' ? $slug : 'showcase').'-'.($variant + 1).'.jpg';
    }
}

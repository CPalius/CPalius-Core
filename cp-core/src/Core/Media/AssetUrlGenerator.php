<?php

declare(strict_types=1);

namespace App\Core\Media;

use App\Core\Settings\SettingsRegistry;

/**
 * The one place that turns a storage key into a URL a browser can fetch.
 *
 * Before this existed, "/uploads/".$key was written out at six call sites, which
 * is fine right up until the answer stops being the same everywhere — and a CDN
 * is exactly that moment.
 *
 * THE CONTRACT: cdn.base_url REPLACES "/uploads". Nothing else.
 *
 *   pull-zone in front of this origin   https://cdn.example.com/uploads
 *   public bucket (R2/S3/Spaces)        https://pub-xxxx.r2.dev
 *
 * One rule covers both because the difference between them is precisely where
 * the operator's files are rooted, and they are the only one who knows. The
 * alternative — inferring it from whether offload happens to be on — would give
 * the pull-zone operator, who never configures a bucket at all, a URL with a
 * "/uploads" that their CDN was already adding.
 *
 * The CDN switch is deliberately independent of offload. A pull zone needs no
 * upload whatsoever: it fetches from this origin on a miss and caches the
 * result. Requiring a bucket before allowing a CDN would have made the cheapest
 * and most common setup the one CPalius could not express.
 */
final class AssetUrlGenerator
{
    public const LOCAL_PREFIX = '/uploads/';

    /**
     * Extensions the images_only switch lets through.
     *
     * The default is images-only because the rest of what lands in uploads is
     * documents, and a PDF served from a CDN hostname is a PDF that has left
     * the origin's access rules behind — whatever public/uploads/.htaccess was
     * doing about it no longer applies.
     *
     * @var list<string>
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico'];

    /** Request-scoped memo: false means "resolved, no CDN". */
    private string|false|null $base = null;

    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    /**
     * Public URL for a storage key such as "2026/09/<hash>.jpg".
     * An empty or unsafe key yields an empty string, never a broken URL.
     */
    public function forKey(?string $storageKey): string
    {
        $key = $this->normalizeKey($storageKey);

        if ($key === '') {
            return '';
        }

        $base = $this->base();

        if ($base === null || !$this->isEligible($key)) {
            return self::LOCAL_PREFIX.$key;
        }

        return $base.'/'.$key;
    }

    /**
     * Applies the CDN to a URL that already points at local uploads.
     *
     * For callers that receive "/uploads/..." from somewhere else — chiefly
     * ImageProcessor, which deals in files on disk and has no business knowing
     * a CDN exists. Anything that is not a local uploads URL is returned
     * untouched, so an absolute URL that has already been rewritten cannot be
     * rewritten twice.
     */
    public function rewrite(string $url): string
    {
        if (!str_starts_with($url, self::LOCAL_PREFIX)) {
            return $url;
        }

        return $this->forKey(substr($url, strlen(self::LOCAL_PREFIX)));
    }

    public function isCdnEnabled(): bool
    {
        return $this->base() !== null;
    }

    /**
     * Normalised CDN root without a trailing slash, or null when it is off,
     * unset, or not something we are willing to emit into a page.
     */
    private function base(): ?string
    {
        if ($this->base !== null) {
            return $this->base === false ? null : $this->base;
        }

        if ((bool) $this->settings->get('cdn.enabled', false) !== true) {
            $this->base = false;

            return null;
        }

        $raw = trim((string) $this->settings->get('cdn.base_url', ''));

        // Scheme-relative and plain-http bases are refused rather than
        // normalised: a CDN host injected into every <img> on the site is worth
        // being strict about, and an operator who mistypes it should find out
        // from a screen that says so, not from a page of broken images.
        if ($raw === '' || preg_match('#^https://[a-z0-9.\-]+(:\d+)?(/[^\s"\'<>]*)?$#i', $raw) !== 1) {
            $this->base = false;

            return null;
        }

        return $this->base = rtrim($raw, '/');
    }

    private function isEligible(string $key): bool
    {
        if ((bool) $this->settings->get('cdn.images_only', true) !== true) {
            return true;
        }

        return in_array(strtolower(pathinfo($key, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Accepts a raw key or a "/uploads/..." URL and returns a traversal-free
     * key. Mirrors ImageProcessor::normalizeKey — the two agree on purpose,
     * because a key that one of them rejects and the other accepts is a URL
     * pointing at a file that will never be generated.
     */
    private function normalizeKey(?string $source): string
    {
        $key = trim((string) $source);

        if (str_starts_with($key, self::LOCAL_PREFIX)) {
            $key = substr($key, strlen(self::LOCAL_PREFIX));
        }

        $key = ltrim(str_replace('\\', '/', $key), '/');

        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            return '';
        }

        return $key;
    }
}

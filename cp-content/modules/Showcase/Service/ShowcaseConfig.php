<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Settings\SettingsRegistry;

/**
 * Typed reader for the module's settings.
 *
 * Every value is clamped here rather than trusted from the settings table: an
 * operator who types 100000 into "images per item" should not be able to turn a
 * submission form into a disk-filling tool, and a blank currency should not reach
 * the price formatter.
 */
final class ShowcaseConfig
{
    private const MIN_PER_PAGE = 1;
    private const MAX_PER_PAGE = 60;
    private const MAX_GALLERY_HARD_LIMIT = 30;
    private const MAX_IMAGE_MB_HARD_LIMIT = 20;

    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function itemsPerPage(): int
    {
        return $this->clamp($this->settings->get('showcase.items_per_page', 12), self::MIN_PER_PAGE, self::MAX_PER_PAGE, 12);
    }

    public function requiresApproval(): bool
    {
        return (bool) $this->settings->get('showcase.require_approval', true);
    }

    /**
     * 0 means "no cap" — an explicit choice an operator can make, so it is not
     * clamped up to 1.
     */
    public function maxItemsPerMember(): int
    {
        $value = $this->settings->get('showcase.max_items_per_member', 20);

        return is_numeric($value) ? max(0, (int) $value) : 20;
    }

    public function maxGalleryImages(): int
    {
        return $this->clamp($this->settings->get('showcase.max_gallery_images', 8), 0, self::MAX_GALLERY_HARD_LIMIT, 8);
    }

    public function maxImageBytes(): int
    {
        $mb = $this->clamp($this->settings->get('showcase.max_image_mb', 4), 1, self::MAX_IMAGE_MB_HARD_LIMIT, 4);

        return $mb * 1024 * 1024;
    }

    public function defaultCurrency(): string
    {
        $currency = strtoupper(trim((string) $this->settings->get('showcase.default_currency', 'TRY')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'TRY';
    }

    /**
     * Submissions allowed per member per hour. Zero disables the throttle, which
     * the settings screen documents as "trusted community only".
     */
    public function submitRateLimit(): int
    {
        $value = $this->settings->get('showcase.submit_rate_limit', 10);

        return is_numeric($value) ? max(0, min(200, (int) $value)) : 10;
    }

    public function reviewsEnabled(): bool
    {
        return (bool) $this->settings->get('showcase.reviews_enabled', true);
    }

    public function reviewsRequireApproval(): bool
    {
        return (bool) $this->settings->get('showcase.reviews_require_approval', true);
    }

    public function reviewsAllowOwner(): bool
    {
        return (bool) $this->settings->get('showcase.reviews_allow_owner', false);
    }

    public function listingLayout(): string
    {
        $layout = (string) $this->settings->get('showcase.listing_layout', 'grid');

        return \in_array($layout, ['grid', 'list'], true) ? $layout : 'grid';
    }

    public function heroTitle(string $locale): string
    {
        return trim((string) $this->settings->getForLocale('showcase.hero_title', $locale, ''));
    }

    public function heroDescription(string $locale): string
    {
        return trim((string) $this->settings->getForLocale('showcase.hero_description', $locale, ''));
    }

    public function seoTitlePattern(): string
    {
        $pattern = trim((string) $this->settings->get('showcase.seo_title_pattern', ''));

        return $pattern !== '' ? $pattern : '%title% - %site_name%';
    }

    private function clamp(mixed $raw, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($raw)) {
            return $fallback;
        }

        return max($min, min($max, (int) $raw));
    }
}

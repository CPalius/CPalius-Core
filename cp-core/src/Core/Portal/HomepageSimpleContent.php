<?php

declare(strict_types=1);

namespace App\Core\Portal;

use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Copy of the built-in "simple" and "feed" portal templates.
 *
 * Stored as ordinary translatable settings ({"tr": "...", "en": "..."}), so it
 * follows the same locale fallback as the site name: a language left empty
 * shows the default language rather than a blank page.
 */
final class HomepageSimpleContent
{
    public const TEMPLATE_KEY = 'homepage.portal.template';
    public const TEMPLATES = ['simple', 'feed', 'showcase'];

    /** Per-language fields, in editor order. */
    public const FIELDS = ['title', 'subtitle', 'body', 'cta_label', 'cta_url', 'cta2_label', 'cta2_url'];

    private const LINK_FIELDS = ['cta_url', 'cta2_url'];
    private const MODULE = 'studio_homepage';

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function template(): string
    {
        $template = (string) $this->settings->get(self::TEMPLATE_KEY, 'showcase');

        return \in_array($template, self::TEMPLATES, true) ? $template : 'showcase';
    }

    /**
     * What the page shows for one locale (with default-language fallback).
     *
     * @return array{title: string, subtitle: string, body: string, cta_label: string, cta_url: string, cta2_label: string, cta2_url: string, image: string}
     */
    public function forLocale(string $locale): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string) $this->settings->getForLocale('homepage.simple.'.$field, $locale, ''));
            $out[$field] = \in_array($field, self::LINK_FIELDS, true) ? self::safeUrl($value) : $value;
        }
        $out['image'] = self::safeUrl(trim((string) $this->settings->get('homepage.simple.image', '')));

        if ($out['title'] === '') {
            $out['title'] = trim((string) $this->settings->getForLocale('core.site_name', $locale, ''));
        }
        if ($out['subtitle'] === '' && $out['body'] === '') {
            $out['subtitle'] = trim((string) $this->settings->getForLocale('core.default_meta_description', $locale, ''));
        }

        return $out;
    }

    /**
     * The same page built from an editor's unsaved draft instead of the stored
     * settings (the preview frame). Link fields get the same safeUrl filter.
     *
     * @param array<string, mixed> $byLocale locale => [field => value]
     *
     * @return array{title: string, subtitle: string, body: string, cta_label: string, cta_url: string, cta2_label: string, cta2_url: string, image: string}
     */
    public function forLocaleFromDraft(array $byLocale, string $image, string $locale): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $byLocale[$locale][$field] ?? '';
            $value = \is_scalar($value) ? trim((string) $value) : '';
            $out[$field] = \in_array($field, self::LINK_FIELDS, true) ? self::safeUrl($value) : $value;
        }
        $out['image'] = self::safeUrl(trim($image));
        if ($out['title'] === '') {
            $out['title'] = trim((string) $this->settings->getForLocale('core.site_name', $locale, ''));
        }

        return $out;
    }

    /**
     * Exactly what is stored per active language, for the editor (no fallback,
     * so an untranslated field shows as empty rather than as another language).
     *
     * @return array<string, array<string, string>> locale => field => value
     */
    public function editorValues(): array
    {
        $out = [];
        foreach ($this->localeProvider->getCodes() as $code) {
            $out[$code] = array_fill_keys(self::FIELDS, '');
        }

        foreach (self::FIELDS as $field) {
            $map = json_decode((string) $this->settings->getRaw('homepage.simple.'.$field), true);
            if (!\is_array($map)) {
                continue;
            }
            foreach ($out as $code => $_) {
                $out[$code][$field] = \is_string($map[$code] ?? null) ? $map[$code] : '';
            }
        }

        return $out;
    }

    public function image(): string
    {
        return trim((string) $this->settings->get('homepage.simple.image', ''));
    }

    /**
     * @param array<string, mixed> $byLocale locale => [field => value]
     */
    public function save(string $template, array $byLocale, string $image): void
    {
        $this->upsert(self::TEMPLATE_KEY, \in_array($template, self::TEMPLATES, true) ? $template : 'showcase');
        $this->upsert('homepage.simple.image', self::safeUrl(trim($image)));

        $codes = $this->localeProvider->getCodes();
        foreach (self::FIELDS as $field) {
            $map = [];
            foreach ($codes as $code) {
                $value = $byLocale[$code][$field] ?? '';
                $value = \is_scalar($value) ? trim((string) $value) : '';
                $map[$code] = \in_array($field, self::LINK_FIELDS, true) ? self::safeUrl($value) : $value;
            }
            $this->upsert('homepage.simple.'.$field, (string) json_encode($map, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        }

        $this->entityManager->flush();
        $this->settings->clearCache();
    }

    /** Relative paths, http(s) and mailto only — never javascript: or data:. */
    public static function safeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        return preg_match('~^(https?://|mailto:)~i', $url) === 1 ? $url : '';
    }

    private function upsert(string $key, string $value): void
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof Setting) {
            $setting = new Setting($key, self::MODULE);
            $this->entityManager->persist($setting);
        }
        $setting->setSettingValue($value);
    }
}

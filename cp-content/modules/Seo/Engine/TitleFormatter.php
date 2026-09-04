<?php

declare(strict_types=1);

namespace Modules\Seo\Engine;

use App\Core\Settings\SettingsRegistry;

final class TitleFormatter
{
    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function format(string $title, string $templateKey, string $locale): string
    {
        $title = trim($title);
        $siteName = trim((string) $this->settings->getForLocale('core.site_name', $locale, 'CPalius CMF'));
        $template = $this->firstNonEmpty([
            (string) $this->settings->getForLocale($templateKey, $locale, ''),
            $templateKey === 'seo.blog.title_template'
                ? (string) $this->settings->getForLocale('blog.seo_title_pattern', $locale, '')
                : '',
            (string) $this->settings->getForLocale('core.default_meta_title_format', $locale, ''),
            '%title% – %site_name%',
        ]);
        $template = str_replace('%%', '%', $template);

        if ($title === '') {
            $fallback = trim((string) $this->settings->getForLocale('seo.default_title', $locale, ''));
            $title = $fallback !== '' ? $fallback : $siteName;
        }

        $rendered = strtr($template, [
            '%title%' => $title,
            '%site_name%' => $siteName,
            '%locale%' => $locale,
        ]);

        return trim($rendered) !== '' ? $rendered : $title;
    }

    /**
     * @param list<string> $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '%title% – %site_name%';
    }
}

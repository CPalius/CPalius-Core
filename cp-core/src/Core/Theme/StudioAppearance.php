<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Font\FontLibrary;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How Studio looks, as chosen by an administrator in AACP.
 *
 * Three themes share the same pages and the same data:
 *   classic  the original Studio
 *   ledger   the flat, ruled "type case" layout
 *   premium  the modern, calm workspace (default)
 * On top of a theme the administrator picks a mode (light, dark, follow the
 * device), an accent colour and a typeface from the installed font library.
 * Editors never configure any of it; the choice applies to everyone.
 */
final class StudioAppearance
{
    public const KEY = 'studio.appearance';
    public const THEMES = ['premium', 'classic', 'ledger'];
    public const MODES = ['auto', 'light', 'dark'];

    /** @var array<string, string> palette id => accent hex */
    public const PALETTES = [
        'jade' => '#0F8F7E',
        'cobalt' => '#2D5BD8',
        'violet' => '#6B4FD1',
        'rose' => '#C93C5E',
        'amber' => '#C77700',
        'graphite' => '#3B4450',
    ];

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly FontLibrary $fonts,
    ) {
    }

    /**
     * @return array{theme: string, mode: string, accent: string, onAccent: string, font: string, fontCss: string}
     *         accent and font are empty strings when the theme's own default applies
     */
    public function read(): array
    {
        $decoded = json_decode((string) $this->settings->get(self::KEY, '{}'), true);
        $stored = \is_array($decoded) ? $decoded : [];

        $theme = \in_array($stored['theme'] ?? null, self::THEMES, true) ? $stored['theme'] : 'premium';
        $mode = \in_array($stored['mode'] ?? null, self::MODES, true) ? $stored['mode'] : 'auto';

        // 2.2.35 stored a "spot" colour; keep honouring it as the accent.
        $raw = (string) ($stored['accent'] ?? '');
        if ($raw === '' && ($stored['style'] ?? '') === 'spot') {
            $raw = (string) ($stored['spot'] ?? '');
        }
        $accent = strtoupper(trim($raw));
        if (preg_match('/^#[0-9A-F]{6}$/', $accent) !== 1) {
            $accent = '';
        }

        $font = '';
        $fontCss = '';
        $slug = (string) ($stored['font'] ?? '');
        if ($slug !== '' && ($family = $this->fonts->read($slug)) !== null) {
            $font = $slug;
            // Letters, digits, space, dash and underscore only: the value lands in a style attribute.
            $fontCss = "'".preg_replace('/[^A-Za-z0-9 _-]/', '', $family->name)."'";
        }

        return [
            'theme' => $theme,
            'mode' => $mode,
            'accent' => $accent,
            'onAccent' => $accent === '' ? '' : $this->readableOn($accent),
            'font' => $font,
            'fontCss' => $fontCss,
        ];
    }

    public function save(string $theme, string $mode, string $accent, string $font): void
    {
        $accent = strtoupper(trim($accent));
        $value = json_encode([
            'theme' => \in_array($theme, self::THEMES, true) ? $theme : 'premium',
            'mode' => \in_array($mode, self::MODES, true) ? $mode : 'auto',
            'accent' => preg_match('/^#[0-9A-F]{6}$/', $accent) === 1 ? $accent : '',
            // Only a family that is really installed: this ends up in a style attribute.
            'font' => $font !== '' && $this->fonts->read($font) !== null ? $font : '',
        ], JSON_THROW_ON_ERROR);

        $setting = $this->repository->findIndexedByKeys([self::KEY])[self::KEY] ?? null;
        if (!$setting instanceof Setting) {
            $setting = new Setting(self::KEY, 'core');
            $this->em->persist($setting);
        }
        $setting->setSettingValue($value);
        $this->em->flush();
        $this->settings->clearCache(self::KEY);
    }

    /** Black or white text, whichever reads better on the given hex (WCAG relative luminance). */
    private function readableOn(string $hex): string
    {
        $lin = static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((int) hexdec(substr($hex, 1, 2)))
            + 0.7152 * $lin((int) hexdec(substr($hex, 3, 2)))
            + 0.0722 * $lin((int) hexdec(substr($hex, 5, 2)));

        return $l > 0.179 ? '#14181C' : '#FFFFFF';
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Public brand and the color tokens the website theme already reads.
 * Empty colors stay on the theme default; a saved hex overrides that one variable.
 */
final class SiteAppearance
{
    public const KEY = 'core.appearance';

    /** @var array<string, array{default: int, min: int, max: int}> */
    public const METRICS = [
        'logo_height' => ['default' => 32, 'min' => 16, 'max' => 160],
        'logo_width' => ['default' => 0, 'min' => 0, 'max' => 480],
        'site_width' => ['default' => 1200, 'min' => 960, 'max' => 1680],
        'navbar_height' => ['default' => 64, 'min' => 48, 'max' => 120],
    ];

    /** @var array<string, array{var: string, default: string, group: string}> */
    public const COLORS = [
        'primary' => ['var' => '--cp-primary', 'default' => '#4A7C9B', 'group' => 'accent'],
        'primary_dark' => ['var' => '--cp-primary-dark', 'default' => '#3A6A87', 'group' => 'accent'],
        'primary_light' => ['var' => '--cp-primary-light', 'default' => '#5A8CAB', 'group' => 'accent'],
        'accent' => ['var' => '--cp-accent', 'default' => '#4AADE4', 'group' => 'accent'],
        'accent_hover' => ['var' => '--cp-accent-hover', 'default' => '#3A9BD4', 'group' => 'accent'],
        'gold' => ['var' => '--cp-gold', 'default' => '#C8A86E', 'group' => 'accent'],
        'dark' => ['var' => '--cp-dark', 'default' => '#1A2A3A', 'group' => 'surface'],
        'darker' => ['var' => '--cp-darker', 'default' => '#0D1B2A', 'group' => 'surface'],
        'light' => ['var' => '--cp-light', 'default' => '#F0F2F5', 'group' => 'surface'],
        'lighter' => ['var' => '--cp-lighter', 'default' => '#F7F8FA', 'group' => 'surface'],
        'white' => ['var' => '--cp-white', 'default' => '#FFFFFF', 'group' => 'surface'],
        'text' => ['var' => '--cp-text', 'default' => '#2C3E50', 'group' => 'text'],
        'text_secondary' => ['var' => '--cp-text-secondary', 'default' => '#5A6B7D', 'group' => 'text'],
        'text_muted' => ['var' => '--cp-text-muted', 'default' => '#8B9DAF', 'group' => 'text'],
        'border' => ['var' => '--cp-border', 'default' => '#D0D5DD', 'group' => 'text'],
        'success' => ['var' => '--cp-success', 'default' => '#27AE60', 'group' => 'status'],
        'danger' => ['var' => '--cp-danger', 'default' => '#C0392B', 'group' => 'status'],
    ];

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @return array{logo: string, favicon: string, og: string, colors: array<string, string>, metrics: array<string, int>}
     */
    public function read(): array
    {
        $decoded = json_decode((string) $this->settings->get(self::KEY, '{}'), true);
        $stored = \is_array($decoded) ? $decoded : [];
        $colors = [];
        $savedColors = \is_array($stored['colors'] ?? null) ? $stored['colors'] : [];
        foreach (self::COLORS as $name => $meta) {
            $value = strtoupper(trim((string) ($savedColors[$name] ?? '')));
            $colors[$name] = preg_match('/^#[0-9A-F]{6}$/', $value) === 1 ? $value : $meta['default'];
        }

        return [
            'logo' => $this->publicPath($stored['logo'] ?? '', '/CPalius.png'),
            'favicon' => $this->publicPath($stored['favicon'] ?? '', '/CPalius.png'),
            'og' => $this->publicPath($stored['og'] ?? '', ''),
            'colors' => $colors,
            'metrics' => $this->metrics(\is_array($stored['metrics'] ?? null) ? $stored['metrics'] : []),
        ];
    }

    public function css(): string
    {
        $appearance = $this->read();
        $rules = [];
        foreach (self::COLORS as $name => $meta) {
            $rules[] = $meta['var'].':'.$appearance['colors'][$name];
        }
        $metrics = $appearance['metrics'];
        $rules[] = '--container-max:'.$metrics['site_width'].'px';
        $rules[] = '--navbar-h:'.$metrics['navbar_height'].'px';
        $logoWidth = $metrics['logo_width'] > 0 ? $metrics['logo_width'].'px' : 'none';

        return ':root{'.implode(';', $rules).'}'
            .'.navbar-logo,.footer-logo{height:'.$metrics['logo_height'].'px;width:auto;max-width:'.$logoWidth.';object-fit:contain}'
            .'.dns-home-wrap{max-width:'.$metrics['site_width'].'px}';
    }

    /**
     * @param array<string, mixed> $colors
     * @param array<string, mixed> $metrics
     * @param array<string, UploadedFile|null> $files
     */
    public function save(array $colors, array $metrics, array $files, bool $clearLogo, bool $clearFavicon, bool $clearOg): void
    {
        $current = json_decode((string) $this->settings->get(self::KEY, '{}'), true);
        $stored = \is_array($current) ? $current : [];
        $nextColors = [];
        foreach (self::COLORS as $name => $meta) {
            $value = strtoupper(trim((string) ($colors[$name] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $value) === 1 && $value !== $meta['default']) {
                $nextColors[$name] = $value;
            }
        }

        $logo = $clearLogo ? '' : (string) ($stored['logo'] ?? '');
        $favicon = $clearFavicon ? '' : (string) ($stored['favicon'] ?? '');
        $og = $clearOg ? '' : (string) ($stored['og'] ?? '');
        if (($files['logo'] ?? null) instanceof UploadedFile) {
            $logo = $this->store($files['logo'], 'logo');
        }
        if (($files['favicon'] ?? null) instanceof UploadedFile) {
            $favicon = $this->store($files['favicon'], 'favicon');
        }
        if (($files['og'] ?? null) instanceof UploadedFile) {
            $og = $this->store($files['og'], 'og');
        }

        $json = json_encode([
            'logo' => $logo,
            'favicon' => $favicon,
            'og' => $og,
            'colors' => $nextColors,
            'metrics' => $this->storedMetrics($metrics),
        ], JSON_THROW_ON_ERROR);

        $setting = $this->repository->findOneBy(['settingKey' => self::KEY]);
        if (!$setting instanceof Setting) {
            $setting = new Setting(self::KEY, 'theme_manager');
            $this->em->persist($setting);
        }
        $setting->setSettingValue($json);
        $this->em->flush();
        $this->settings->clearCache(self::KEY);
    }

    private function publicPath(mixed $value, string $fallback): string
    {
        $path = trim((string) $value);
        if ($path === '' || str_contains($path, '..') || !str_starts_with($path, '/')) {
            return $fallback;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, int>
     */
    private function metrics(array $raw): array
    {
        $metrics = [];
        foreach (self::METRICS as $name => $meta) {
            $metrics[$name] = $this->clamp((int) ($raw[$name] ?? $meta['default']), $meta['min'], $meta['max']);
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, int>
     */
    private function storedMetrics(array $raw): array
    {
        $stored = [];
        foreach (self::METRICS as $name => $meta) {
            $value = $this->clamp((int) ($raw[$name] ?? $meta['default']), $meta['min'], $meta['max']);
            if ($value !== $meta['default']) {
                $stored[$name] = $value;
            }
        }

        return $stored;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function store(UploadedFile $file, string $name): string
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        if (!\in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'], true)) {
            throw new \InvalidArgumentException('aacp.appearance.bad_file');
        }
        if ($file->getSize() > 2_000_000) {
            throw new \InvalidArgumentException('aacp.appearance.bad_file');
        }

        $dir = $this->kernel->getProjectDir().'/public/uploads/appearance';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('aacp.appearance.bad_file');
        }
        $target = $name.'.'.$ext;
        $file->move($dir, $target);

        return '/uploads/appearance/'.$target;
    }
}

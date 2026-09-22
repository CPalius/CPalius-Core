<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\DnsTools\Catalog\ToolDefinition;

/**
 * Studio overrides for per-tool visibility and locale SEO. Catalogue code stays the default.
 */
final class ToolConfigStore
{
    public const SETTING_KEY = 'dnstools.catalog';

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $raw = $this->settings->get(self::SETTING_KEY, '{}');
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{enabled: bool, featured: bool, is_new: bool, locales: array<string, array<string, string>>}
     */
    public function tool(string $slug, ToolDefinition $fallback): array
    {
        $row = $this->payload()['tools'][$slug] ?? [];
        if (!\is_array($row)) {
            $row = [];
        }

        return [
            'enabled' => array_key_exists('enabled', $row) ? (bool) $row['enabled'] : true,
            'featured' => array_key_exists('featured', $row) ? (bool) $row['featured'] : $fallback->featured,
            'is_new' => array_key_exists('is_new', $row) ? (bool) $row['is_new'] : $fallback->isNew,
            'locales' => \is_array($row['locales'] ?? null) ? $row['locales'] : [],
        ];
    }

    public function isEnabled(string $slug, ToolDefinition $fallback): bool
    {
        return $this->tool($slug, $fallback)['enabled'];
    }

    /**
     * @return array{title: string, description: string, keywords: string}
     */
    public function page(string $page, string $locale): array
    {
        $row = $this->payload()['pages'][$page][$locale] ?? [];
        if (!\is_array($row)) {
            $row = [];
        }

        return [
            'title' => trim((string) ($row['title'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'keywords' => trim((string) ($row['keywords'] ?? '')),
        ];
    }

    public function forumEnabled(): bool
    {
        $payload = $this->payload();
        if (isset($payload['forum']['enabled'])) {
            return (bool) $payload['forum']['enabled'];
        }

        return (string) $this->settings->get('dnstools.forum_enabled', '1') === '1';
    }

    public function forumSection(): string
    {
        $payload = $this->payload();
        if (isset($payload['forum']['section'])) {
            return trim((string) $payload['forum']['section']);
        }

        return trim((string) $this->settings->get('dnstools.forum_section', ''));
    }

    /**
     * @param array<string, mixed> $toolRow
     */
    public function saveTool(string $slug, array $toolRow): void
    {
        $payload = $this->payload();
        $tools = \is_array($payload['tools'] ?? null) ? $payload['tools'] : [];
        $tools[$slug] = $toolRow;
        $payload['tools'] = $tools;
        $this->write($payload);
    }

    /**
     * @param array<string, mixed> $pages
     */
    public function savePages(array $pages): void
    {
        $payload = $this->payload();
        $payload['pages'] = $pages;
        $this->write($payload);
    }

    public function saveForum(bool $enabled, string $section): void
    {
        $payload = $this->payload();
        $payload['forum'] = [
            'enabled' => $enabled,
            'section' => $section,
        ];
        $this->write($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!\is_string($json)) {
            return;
        }

        $setting = $this->settingRepository->findIndexedByKeys([self::SETTING_KEY])[self::SETTING_KEY] ?? null;
        if (!$setting instanceof Setting) {
            $setting = new Setting(self::SETTING_KEY, 'dnstools');
            $this->entityManager->persist($setting);
        }
        $setting->setSettingValue($json);
        $this->entityManager->flush();
        $this->settings->clearCache(self::SETTING_KEY);
    }
}

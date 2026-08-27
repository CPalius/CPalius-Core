<?php

declare(strict_types=1);

namespace App\Core\Portal;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Studio portal layout JSON'unu okur, doğrular ve kaydeder.
 */
final class PortalLayoutService
{
    public const SETTING_KEY = 'homepage.portal.layout';
    public const SETTINGS_MODULE = 'studio_homepage';

    private const ALLOWED_LAYOUTS = [
        PortalBlockCatalog::LAYOUT_LIST,
        PortalBlockCatalog::LAYOUT_CARDS,
        PortalBlockCatalog::LAYOUT_SLIDER,
    ];

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, array{
     *   label: string,
     *   group: string,
     *   supportsLimit: bool,
     *   supportsLayout: bool,
     *   supportsHeroFields: bool,
     *   default: array<string, mixed>
     * }>
     */
    public function getCatalog(): array
    {
        return PortalBlockCatalog::definitions();
    }

    /**
     * Sıralı tam layout (eksik bloklar catalog default ile tamamlanır).
     *
     * @return list<array<string, mixed>>
     */
    public function getLayout(): array
    {
        $raw = $this->settingsRegistry->get(self::SETTING_KEY);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded) || $decoded === []) {
            return $this->applyLegacyWidgetFlags(PortalBlockCatalog::defaultLayout());
        }

        return $this->normalizeLayout($decoded);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEnabledBlocks(): array
    {
        return array_values(array_filter(
            $this->getLayout(),
            static fn (array $block): bool => (bool) ($block['enabled'] ?? false),
        ));
    }

    /**
     * @param list<array<string, mixed>>|array<int, array<string, mixed>> $blocks
     */
    public function saveLayout(array $blocks): void
    {
        $normalized = $this->normalizeLayout($blocks);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('Portal layout JSON encode failed.');
        }

        $setting = $this->settingRepository->findOneBy(['settingKey' => self::SETTING_KEY]);
        if (!$setting instanceof Setting) {
            $setting = new Setting(self::SETTING_KEY, self::SETTINGS_MODULE);
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($json);
        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();
    }

    /**
     * @param list<array<string, mixed>>|array<int, array<string, mixed>> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeLayout(array $blocks): array
    {
        $catalog = PortalBlockCatalog::definitions();
        $seen = [];
        $normalized = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $id = (string) ($block['id'] ?? '');
            if ($id === '' || !isset($catalog[$id]) || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $normalized[] = $this->sanitizeBlock($id, $block, $catalog[$id]);
        }

        foreach (PortalBlockCatalog::defaultLayout() as $defaultBlock) {
            $id = (string) $defaultBlock['id'];
            if (isset($seen[$id])) {
                continue;
            }

            $normalized[] = $defaultBlock;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $block
     * @param array{
     *   label: string,
     *   group: string,
     *   supportsLimit: bool,
     *   supportsLayout: bool,
     *   supportsHeroFields: bool,
     *   default: array<string, mixed>
     * } $meta
     *
     * @return array<string, mixed>
     */
    private function sanitizeBlock(string $id, array $block, array $meta): array
    {
        $default = $meta['default'];
        $result = [
            'id' => $id,
            'enabled' => (bool) ($block['enabled'] ?? $default['enabled'] ?? false),
            'title' => trim((string) ($block['title'] ?? $default['title'] ?? '')),
        ];

        if ($meta['supportsLimit']) {
            $limit = (int) ($block['limit'] ?? $default['limit'] ?? 5);
            $result['limit'] = max(3, min(20, $limit));
        }

        if ($meta['supportsLayout']) {
            $layout = (string) ($block['layout'] ?? $default['layout'] ?? PortalBlockCatalog::LAYOUT_LIST);
            $result['layout'] = in_array($layout, self::ALLOWED_LAYOUTS, true)
                ? $layout
                : PortalBlockCatalog::LAYOUT_LIST;
        }

        if ($meta['supportsHeroFields']) {
            foreach ([
                'label',
                'subtitle',
                'description',
                'cta_primary_label',
                'cta_primary_href',
                'cta_secondary_label',
                'cta_secondary_href',
                'badges',
            ] as $field) {
                $result[$field] = trim((string) ($block[$field] ?? $default[$field] ?? ''));
            }

            if ($result['title'] === '') {
                $result['title'] = (string) ($default['title'] ?? 'CPalius CMF');
            }
        }

        return $result;
    }

    /**
     * Eski homepage.widget.* checkbox değerlerini enabled bayraklarına yansıtır.
     *
     * @param list<array<string, mixed>> $layout
     *
     * @return list<array<string, mixed>>
     */
    private function applyLegacyWidgetFlags(array $layout): array
    {
        $legacyMap = [
            'latest_forum_topics' => 'homepage.widget.latest_forum_topics',
            'latest_blog_posts' => 'homepage.widget.latest_blog_posts',
            'popular_forum_topics' => 'homepage.widget.popular_forum_topics',
        ];

        foreach ($layout as &$block) {
            $id = (string) ($block['id'] ?? '');
            $legacyKey = $legacyMap[$id] ?? null;
            if ($legacyKey === null) {
                continue;
            }

            $definition = $this->settingsRegistry->getDefinition($legacyKey);
            if ($definition === null) {
                // Tanım kaldırıldıysa DB ham değerine bak.
                $rawMap = $this->settingRepository->findAllAsMap();
                if (!array_key_exists($legacyKey, $rawMap)) {
                    continue;
                }
                $block['enabled'] = $rawMap[$legacyKey] === '1';
                continue;
            }

            $block['enabled'] = (bool) $this->settingsRegistry->get($legacyKey);
        }
        unset($block);

        return $layout;
    }
}

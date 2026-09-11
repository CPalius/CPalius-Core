<?php

declare(strict_types=1);

namespace App\Core\Portal;

use App\Core\Module\ModuleContributionCatalog;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads, validates, and persists Studio portal layout JSON.
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
        private readonly ModuleContributionCatalog $contributions,
    ) {
    }

    /**
     * @return array<string, array{
     *   label: string,
     *   group: string,
     *   supportsLimit: bool,
     *   supportsLayout: bool,
     *   supportsHeroFields: bool,
     *   hideOnLanding?: bool,
     *   requiresRoute?: ?string,
     *   legacyWidgetSetting?: ?string,
     *   default: array<string, mixed>
     * }>
     */
    public function getCatalog(): array
    {
        $catalog = [];
        foreach (PortalBlockCatalog::definitions() as $id => $meta) {
            $catalog[$id] = $meta + [
                'hideOnLanding' => false,
                'requiresRoute' => null,
                'legacyWidgetSetting' => null,
            ];
        }

        foreach ($this->contributions->portalBlocks() as $id => $meta) {
            if (\is_string($id) && $id !== '' && \is_array($meta)) {
                $catalog[$id] = $meta;
            }
        }

        return $catalog;
    }

    /**
     * Ordered full layout; missing blocks are filled from catalog defaults.
     *
     * @return list<array<string, mixed>>
     */
    public function getLayout(): array
    {
        $raw = $this->settingsRegistry->get(self::SETTING_KEY);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded) || $decoded === []) {
            return $this->hideLandingFeeds($this->applyLegacyWidgetFlags(PortalBlockCatalog::defaultLayout()));
        }

        return $this->hideLandingFeeds($this->collapseVerboseMarketing($this->normalizeLayout($decoded)));
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
        $catalog = $this->getCatalog();
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

        foreach ($this->orderedCatalogIds($catalog) as $id) {
            if (isset($seen[$id])) {
                continue;
            }

            $normalized[] = $catalog[$id]['default'];
            $seen[$id] = true;
        }

        return $normalized;
    }

    /**
     * Old factory layouts enabled every deep-dive marketing block. Collapse
     * those onto the whitepaper so the public landing stays a spotlight.
     *
     * @param list<array<string, mixed>> $layout
     *
     * @return list<array<string, mixed>>
     */
    private function collapseVerboseMarketing(array $layout): array
    {
        $verbose = PortalBlockCatalog::whitepaperOnlyIds();
        $enabledVerbose = 0;
        foreach ($layout as $block) {
            $id = (string) ($block['id'] ?? '');
            if (($block['enabled'] ?? false) && \in_array($id, $verbose, true)) {
                ++$enabledVerbose;
            }
        }
        if ($enabledVerbose < 8) {
            return $layout;
        }

        foreach ($layout as &$block) {
            $id = (string) ($block['id'] ?? '');
            if (\in_array($id, $verbose, true)) {
                $block['enabled'] = false;
            }
        }
        unset($block);

        return $layout;
    }

    /**
     * Latest blog/forum feeds stay in Studio but are off the public landing.
     *
     * @param list<array<string, mixed>> $layout
     *
     * @return list<array<string, mixed>>
     */
    private function hideLandingFeeds(array $layout): array
    {
        $catalog = $this->getCatalog();
        foreach ($layout as &$block) {
            $id = (string) ($block['id'] ?? '');
            if (($catalog[$id]['hideOnLanding'] ?? false) === true) {
                $block['enabled'] = false;
            }
        }
        unset($block);

        return $layout;
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
     * Core default order first, then module-contributed block ids.
     *
     * @param array<string, array<string, mixed>> $catalog
     *
     * @return list<string>
     */
    private function orderedCatalogIds(array $catalog): array
    {
        $ids = [];
        foreach (PortalBlockCatalog::defaultLayout() as $defaultBlock) {
            $id = (string) ($defaultBlock['id'] ?? '');
            if ($id !== '' && isset($catalog[$id])) {
                $ids[] = $id;
            }
        }

        foreach (array_keys($catalog) as $id) {
            if (!\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Maps legacy homepage.widget.* checkboxes onto enabled flags.
     *
     * @param list<array<string, mixed>> $layout
     *
     * @return list<array<string, mixed>>
     */
    private function applyLegacyWidgetFlags(array $layout): array
    {
        $legacyMap = [];
        foreach ($this->getCatalog() as $id => $meta) {
            $legacyKey = $meta['legacyWidgetSetting'] ?? null;
            if (\is_string($legacyKey) && $legacyKey !== '') {
                $legacyMap[$id] = $legacyKey;
            }
        }

        foreach ($layout as &$block) {
            $id = (string) ($block['id'] ?? '');
            $legacyKey = $legacyMap[$id] ?? null;
            if ($legacyKey === null) {
                continue;
            }

            $definition = $this->settingsRegistry->getDefinition($legacyKey);
            if ($definition === null) {
                // Fall back to raw DB value when the definition was removed.
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

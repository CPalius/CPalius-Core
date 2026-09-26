<?php

declare(strict_types=1);

namespace App\Core\Portal;

/**
 * Core marketing/hero blocks only. Live module feeds are declared in
 * Resources/config/contributions.yaml and merged by PortalLayoutService.
 */
final class PortalBlockCatalog
{
    public const GROUP_HERO = 'hero';
    public const GROUP_PORTAL = 'portal';
    public const GROUP_MARKETING = 'marketing';

    public const LAYOUT_LIST = 'list';
    public const LAYOUT_CARDS = 'cards';
    public const LAYOUT_SLIDER = 'slider';

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::definitions());
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
    public static function definitions(): array
    {
        return [
            'hero' => [
                'label' => 'studio.homepage.blocks.hero',
                'group' => self::GROUP_HERO,
                'supportsLimit' => false,
                'supportsLayout' => false,
                // Copy lives in portal.tr.yaml / portal.en.yaml (bilingual), not Studio fields.
                'supportsHeroFields' => false,
                'default' => [
                    'id' => 'hero',
                    'enabled' => true,
                    'title' => '',
                ],
            ],
            'about' => [
                'label' => 'studio.homepage.blocks.about',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'about', 'enabled' => true, 'title' => ''],
            ],
            'features' => [
                'label' => 'studio.homepage.blocks.features',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'features', 'enabled' => true, 'title' => ''],
            ],
            'architecture' => [
                'label' => 'studio.homepage.blocks.architecture',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'architecture', 'enabled' => false, 'title' => ''],
            ],
            'core' => [
                'label' => 'studio.homepage.blocks.core',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'core', 'enabled' => false, 'title' => ''],
            ],
            'techstack' => [
                'label' => 'studio.homepage.blocks.techstack',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'techstack', 'enabled' => true, 'title' => ''],
            ],
            'entity' => [
                'label' => 'studio.homepage.blocks.entity',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'entity', 'enabled' => false, 'title' => ''],
            ],
            'security' => [
                'label' => 'studio.homepage.blocks.security',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'security', 'enabled' => false, 'title' => ''],
            ],
            'stats' => [
                'label' => 'studio.homepage.blocks.stats',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'stats', 'enabled' => false, 'title' => ''],
            ],
            'roadmap' => [
                'label' => 'studio.homepage.blocks.roadmap',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => true,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'roadmap', 'enabled' => true, 'title' => '', 'limit' => 3],
            ],
            'community' => [
                'label' => 'studio.homepage.blocks.community',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'community', 'enabled' => true, 'title' => ''],
            ],
            'audit' => [
                'label' => 'studio.homepage.blocks.audit',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'audit', 'enabled' => false, 'title' => ''],
            ],
            'platform' => [
                'label' => 'studio.homepage.blocks.platform',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'platform', 'enabled' => false, 'title' => ''],
            ],
            'aacp' => [
                'label' => 'studio.homepage.blocks.aacp',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'aacp', 'enabled' => false, 'title' => ''],
            ],
            'modules' => [
                'label' => 'studio.homepage.blocks.modules',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'modules', 'enabled' => false, 'title' => ''],
            ],
            'forum_engine' => [
                'label' => 'studio.homepage.blocks.forum_engine',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'forum_engine', 'enabled' => false, 'title' => ''],
            ],
            'media_pipeline' => [
                'label' => 'studio.homepage.blocks.media_pipeline',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'media_pipeline', 'enabled' => false, 'title' => ''],
            ],
            'localization' => [
                'label' => 'studio.homepage.blocks.localization',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'localization', 'enabled' => false, 'title' => ''],
            ],
            'manifesto' => [
                'label' => 'studio.homepage.blocks.manifesto',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'manifesto', 'enabled' => false, 'title' => ''],
            ],
        ];
    }

    /**
     * Deep-dive marketing blocks belong on the whitepaper, not the landing page.
     *
     * @return list<string>
     */
    public static function whitepaperOnlyIds(): array
    {
        return [
            'architecture',
            'core',
            'entity',
            'audit',
            'platform',
            'aacp',
            'modules',
            'forum_engine',
            'media_pipeline',
            'localization',
            'security',
            'manifesto',
        ];
    }

    /**
     * Landing spotlight plus optional whitepaper-depth blocks (off by default).
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultLayout(): array
    {
        $defs = self::definitions();
        $order = [
            'hero',
            'about',
            'features',
            'techstack',
            'stats',
            'roadmap',
            'community',
            'architecture',
            'core',
            'entity',
            'audit',
            'platform',
            'aacp',
            'modules',
            'forum_engine',
            'media_pipeline',
            'localization',
            'security',
            'manifesto',
        ];

        $layout = [];
        foreach ($order as $id) {
            $layout[] = $defs[$id]['default'];
        }

        return $layout;
    }
}

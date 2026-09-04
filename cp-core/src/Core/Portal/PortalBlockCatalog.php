<?php

declare(strict_types=1);

namespace App\Core\Portal;

/**
 * Portal landing block catalog — fixed IDs and default configuration.
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
            'latest_forum_topics' => [
                'label' => 'studio.homepage.blocks.latest_forum_topics',
                'group' => self::GROUP_PORTAL,
                'supportsLimit' => true,
                'supportsLayout' => true,
                'supportsHeroFields' => false,
                'default' => [
                    'id' => 'latest_forum_topics',
                    'enabled' => true,
                    'title' => 'Son Forum Konuları',
                    'limit' => 6,
                    'layout' => self::LAYOUT_LIST,
                ],
            ],
            'latest_blog_posts' => [
                'label' => 'studio.homepage.blocks.latest_blog_posts',
                'group' => self::GROUP_PORTAL,
                'supportsLimit' => true,
                'supportsLayout' => true,
                'supportsHeroFields' => false,
                'default' => [
                    'id' => 'latest_blog_posts',
                    'enabled' => true,
                    'title' => 'Son Yazılar',
                    'limit' => 6,
                    'layout' => self::LAYOUT_CARDS,
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
            'popular_forum_topics' => [
                'label' => 'studio.homepage.blocks.popular_forum_topics',
                'group' => self::GROUP_PORTAL,
                'supportsLimit' => true,
                'supportsLayout' => true,
                'supportsHeroFields' => false,
                'default' => [
                    'id' => 'popular_forum_topics',
                    'enabled' => true,
                    'title' => 'Popüler Konular',
                    'limit' => 5,
                    'layout' => self::LAYOUT_SLIDER,
                ],
            ],
            'features' => [
                'label' => 'studio.homepage.blocks.features',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'features', 'enabled' => true, 'title' => ''],
            ],
            'forum_boards' => [
                'label' => 'studio.homepage.blocks.forum_boards',
                'group' => self::GROUP_PORTAL,
                'supportsLimit' => true,
                'supportsLayout' => true,
                'supportsHeroFields' => false,
                'default' => [
                    'id' => 'forum_boards',
                    'enabled' => true,
                    'title' => 'Forum Panoları',
                    'limit' => 8,
                    'layout' => self::LAYOUT_CARDS,
                ],
            ],
            'forum_stats' => [
                'label' => 'studio.homepage.blocks.forum_stats',
                'group' => self::GROUP_PORTAL,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => [
                    'id' => 'forum_stats',
                    'enabled' => true,
                    'title' => 'Topluluk Özeti',
                ],
            ],
            'latest_forum_posts' => [
                'label' => 'studio.homepage.blocks.latest_forum_posts',
                'group' => self::GROUP_PORTAL,
                'supportsLimit' => true,
                'supportsLayout' => true,
                'supportsHeroFields' => false,
                'default' => [
                    'id' => 'latest_forum_posts',
                    'enabled' => true,
                    'title' => 'Son Forum Mesajları',
                    'limit' => 6,
                    'layout' => self::LAYOUT_LIST,
                ],
            ],
            'architecture' => [
                'label' => 'studio.homepage.blocks.architecture',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'architecture', 'enabled' => true, 'title' => ''],
            ],
            'core' => [
                'label' => 'studio.homepage.blocks.core',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'core', 'enabled' => true, 'title' => ''],
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
                'default' => ['id' => 'entity', 'enabled' => true, 'title' => ''],
            ],
            'security' => [
                'label' => 'studio.homepage.blocks.security',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'security', 'enabled' => true, 'title' => ''],
            ],
            'stats' => [
                'label' => 'studio.homepage.blocks.stats',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'stats', 'enabled' => true, 'title' => ''],
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
                'default' => ['id' => 'audit', 'enabled' => true, 'title' => ''],
            ],
            'platform' => [
                'label' => 'studio.homepage.blocks.platform',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'platform', 'enabled' => true, 'title' => ''],
            ],
            'aacp' => [
                'label' => 'studio.homepage.blocks.aacp',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'aacp', 'enabled' => true, 'title' => ''],
            ],
            'modules' => [
                'label' => 'studio.homepage.blocks.modules',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'modules', 'enabled' => true, 'title' => ''],
            ],
            'forum_engine' => [
                'label' => 'studio.homepage.blocks.forum_engine',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'forum_engine', 'enabled' => true, 'title' => ''],
            ],
            'media_pipeline' => [
                'label' => 'studio.homepage.blocks.media_pipeline',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'media_pipeline', 'enabled' => true, 'title' => ''],
            ],
            'localization' => [
                'label' => 'studio.homepage.blocks.localization',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'localization', 'enabled' => true, 'title' => ''],
            ],
            'manifesto' => [
                'label' => 'studio.homepage.blocks.manifesto',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'manifesto', 'enabled' => true, 'title' => ''],
            ],
        ];
    }

    /**
     * Marketing showcase order restored from the original cpalius-website.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultLayout(): array
    {
        $defs = self::definitions();
        $order = [
            'hero',
            'about',
            'architecture',
            'core',
            'features',
            'techstack',
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
            'stats',
            'roadmap',
            'community',
        ];

        $layout = [];
        foreach ($order as $id) {
            $layout[] = $defs[$id]['default'];
        }

        return $layout;
    }
}

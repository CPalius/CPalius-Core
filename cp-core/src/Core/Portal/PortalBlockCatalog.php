<?php

declare(strict_types=1);

namespace App\Core\Portal;

/**
 * Portal landing blok kataloğu — sabit ID'ler ve varsayılan yapılandırma.
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
                'supportsHeroFields' => true,
                'default' => [
                    'id' => 'hero',
                    'enabled' => true,
                    'title' => 'CPalius CMF',
                    'label' => 'NEXT-GEN CONTENT MANAGEMENT FRAMEWORK',
                    'subtitle' => "Bir İçerik Yönetim Sisteminden\n\"Kurşun Geçirmez\" Kurumsal Uygulama Framework'üne",
                    'description' => 'Modern web ekosisteminde hazır eklenti sistemleriyle donatılmış ama hantal CMS\'ler ile her şeye sıfırdan başlamayı gerektiren saf framework\'ler arasındaki köprüyü kuran, dünya standartlarında optimize, güvenli ve genişletilebilir altyapı.',
                    'cta_primary_label' => "Whitepaper'ı İncele",
                    'cta_primary_href' => '#about',
                    'cta_secondary_label' => "Blog'a Göz At",
                    'cta_secondary_href' => '/blog',
                    'badges' => 'PHP 8.2+,Symfony 7.4 LTS,Doctrine ORM,AssetMapper,Açık Kaynak',
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
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'roadmap', 'enabled' => true, 'title' => ''],
            ],
            'community' => [
                'label' => 'studio.homepage.blocks.community',
                'group' => self::GROUP_MARKETING,
                'supportsLimit' => false,
                'supportsLayout' => false,
                'supportsHeroFields' => false,
                'default' => ['id' => 'community', 'enabled' => true, 'title' => ''],
            ],
        ];
    }

    /**
     * Portal ağırlıklı varsayılan sıra (plan).
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultLayout(): array
    {
        $defs = self::definitions();
        $order = [
            'hero',
            'latest_forum_topics',
            'latest_blog_posts',
            'about',
            'popular_forum_topics',
            'features',
            'forum_boards',
            'forum_stats',
            'latest_forum_posts',
            'architecture',
            'core',
            'techstack',
            'entity',
            'security',
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

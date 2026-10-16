<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Settings\SettingsRegistry;
use App\Core\Widget\WidgetArea;
use App\Core\Widget\WidgetAreaProviderInterface;
use App\Core\Widget\WidgetPreset;

/**
 * The blog's sidebar area and its ready-made arrangements.
 *
 * Before the widget system the sidebar was fixed (an author card, recent posts, popular tags, an archive),
 * tuned by a few settings and two on/off switches. A site that already has those values gets them as the
 * "Your previous sidebar" arrangement and starts with it, so updating changes nothing visitors see; a new
 * site starts with the classic one.
 */
final class BlogWidgetAreaProvider implements WidgetAreaProviderInterface
{
    public const SIDEBAR = 'blog_sidebar';

    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function areas(): array
    {
        $presets = [];
        $legacy = $this->legacyWidgets();
        if ($legacy !== null) {
            $presets[] = new WidgetPreset('legacy', 'blog.widget.preset.legacy.label', 'blog.widget.preset.legacy.description', $legacy);
        }

        array_push(
            $presets,
            new WidgetPreset('classic', 'blog.widget.preset.classic.label', 'blog.widget.preset.classic.description', [
                ['type' => 'blog.search'],
                ['type' => 'blog.author_card'],
                ['type' => 'blog.recent_posts'],
                ['type' => 'blog.categories'],
                ['type' => 'blog.tags'],
                ['type' => 'blog.archive'],
            ]),
            new WidgetPreset('minimal', 'blog.widget.preset.minimal.label', 'blog.widget.preset.minimal.description', [
                ['type' => 'blog.search'],
                ['type' => 'blog.recent_posts'],
                ['type' => 'blog.categories'],
            ]),
            new WidgetPreset('popular', 'blog.widget.preset.popular.label', 'blog.widget.preset.popular.description', [
                ['type' => 'blog.search'],
                ['type' => 'blog.popular_posts'],
                ['type' => 'blog.most_commented'],
                ['type' => 'blog.featured_posts'],
                ['type' => 'blog.tags'],
                ['type' => 'widget.social_links'],
            ]),
            new WidgetPreset('empty', 'blog.widget.preset.empty.label', 'blog.widget.preset.empty.description', []),
        );

        return [
            new WidgetArea(self::SIDEBAR, 'blog.widget.area.sidebar', 'blog.widget.area.sidebar_help', $presets, $legacy !== null ? 'legacy' : 'classic', 'Blog'),
        ];
    }

    /**
     * The old fixed sidebar rebuilt from its settings, or null when the site never had any of them
     * (a fresh install, or a site that kept every default).
     *
     * @return list<array{type: string, title?: string, settings?: array<string, mixed>}>|null
     */
    private function legacyWidgets(): ?array
    {
        $recent = $this->settings->getRaw('blog_widget.recent_posts_limit');
        $tags = $this->settings->getRaw('blog_widget.popular_tags_limit');
        $github = trim((string) $this->settings->getRaw('blog_widget.social_github_url'));
        $twitter = trim((string) $this->settings->getRaw('blog_widget.social_twitter_url'));
        $widgetPlugin = $this->settings->getRaw('plugin.blog_widget.active');
        $archivePlugin = $this->settings->getRaw('plugin.blog_archive.active');

        if ($recent === null && $tags === null && $github === '' && $twitter === '' && $widgetPlugin === null && $archivePlugin === null) {
            return null;
        }

        // The theme itself used to print these two above the plugins, always: the author card on post pages
        // and the search box everywhere. They are widgets now, so the arrangement keeps them first.
        $rows = [['type' => 'blog.author_card'], ['type' => 'blog.search']];
        if ($widgetPlugin !== '0') {
            if ($github !== '' || $twitter !== '') {
                $rows[] = [
                    'type' => 'widget.social_links',
                    'title' => 'blog.widget.author_title',
                    'settings' => ['github' => $github, 'x' => $twitter],
                ];
            }
            $rows[] = [
                'type' => 'blog.recent_posts',
                'title' => 'blog.widget.recent_posts_title',
                'settings' => ['limit' => (int) ($recent ?? 5), 'exclude_current' => false],
            ];
            $rows[] = [
                'type' => 'blog.tags',
                'title' => 'blog.widget.popular_tags_title',
                'settings' => ['limit' => (int) ($tags ?? 10), 'show_count' => true],
            ];
        }
        if ($archivePlugin !== '0') {
            $rows[] = ['type' => 'blog.archive', 'title' => 'blog.widget.archive_title'];
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Blog\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Runtime settings for BlogWidgetPlugin. module is "blog_widget" (plugin name), not "blog".
 */
#[CpSetting(
    key: 'blog_widget.recent_posts_limit',
    label: 'blog.widget.recent_posts_limit',
    type: 'integer',
    default: 5,
    module: 'blog_widget',
    group: 'blog_widget',
)]
#[CpSetting(
    key: 'blog_widget.popular_tags_limit',
    label: 'blog.widget.popular_tags_limit',
    type: 'integer',
    default: 10,
    module: 'blog_widget',
    group: 'blog_widget',
)]
#[CpSetting(
    key: 'blog_widget.social_github_url',
    label: 'blog.widget.social_github_url',
    type: 'text',
    default: '',
    module: 'blog_widget',
    group: 'blog_widget',
)]
#[CpSetting(
    key: 'blog_widget.social_twitter_url',
    label: 'blog.widget.social_twitter_url',
    type: 'text',
    default: '',
    module: 'blog_widget',
    group: 'blog_widget',
)]
final class BlogWidgetSettings
{
}

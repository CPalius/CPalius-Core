<?php

declare(strict_types=1);

namespace Modules\Seo\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Per-locale copy is translatable; crawl/schema behaviour keys are global.
 */
#[CpSetting(key: 'seo.default_title', label: 'studio.seo.field.default_title', type: 'text', default: 'CPalius CMF', module: 'seo', group: 'seo_identity', translatable: true)]
#[CpSetting(key: 'seo.default_description', label: 'studio.seo.field.default_description', type: 'textarea', default: 'CPalius CMF: an enterprise-grade secure, modular, and extensible content management framework.', module: 'seo', group: 'seo_identity', translatable: true)]
#[CpSetting(key: 'seo.og_title', label: 'studio.seo.field.og_title', type: 'text', default: 'CPalius CMF', module: 'seo', group: 'seo_identity', translatable: true)]
#[CpSetting(key: 'seo.og_description', label: 'studio.seo.field.og_description', type: 'textarea', default: 'CPalius CMF: an enterprise-grade secure, modular, and extensible content management framework.', module: 'seo', group: 'seo_identity', translatable: true)]
#[CpSetting(key: 'seo.organization_name', label: 'studio.seo.field.organization_name', type: 'text', default: 'CPalius', module: 'seo', group: 'seo_identity', translatable: true)]
#[CpSetting(key: 'seo.organization_description', label: 'studio.seo.field.organization_description', type: 'textarea', default: 'CPalius CMF is a next-generation content management framework built on PHP 8.2+ and Symfony 7.4 LTS.', module: 'seo', group: 'seo_identity', translatable: true)]
#[CpSetting(key: 'seo.page.title_template', label: 'studio.seo.field.page_title_template', type: 'text', default: '%%title%% – %%site_name%%', module: 'seo', group: 'seo_identity', translatable: true)]
#[CpSetting(key: 'seo.blog.title_template', label: 'studio.seo.field.blog_title_template', type: 'text', default: '%%title%% – %%site_name%%', module: 'seo', group: 'seo_blog', translatable: true)]
#[CpSetting(key: 'seo.forum.title_template', label: 'studio.seo.field.forum_title_template', type: 'text', default: '%%title%% – %%site_name%%', module: 'seo', group: 'seo_forum', translatable: true)]
#[CpSetting(key: 'seo.roadmap.title_template', label: 'studio.seo.field.roadmap_title_template', type: 'text', default: '%%title%% – %%site_name%%', module: 'seo', group: 'seo_roadmap', translatable: true)]
#[CpSetting(key: 'seo.public_base_url', label: 'studio.seo.field.public_base_url', type: 'text', default: '', module: 'seo', group: 'seo_identity')]
#[CpSetting(key: 'seo.default_og_image', label: 'studio.seo.field.default_og_image', type: 'text', default: '/CPalius.png', module: 'seo', group: 'seo_social')]
#[CpSetting(key: 'seo.twitter_site', label: 'studio.seo.field.twitter_site', type: 'text', default: '', module: 'seo', group: 'seo_social')]
#[CpSetting(key: 'seo.twitter_card', label: 'studio.seo.field.twitter_card', type: 'select', default: 'summary_large_image', variants: [
    'summary' => 'studio.seo.twitter.summary',
    'summary_large_image' => 'studio.seo.twitter.summary_large_image',
], module: 'seo', group: 'seo_social')]
#[CpSetting(key: 'seo.facebook_app_id', label: 'studio.seo.field.facebook_app_id', type: 'text', default: '', module: 'seo', group: 'seo_social')]
#[CpSetting(key: 'seo.google_site_verification', label: 'studio.seo.field.google_site_verification', type: 'text', default: '', module: 'seo', group: 'seo_verify')]
#[CpSetting(key: 'seo.bing_site_verification', label: 'studio.seo.field.bing_site_verification', type: 'text', default: '', module: 'seo', group: 'seo_verify')]
#[CpSetting(key: 'seo.yandex_site_verification', label: 'studio.seo.field.yandex_site_verification', type: 'text', default: '', module: 'seo', group: 'seo_verify')]
#[CpSetting(key: 'seo.site_indexable', label: 'studio.seo.field.site_indexable', type: 'checkbox', default: '1', module: 'seo', group: 'seo_robots')]
#[CpSetting(key: 'seo.index_blog_listings', label: 'studio.seo.field.index_blog_listings', type: 'checkbox', default: '1', module: 'seo', group: 'seo_blog')]
#[CpSetting(key: 'seo.index_search', label: 'studio.seo.field.index_search', type: 'checkbox', default: '0', module: 'seo', group: 'seo_robots')]
#[CpSetting(key: 'seo.index_pagination', label: 'studio.seo.field.index_pagination', type: 'checkbox', default: '0', module: 'seo', group: 'seo_robots')]
#[CpSetting(key: 'seo.index_forum_profiles', label: 'studio.seo.field.index_forum_profiles', type: 'checkbox', default: '0', module: 'seo', group: 'seo_forum')]
#[CpSetting(key: 'seo.index_forum_activity', label: 'studio.seo.field.index_forum_activity', type: 'checkbox', default: '0', module: 'seo', group: 'seo_forum')]
#[CpSetting(key: 'seo.robots_extra', label: 'studio.seo.field.robots_extra', type: 'textarea', default: '', module: 'seo', group: 'seo_robots')]
#[CpSetting(key: 'seo.blog.article_schema', label: 'studio.seo.field.blog_article_schema', type: 'select', default: 'BlogPosting', variants: [
    'BlogPosting' => 'BlogPosting',
    'Article' => 'Article',
    'TechArticle' => 'TechArticle',
], module: 'seo', group: 'seo_blog')]
#[CpSetting(key: 'seo.blog.project_schema', label: 'studio.seo.field.blog_project_schema', type: 'select', default: 'SoftwareSourceCode', variants: [
    'SoftwareSourceCode' => 'SoftwareSourceCode',
    'CreativeWork' => 'CreativeWork',
    'Article' => 'Article',
], module: 'seo', group: 'seo_blog')]
#[CpSetting(key: 'seo.forum.topic_schema', label: 'studio.seo.field.forum_topic_schema', type: 'select', default: 'DiscussionForumPosting', variants: [
    'DiscussionForumPosting' => 'DiscussionForumPosting',
    'QAPage' => 'QAPage',
    'Article' => 'Article',
], module: 'seo', group: 'seo_forum')]
#[CpSetting(key: 'seo.roadmap.entry_schema', label: 'studio.seo.field.roadmap_entry_schema', type: 'select', default: 'TechArticle', variants: [
    'TechArticle' => 'TechArticle',
    'SoftwareApplication' => 'SoftwareApplication',
    'Article' => 'Article',
], module: 'seo', group: 'seo_roadmap')]
#[CpSetting(key: 'seo.include_search_action', label: 'studio.seo.field.include_search_action', type: 'checkbox', default: '1', module: 'seo', group: 'seo_identity')]
#[CpSetting(key: 'seo.sitemap_enabled', label: 'studio.seo.field.sitemap_enabled', type: 'checkbox', default: '1', module: 'seo', group: 'seo_sitemap')]
#[CpSetting(key: 'seo.sitemap_include_forum', label: 'studio.seo.field.sitemap_include_forum', type: 'checkbox', default: '1', module: 'seo', group: 'seo_sitemap')]
#[CpSetting(key: 'seo.sitemap_include_images', label: 'studio.seo.field.sitemap_include_images', type: 'checkbox', default: '1', module: 'seo', group: 'seo_sitemap')]
#[CpSetting(key: 'seo.sitemap_include_videos', label: 'studio.seo.field.sitemap_include_videos', type: 'checkbox', default: '1', module: 'seo', group: 'seo_sitemap')]
final class SeoModuleSettings
{
}

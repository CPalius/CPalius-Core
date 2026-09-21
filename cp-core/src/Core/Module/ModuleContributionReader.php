<?php

declare(strict_types=1);

namespace App\Core\Module;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses Resources/config/contributions.yaml so modules register routes/fields
 * without editing core PHP.
 *
 * @phpstan-type StudioConfig array{
 *     type_labels: array<string, string>,
 *     edit_routes: array<string, array{0: string, 1: string}>,
 *     view_routes: array<string, array{0: string, 1: string}>,
 *     quick_create: list<array{labelKey: string, routeName: string, icon: string}>,
 *     quick_links: list<array{labelKey: string, routeName: string, icon: string}>,
 *     hidden_node_types: list<string>,
 *     shell: ?StudioShellConfig
 * }
 * @phpstan-type StudioShellConfig array{
 *     brand: string,
 *     subtitle: ?string,
 *     home_route: ?string,
 *     keep: list<string>,
 *     regroup: array<string, string>
 * }
 * @phpstan-type HomepageMode array{route: string, label: string, priority: int}
 * @phpstan-type PortalBlock array{
 *     label: string,
 *     group: string,
 *     supportsLimit: bool,
 *     supportsLayout: bool,
 *     supportsHeroFields: bool,
 *     hideOnLanding: bool,
 *     requiresRoute: ?string,
 *     legacyWidgetSetting: ?string,
 *     default: array<string, mixed>
 * }
 * @phpstan-type AccountConfig array{post_login_route: ?string, post_login_priority: int}
 * @phpstan-type InboundWebhook array{secretSetting: string, header: string, signature: string, maxBody: int}
 * @phpstan-type Catalogue array{
 *     node_show_routes: array<string, string>,
 *     category_show_route: ?string,
 *     queryable_fields: array<string, array<string, string>>,
 *     studio: StudioConfig,
 *     homepage_modes: array<string, HomepageMode>,
 *     portal_blocks: array<string, PortalBlock>,
 *     schema_types: array<string, string>,
 *     account: AccountConfig,
 *     inbound_webhooks: array<string, InboundWebhook>,
 *     notification_types: array<string, NotificationType>
 * }
 * @phpstan-type NotificationType array{
 *     label: string,
 *     module: string,
 *     defaultChannels: list<string>,
 *     preferenceKey: ?string,
 *     mailTemplate: string,
 *     allowSelf: bool,
 *     exposeInAccount: bool
 * }
 */
final class ModuleContributionReader
{
    /**
     * @return Catalogue
     */
    public static function empty(): array
    {
        return [
            'node_show_routes' => [],
            'category_show_route' => null,
            'queryable_fields' => [],
            'studio' => [
                'type_labels' => [],
                'edit_routes' => [],
                'view_routes' => [],
                'quick_create' => [],
                'quick_links' => [],
                'hidden_node_types' => [],
                'shell' => null,
            ],
            'homepage_modes' => [],
            'portal_blocks' => [],
            'schema_types' => [],
            'account' => [
                'post_login_route' => null,
                'post_login_priority' => 0,
            ],
            'inbound_webhooks' => [],
            'notification_types' => [],
        ];
    }

    /**
     * @return Catalogue
     */
    public static function fromFile(string $path): array
    {
        if (!is_file($path)) {
            return self::empty();
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (\Throwable) {
            return self::empty();
        }

        return self::normalize(\is_array($data) ? $data : []);
    }

    /**
     * @param Catalogue $base
     * @param Catalogue $incoming
     *
     * @return Catalogue
     */
    public static function merge(array $base, array $incoming): array
    {
        $base['node_show_routes'] = array_merge($base['node_show_routes'], $incoming['node_show_routes']);
        if ($incoming['category_show_route'] !== null) {
            $base['category_show_route'] = $incoming['category_show_route'];
        }

        foreach ($incoming['queryable_fields'] as $type => $fields) {
            $base['queryable_fields'][$type] = array_merge($base['queryable_fields'][$type] ?? [], $fields);
        }

        $studio = $base['studio'];
        $add = $incoming['studio'];
        $studio['type_labels'] = array_merge($studio['type_labels'], $add['type_labels']);
        $studio['edit_routes'] = array_merge($studio['edit_routes'], $add['edit_routes']);
        $studio['view_routes'] = array_merge($studio['view_routes'], $add['view_routes']);
        $studio['quick_create'] = array_merge($studio['quick_create'], $add['quick_create']);
        $studio['quick_links'] = array_merge($studio['quick_links'], $add['quick_links']);
        $studio['hidden_node_types'] = array_values(array_unique(array_merge(
            $studio['hidden_node_types'],
            $add['hidden_node_types'],
        )));
        // First declaration wins. Two modules both claiming the shell is a
        // configuration mistake, and silently letting the last one loaded take
        // it would make which panel you get depend on activation order.
        $studio['shell'] ??= $add['shell'];
        $base['studio'] = $studio;

        $base['homepage_modes'] = array_merge($base['homepage_modes'], $incoming['homepage_modes']);
        $base['portal_blocks'] = array_merge($base['portal_blocks'], $incoming['portal_blocks']);
        $base['schema_types'] = array_merge($base['schema_types'], $incoming['schema_types']);

        if ($incoming['account']['post_login_route'] !== null
            && $incoming['account']['post_login_priority'] >= $base['account']['post_login_priority']
        ) {
            $base['account'] = $incoming['account'];
        }

        $base['inbound_webhooks'] = array_merge($base['inbound_webhooks'], $incoming['inbound_webhooks']);
        $base['notification_types'] = array_merge($base['notification_types'], $incoming['notification_types']);

        return $base;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return Catalogue
     */
    public static function normalize(array $data): array
    {
        $empty = self::empty();
        $empty['node_show_routes'] = self::stringMap($data['node_show_routes'] ?? null);
        $empty['category_show_route'] = self::nullableString($data['category_show_route'] ?? null);
        $empty['queryable_fields'] = self::fieldMap($data['queryable_fields'] ?? null);

        $studio = \is_array($data['studio'] ?? null) ? $data['studio'] : [];
        $empty['studio']['type_labels'] = self::stringMap($studio['type_labels'] ?? null);
        $empty['studio']['edit_routes'] = self::routePairs($studio['edit_routes'] ?? null);
        $empty['studio']['view_routes'] = self::routePairs($studio['view_routes'] ?? null);
        $empty['studio']['quick_create'] = self::linkList($studio['quick_create'] ?? null);
        $empty['studio']['quick_links'] = self::linkList($studio['quick_links'] ?? null);
        $empty['studio']['hidden_node_types'] = self::stringList($studio['hidden_node_types'] ?? null);
        $empty['studio']['shell'] = self::studioShell($studio['shell'] ?? null);

        $empty['homepage_modes'] = self::homepageModes($data['homepage_modes'] ?? null);
        $empty['portal_blocks'] = self::portalBlocks($data['portal_blocks'] ?? null);
        $empty['schema_types'] = self::stringMap($data['schema_types'] ?? null);
        $empty['account'] = self::accountConfig($data['account'] ?? null);
        $empty['inbound_webhooks'] = self::inboundWebhooks($data['inbound_webhooks'] ?? null);
        $empty['notification_types'] = self::notificationTypes($data['notification_types'] ?? null);

        return $empty;
    }

    /**
     * @return array<string, NotificationType>
     */
    private static function notificationTypes(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $row) {
            if (!\is_string($key) || $key === '' || !\is_array($row)) {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_.]{1,127}$/', $key) !== 1) {
                continue;
            }
            $label = $row['label'] ?? $row['label_key'] ?? null;
            if (!\is_string($label) || $label === '') {
                continue;
            }
            $module = $row['module'] ?? 'module';
            $channels = $row['default_channels'] ?? $row['defaultChannels'] ?? ['in_app'];
            if (!\is_array($channels)) {
                $channels = ['in_app'];
            }
            $normalizedChannels = [];
            foreach ($channels as $channel) {
                if (\is_string($channel) && $channel !== '') {
                    $normalizedChannels[] = $channel;
                }
            }
            if ($normalizedChannels === []) {
                $normalizedChannels = ['in_app'];
            }
            $pref = $row['preference_key'] ?? $row['preferenceKey'] ?? null;
            $template = $row['mail_template'] ?? $row['mailTemplate'] ?? 'generic';
            $map[$key] = [
                'label' => $label,
                'module' => \is_string($module) && $module !== '' ? $module : 'module',
                'defaultChannels' => array_values(array_unique($normalizedChannels)),
                'preferenceKey' => \is_string($pref) && $pref !== '' ? $pref : null,
                'mailTemplate' => \is_string($template) && $template !== '' ? $template : 'generic',
                'allowSelf' => (bool) ($row['allow_self'] ?? $row['allowSelf'] ?? false),
                'exposeInAccount' => (bool) ($row['expose_in_account'] ?? $row['exposeInAccount'] ?? true),
            ];
        }

        return $map;
    }

    /**
     * @return array<string, HomepageMode>
     */
    private static function homepageModes(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $id => $mode) {
            if (!\is_string($id) || $id === '' || !\is_array($mode)) {
                continue;
            }
            $route = $mode['route'] ?? null;
            $label = $mode['label'] ?? $mode['label_key'] ?? $id;
            $priority = (int) ($mode['priority'] ?? 0);
            if (\is_string($route) && $route !== '' && \is_string($label) && $label !== '') {
                $map[$id] = ['route' => $route, 'label' => $label, 'priority' => $priority];
            }
        }

        return $map;
    }

    /**
     * @return array<string, PortalBlock>
     */
    private static function portalBlocks(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $id => $block) {
            if (!\is_string($id) || $id === '' || !\is_array($block)) {
                continue;
            }

            $label = $block['label'] ?? $block['label_key'] ?? '';
            if (!\is_string($label) || $label === '') {
                continue;
            }

            $group = $block['group'] ?? 'portal';
            $default = \is_array($block['default'] ?? null) ? $block['default'] : [];
            $default['id'] = $id;
            if (!array_key_exists('enabled', $default)) {
                $default['enabled'] = false;
            }
            if (!array_key_exists('title', $default)) {
                $default['title'] = '';
            }

            $map[$id] = [
                'label' => $label,
                'group' => \is_string($group) && $group !== '' ? $group : 'portal',
                'supportsLimit' => (bool) ($block['supports_limit'] ?? $block['supportsLimit'] ?? false),
                'supportsLayout' => (bool) ($block['supports_layout'] ?? $block['supportsLayout'] ?? false),
                'supportsHeroFields' => (bool) ($block['supports_hero_fields'] ?? $block['supportsHeroFields'] ?? false),
                'hideOnLanding' => (bool) ($block['hide_on_landing'] ?? $block['hideOnLanding'] ?? false),
                'requiresRoute' => self::nullableString($block['requires_route'] ?? $block['requiresRoute'] ?? null),
                'legacyWidgetSetting' => self::nullableString($block['legacy_widget_setting'] ?? $block['legacyWidgetSetting'] ?? null),
                'default' => $default,
            ];
        }

        return $map;
    }

    /**
     * @return AccountConfig
     */
    private static function accountConfig(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return ['post_login_route' => null, 'post_login_priority' => 0];
        }

        return [
            'post_login_route' => self::nullableString($raw['post_login_route'] ?? null),
            'post_login_priority' => (int) ($raw['post_login_priority'] ?? 0),
        ];
    }

    /**
     * @return array<string, InboundWebhook>
     */
    private static function inboundWebhooks(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $id => $row) {
            if (!\is_string($id) || $id === '' || !\is_array($row)) {
                continue;
            }
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $id) !== 1) {
                continue;
            }
            $secret = self::nullableString($row['secret_setting'] ?? $row['secretSetting'] ?? null);
            if ($secret === null) {
                continue;
            }
            $header = self::nullableString($row['header'] ?? null) ?? 'X-CP-Webhook-Signature';
            $signature = self::nullableString($row['signature'] ?? null) ?? 'hmac_sha256';
            $maxBody = (int) ($row['max_body'] ?? $row['maxBody'] ?? 65536);
            $map[$id] = [
                'secretSetting' => $secret,
                'header' => $header,
                'signature' => $signature,
                'maxBody' => max(1024, min(65536, $maxBody)),
            ];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (\is_string($key) && $key !== '' && \is_string($value) && $value !== '') {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function fieldMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $type => $fields) {
            if (!\is_string($type) || $type === '' || !\is_array($fields)) {
                continue;
            }
            $map[$type] = self::stringMap($fields);
        }

        return $map;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private static function routePairs(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $type => $pair) {
            if (!\is_string($type) || $type === '' || !\is_array($pair) || \count($pair) < 2) {
                continue;
            }
            $route = $pair[0] ?? $pair['route'] ?? null;
            $param = $pair[1] ?? $pair['param'] ?? null;
            if (\is_string($route) && $route !== '' && \is_string($param) && $param !== '') {
                $map[$type] = [$route, $param];
            }
        }

        return $map;
    }

    /**
     * @return list<array{labelKey: string, routeName: string, icon: string}>
     */
    private static function linkList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $label = $item['label_key'] ?? $item['labelKey'] ?? null;
            $route = $item['route'] ?? $item['routeName'] ?? null;
            $icon = $item['icon'] ?? 'heroicons:puzzle-piece';
            if (\is_string($label) && $label !== '' && \is_string($route) && $route !== '' && \is_string($icon)) {
                $list[] = ['labelKey' => $label, 'routeName' => $route, 'icon' => $icon];
            }
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $value) {
            if (\is_string($value) && $value !== '') {
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * A module claiming the Studio panel for itself.
     *
     * The declaration is deliberately small. A shell owner renames the panel and
     * decides which of the other screens stay reachable from its menu; it does
     * not get to restyle anything, because a module that could would be a module
     * that can break the console it is running inside.
     *
     * A claim with no brand is not a claim. Without it there is nothing to put
     * where the platform name was, and a half-applied takeover — module menu,
     * platform name — is worse than none.
     *
     * @return StudioShellConfig|null
     */
    private static function studioShell(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }

        $brand = self::nullableString($raw['brand'] ?? null);

        if ($brand === null) {
            return null;
        }

        return [
            'brand' => $brand,
            'subtitle' => self::nullableString($raw['subtitle'] ?? null),
            'home_route' => self::nullableString($raw['home_route'] ?? null),
            'keep' => self::stringList($raw['keep'] ?? null),
            // Route name => sidebar section heading. A shell owner that keeps a
            // platform screen can also say where it belongs in its own menu,
            // because "Media" filed under a heading called Content next to a
            // heading called Hosting reads as two products bolted together.
            'regroup' => self::stringMap($raw['regroup'] ?? null),
        ];
    }

    private static function nullableString(mixed $raw): ?string
    {
        return \is_string($raw) && $raw !== '' ? $raw : null;
    }
}

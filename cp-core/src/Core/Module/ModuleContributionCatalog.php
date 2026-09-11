<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Runtime view of merged module contributions (parameter cpalius.module.contributions).
 */
final class ModuleContributionCatalog
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly array $data = [],
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function nodeShowRoutes(): array
    {
        $routes = $this->data['node_show_routes'] ?? [];

        return \is_array($routes) ? $routes : [];
    }

    public function nodeShowRoute(string $nodeType): ?string
    {
        $route = $this->nodeShowRoutes()[$nodeType] ?? null;

        return \is_string($route) && $route !== '' ? $route : null;
    }

    public function categoryShowRoute(): ?string
    {
        $route = $this->data['category_show_route'] ?? null;

        return \is_string($route) && $route !== '' ? $route : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function queryableFields(): array
    {
        $fields = $this->data['queryable_fields'] ?? [];

        return \is_array($fields) ? $fields : [];
    }

    /**
     * @return array<string, string>
     */
    public function queryableFieldsForType(string $nodeType): array
    {
        $fields = $this->queryableFields()[$nodeType] ?? [];

        return \is_array($fields) ? $fields : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function studio(): array
    {
        $studio = $this->data['studio'] ?? [];

        return \is_array($studio) ? $studio : [];
    }

    /**
     * @return array<string, string>
     */
    public function studioTypeLabels(): array
    {
        $labels = $this->studio()['type_labels'] ?? [];

        return \is_array($labels) ? $labels : [];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function studioEditRoutes(): array
    {
        return $this->routePairs($this->studio()['edit_routes'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function studioViewRoutes(): array
    {
        return $this->routePairs($this->studio()['view_routes'] ?? null);
    }

    /**
     * @return list<array{labelKey: string, routeName: string, icon: string}>
     */
    public function studioQuickCreate(): array
    {
        return $this->linkList($this->studio()['quick_create'] ?? null);
    }

    /**
     * @return list<array{labelKey: string, routeName: string, icon: string}>
     */
    public function studioQuickLinks(): array
    {
        return $this->linkList($this->studio()['quick_links'] ?? null);
    }

    /**
     * @return list<string>
     */
    public function hiddenNodeTypes(): array
    {
        $types = $this->studio()['hidden_node_types'] ?? [];
        if (!\is_array($types)) {
            return [];
        }

        $out = [];
        foreach ($types as $type) {
            if (\is_string($type) && $type !== '') {
                $out[] = $type;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{route: string, label: string, priority: int}>
     */
    public function homepageModes(): array
    {
        $modes = $this->data['homepage_modes'] ?? [];
        if (!\is_array($modes)) {
            return [];
        }

        $out = [];
        foreach ($modes as $id => $mode) {
            if (!\is_string($id) || $id === '' || !\is_array($mode)) {
                continue;
            }
            $route = $mode['route'] ?? null;
            $label = $mode['label'] ?? null;
            $priority = (int) ($mode['priority'] ?? 0);
            if (\is_string($route) && $route !== '' && \is_string($label) && $label !== '') {
                $out[$id] = ['route' => $route, 'label' => $label, 'priority' => $priority];
            }
        }

        uasort($out, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $out;
    }

    public function homepageModeRoute(string $mode): ?string
    {
        $route = $this->homepageModes()[$mode]['route'] ?? null;

        return \is_string($route) && $route !== '' ? $route : null;
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     group: string,
     *     supportsLimit: bool,
     *     supportsLayout: bool,
     *     supportsHeroFields: bool,
     *     hideOnLanding: bool,
     *     requiresRoute: ?string,
     *     legacyWidgetSetting: ?string,
     *     default: array<string, mixed>
     * }>
     */
    public function portalBlocks(): array
    {
        $blocks = $this->data['portal_blocks'] ?? [];

        return \is_array($blocks) ? $blocks : [];
    }

    /**
     * @return array<string, string>
     */
    public function schemaTypes(): array
    {
        $types = $this->data['schema_types'] ?? [];
        if (!\is_array($types)) {
            return [];
        }

        $out = [];
        foreach ($types as $nodeType => $schema) {
            if (\is_string($nodeType) && $nodeType !== '' && \is_string($schema) && $schema !== '') {
                $out[$nodeType] = $schema;
            }
        }

        return $out;
    }

    public function schemaTypeFor(string $nodeType, string $fallback = 'WebPage'): string
    {
        return $this->schemaTypes()[$nodeType] ?? $fallback;
    }

    public function accountPostLoginRoute(): ?string
    {
        $account = $this->data['account'] ?? [];
        if (!\is_array($account)) {
            return null;
        }

        $route = $account['post_login_route'] ?? null;

        return \is_string($route) && $route !== '' ? $route : null;
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     module: string,
     *     defaultChannels: list<string>,
     *     preferenceKey: ?string,
     *     mailTemplate: string,
     *     allowSelf: bool,
     *     exposeInAccount: bool
     * }>
     */
    public function notificationTypes(): array
    {
        $types = $this->data['notification_types'] ?? [];

        return \is_array($types) ? $types : [];
    }

    /**
     * @return array{secretSetting: string, header: string, signature: string, maxBody: int}|null
     */
    public function inboundWebhook(string $endpointId): ?array
    {
        $all = $this->data['inbound_webhooks'] ?? [];
        if (!\is_array($all)) {
            return null;
        }
        $row = $all[$endpointId] ?? null;
        if (!\is_array($row) || !isset($row['secretSetting']) || !\is_string($row['secretSetting'])) {
            return null;
        }

        return [
            'secretSetting' => $row['secretSetting'],
            'header' => \is_string($row['header'] ?? null) ? $row['header'] : 'X-CP-Webhook-Signature',
            'signature' => \is_string($row['signature'] ?? null) ? $row['signature'] : 'hmac_sha256',
            'maxBody' => (int) ($row['maxBody'] ?? 65536),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function routePairs(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $type => $pair) {
            if (!\is_string($type) || !\is_array($pair) || \count($pair) < 2) {
                continue;
            }
            $route = $pair[0] ?? null;
            $param = $pair[1] ?? null;
            if (\is_string($route) && \is_string($param)) {
                $map[$type] = [$route, $param];
            }
        }

        return $map;
    }

    /**
     * @return list<array{labelKey: string, routeName: string, icon: string}>
     */
    private function linkList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $label = $item['labelKey'] ?? null;
            $route = $item['routeName'] ?? null;
            $icon = $item['icon'] ?? 'heroicons:puzzle-piece';
            if (\is_string($label) && \is_string($route) && \is_string($icon)) {
                $list[] = ['labelKey' => $label, 'routeName' => $route, 'icon' => $icon];
            }
        }

        return $list;
    }
}

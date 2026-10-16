<?php

declare(strict_types=1);

namespace App\Core\Admin;

/**
 * Per-user desk layout (Studio and AACP): which column each card sits in, the order inside the
 * column, removed cards and how many rows each card lists. Pure functions over a card catalogue,
 * so a controller can validate a save without rebuilding the desk's data.
 *
 * Cards live in independent columns on purpose: moving a card changes only the column it left and
 * the one it entered, so the others stay exactly where the user put them.
 *
 * Stored shape: {order: list<string>, columns: array<string, int>, removed: list<string>, limits: array<string, int>}
 */
final class StudioDashboardLayout
{
    public const DATA_KEY = 'studio_dashboard_layout';
    public const COLUMNS = 2;

    private const ID_PATTERN = '/^[a-z0-9_.]+$/';

    /**
     * The cards this desk can show right now. `limit` is [default, min, max] for cards that list
     * rows, null for cards that do not; `column` is where a never-moved card starts.
     *
     * @param array<string, mixed> $data StudioDashboardService::build() output
     *
     * @return list<array{id: string, kind: string, labelKey: string, icon: string, column: int, limit: ?array{int, int, int}, module?: array<string, mixed>}>
     */
    public static function catalog(array $data, bool $hasVisitors): array
    {
        $cards = [
            ['id' => 'studio.recent', 'kind' => 'recent', 'labelKey' => 'studio.dashboard.recent.title', 'icon' => 'heroicons:clock', 'column' => 0, 'limit' => [10, 1, 25]],
            ['id' => 'studio.totals', 'kind' => 'totals', 'labelKey' => 'studio.dashboard.totals.title', 'icon' => 'heroicons:chart-bar', 'column' => 1, 'limit' => null],
        ];
        if ($hasVisitors) {
            $cards[] = ['id' => 'studio.visitors', 'kind' => 'visitors', 'labelKey' => 'studio.dashboard.visitors.title', 'icon' => 'heroicons:users', 'column' => 1, 'limit' => null];
        }
        if (($data['workbench']['available'] ?? false) && ($data['workbench']['states'] ?? []) !== []) {
            $cards[] = ['id' => 'studio.workbench', 'kind' => 'workbench', 'labelKey' => 'studio.dashboard.workbench.title', 'icon' => 'heroicons:queue-list', 'column' => 1, 'limit' => [10, 1, 25]];
        }
        $cards[] = ['id' => 'studio.mix', 'kind' => 'mix', 'labelKey' => 'studio.dashboard.mix.title', 'icon' => 'heroicons:chart-pie', 'column' => 1, 'limit' => [8, 1, 25]];
        $cards[] = ['id' => 'studio.shortcuts', 'kind' => 'shortcuts', 'labelKey' => 'studio.dashboard.shortcuts.title', 'icon' => 'heroicons:bolt', 'column' => 1, 'limit' => [12, 1, 30]];

        foreach ($data['modules'] ?? [] as $module) {
            $id = 'module.'.preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) $module['key']));
            $cards[] = ['id' => $id, 'kind' => 'module', 'labelKey' => (string) $module['labelKey'], 'icon' => (string) $module['icon'], 'column' => 1, 'limit' => [6, 1, 25], 'module' => $module];
        }

        return $cards;
    }

    /**
     * Applies the stored layout to the catalogue. A card never placed (a module installed later)
     * keeps its catalogue column and goes after the placed ones; a stored id that no longer
     * exists is ignored. Order is meaningful only within a column.
     *
     * @param list<array<string, mixed>> $catalog each entry may carry `column` (default 0)
     * @param array<string, mixed>       $stored
     *
     * @return list<array<string, mixed>> catalogue entries plus `removed`, `limitValue` and a final `column`
     */
    public static function arrange(array $catalog, array $stored, int $columns = self::COLUMNS): array
    {
        $order = array_flip(array_values(array_filter((array) ($stored['order'] ?? []), 'is_string')));
        $removed = array_flip(array_values(array_filter((array) ($stored['removed'] ?? []), 'is_string')));
        $limits = \is_array($stored['limits'] ?? null) ? $stored['limits'] : [];
        $placedIn = \is_array($stored['columns'] ?? null) ? $stored['columns'] : [];

        $position = 0;
        $placed = [];
        foreach ($catalog as $card) {
            $id = $card['id'];
            $card['removed'] = isset($removed[$id]);
            $card['column'] = max(0, min($columns - 1, (int) ($placedIn[$id] ?? $card['column'] ?? 0)));
            $card['limitValue'] = null;
            if (($card['limit'] ?? null) !== null) {
                [$default, $min, $max] = $card['limit'];
                $card['limitValue'] = max($min, min($max, (int) ($limits[$id] ?? $default)));
            }
            $placed[] = [$order[$id] ?? \PHP_INT_MAX, $position++, $card];
        }

        usort($placed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $row): array => $row[2], $placed);
    }

    /**
     * What a client may store: known-shaped ids, bounded integers, nothing else.
     *
     * @param array<string, mixed> $input
     *
     * @return array{order: list<string>, columns: array<string, int>, removed: list<string>, limits: array<string, int>}
     */
    public static function normalize(array $input, int $columns = self::COLUMNS): array
    {
        $ids = static fn (mixed $list): array => array_values(array_unique(array_filter(
            (array) $list,
            static fn (mixed $id): bool => \is_string($id) && preg_match(self::ID_PATTERN, $id) === 1,
        )));

        $limits = [];
        foreach ((array) ($input['limits'] ?? []) as $id => $value) {
            if (\is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 && is_numeric($value)) {
                $limits[$id] = max(1, min(50, (int) $value));
            }
        }

        $placed = [];
        foreach ((array) ($input['columns'] ?? []) as $id => $value) {
            if (\is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 && is_numeric($value)) {
                $placed[$id] = max(0, min($columns - 1, (int) $value));
            }
        }

        return ['order' => $ids($input['order'] ?? []), 'columns' => $placed, 'removed' => $ids($input['removed'] ?? []), 'limits' => $limits];
    }
}

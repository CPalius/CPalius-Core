<?php

declare(strict_types=1);

namespace Modules\Widget\Service;

use App\Core\Widget\WidgetArea;
use App\Core\Widget\WidgetAreaProviderInterface;
use App\Core\Widget\ScopedWidgetTypeInterface;
use App\Core\Widget\WidgetPreset;
use App\Core\Widget\WidgetTypeInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Every widget type and area the active modules contribute. Types and areas are indexed by id on first
 * use; an id that two modules both claim keeps the first one registered.
 */
final class WidgetRegistry
{
    /** Page words a theme may pass to cp_widget_area() and an admin may limit a widget to. */
    public const PAGES = ['list', 'post', 'category', 'tag', 'archive', 'search', 'forum', 'doc'];

    /** @var array<string, WidgetTypeInterface>|null */
    private ?array $types = null;

    /** @var array<string, WidgetArea>|null */
    private ?array $areas = null;

    /**
     * @param iterable<WidgetTypeInterface>         $typeSource
     * @param iterable<WidgetAreaProviderInterface> $areaProviders
     */
    public function __construct(
        #[TaggedIterator('cpalius.widget_type')]
        private readonly iterable $typeSource,
        #[TaggedIterator('cpalius.widget_area_provider')]
        private readonly iterable $areaProviders,
    ) {
    }

    /**
     * @return array<string, WidgetTypeInterface>
     */
    public function types(): array
    {
        if ($this->types === null) {
            $this->types = [];
            foreach ($this->typeSource as $type) {
                $this->types[$type->id()] ??= $type;
            }
        }

        return $this->types;
    }

    public function type(string $id): ?WidgetTypeInterface
    {
        return $this->types()[$id] ?? null;
    }

    /**
     * The catalogue: types grouped under their section, sections in the order first met.
     *
     * @return array<string, list<WidgetTypeInterface>> group label key => types
     */
    public function typesByGroup(): array
    {
        $groups = [];
        foreach ($this->types() as $type) {
            $groups[$type->group()][] = $type;
        }

        return $groups;
    }

    /**
     * @return array<string, WidgetArea>
     */
    public function areas(): array
    {
        if ($this->areas === null) {
            $this->areas = [];
            foreach ($this->areaProviders as $provider) {
                foreach ($provider->areas() as $area) {
                    $this->areas[$area->id] ??= $area;
                }
            }
        }

        return $this->areas;
    }

    /**
     * The widget types worth offering in one area: general ones plus the ones written for the area's module.
     * A type scoped to another module (forum boxes in the docs) is left out of the picker.
     *
     * @return array<string, list<WidgetTypeInterface>> group label key => types
     */
    public function typesByGroupFor(string $areaId): array
    {
        $module = $this->area($areaId)?->module ?? '';
        $groups = [];
        foreach ($this->types() as $type) {
            if ($type instanceof ScopedWidgetTypeInterface && !\in_array($module, $type->modules(), true)) {
                continue;
            }
            $groups[$type->group()][] = $type;
        }

        return $groups;
    }

    /**
     * Areas grouped by the module that owns them ("" = theme or general areas).
     *
     * @return array<string, array<string, WidgetArea>>
     */
    public function areasByModule(): array
    {
        $groups = [];
        foreach ($this->areas() as $id => $area) {
            $groups[$area->module][$id] = $area;
        }
        ksort($groups);

        return $groups;
    }

    public function area(string $id): ?WidgetArea
    {
        return $this->areas()[$id] ?? null;
    }

    public function preset(string $areaId, string $presetId): ?WidgetPreset
    {
        foreach ($this->area($areaId)?->presets ?? [] as $preset) {
            if ($preset->id === $presetId) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Field defaults of a type, keyed by field name.
     *
     * @return array<string, mixed>
     */
    public function defaults(WidgetTypeInterface $type): array
    {
        $defaults = [];
        foreach ($type->fields() as $field) {
            $defaults[$field->name] = $field->default;
        }

        return $defaults;
    }
}

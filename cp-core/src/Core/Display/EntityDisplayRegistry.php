<?php

declare(strict_types=1);

namespace App\Core\Display;

use App\Core\Display\Entity\EntityDisplay;
use App\Core\Display\Repository\EntityDisplayRepository;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * Merges FieldDefinitionRegistry (what fields a bundle has) with any explicit
 * EntityDisplay overrides for one view mode, filling in field-level defaults
 * for anything unconfigured. A bundle with zero EntityDisplay rows renders
 * every view mode identically to "show every field, field's own weight" —
 * the exact behaviour FieldRenderer had before view modes existed.
 *
 * Writes (AACP + config import) must call invalidate() — same contract as
 * FieldDefinitionRegistry / VocabularyRegistry.
 */
final class EntityDisplayRegistry
{
    private const CACHE_PREFIX = 'cpalius.display.';
    private const CACHE_TTL = 3600;

    /** @var array<string, list<ResolvedDisplayField>> bundle::viewMode => rows */
    private array $memo = [];

    public function __construct(
        private readonly EntityDisplayRepository $repository,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly ViewModeRegistry $viewModes,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return list<ResolvedDisplayField> visible fields only, ordered by weight
     */
    public function visibleFields(string $bundle, string $viewMode): array
    {
        return array_values(array_filter(
            $this->resolve($bundle, $viewMode),
            static fn (ResolvedDisplayField $row): bool => $row->visible,
        ));
    }

    /**
     * @return list<ResolvedDisplayField> every field of the bundle, ordered by weight
     */
    public function resolve(string $bundle, string $viewMode): array
    {
        if (!$this->viewModes->has($viewMode)) {
            $viewMode = ViewModeRegistry::DEFAULT;
        }

        $memoKey = $bundle.'::'.$viewMode;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $overrides = $this->cachedOverrides($bundle, $viewMode);

        $rows = [];
        foreach ($this->fieldDefinitions->getFieldsForBundle($bundle) as $definition) {
            $override = $overrides[$definition->getName()] ?? null;
            $rows[] = new ResolvedDisplayField(
                definition: $definition,
                visible: $override !== null ? (bool) $override['visible'] : true,
                weight: $override !== null ? (int) $override['weight'] : $definition->getWeight(),
                labelDisplay: $override !== null ? (string) $override['label_display'] : EntityDisplay::LABEL_ABOVE,
            );
        }

        usort($rows, static fn (ResolvedDisplayField $a, ResolvedDisplayField $b): int => $a->weight <=> $b->weight);

        return $this->memo[$memoKey] = $rows;
    }

    public function invalidate(?string $bundle = null): void
    {
        $this->memo = [];

        if (!$this->cache instanceof CacheItemPoolInterface) {
            return;
        }

        $bundles = $bundle !== null ? [$bundle] : $this->repository->distinctBundles();

        try {
            foreach ($bundles as $known) {
                foreach ($this->viewModes->ids() as $viewMode) {
                    $this->cache->deleteItem(self::CACHE_PREFIX.$known.'.'.$viewMode);
                }
            }
        } catch (Throwable) {
            // Stale cache expires within the TTL; a write must not 500.
        }
    }

    /**
     * @return array<string, array{visible: bool, weight: int, label_display: string}>
     */
    private function cachedOverrides(string $bundle, string $viewMode): array
    {
        $key = self::CACHE_PREFIX.$bundle.'.'.$viewMode;

        try {
            /** @var array<string, array{visible: bool, weight: int, label_display: string}> $result */
            $result = $this->cache->get($key, function (ItemInterface $item) use ($bundle, $viewMode): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->loadOverrides($bundle, $viewMode);
            });

            return $result;
        } catch (Throwable) {
            return $this->loadOverrides($bundle, $viewMode);
        }
    }

    /**
     * @return array<string, array{visible: bool, weight: int, label_display: string}>
     */
    private function loadOverrides(string $bundle, string $viewMode): array
    {
        $out = [];
        foreach ($this->repository->findByBundleAndViewMode($bundle, $viewMode) as $row) {
            $out[$row->getFieldName()] = $row->toArray();
        }

        return $out;
    }
}

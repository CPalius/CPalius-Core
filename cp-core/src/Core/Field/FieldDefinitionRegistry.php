<?php

declare(strict_types=1);

namespace App\Core\Field;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\Repository\FieldDefinitionRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * Cached read model for FieldDefinition rows, keyed by bundle.
 *
 * Field definitions are effectively configuration (rarely changed, read on every
 * content render), so the shared cache.app layer stores their serialized form and
 * this registry rebuilds DETACHED FieldDefinition objects for read paths. The
 * AACP editor loads managed entities from the repository directly.
 *
 * Writes (AACP + seeder) must call invalidate() — same contract as SettingsRegistry.
 */
class FieldDefinitionRegistry
{
    private const CACHE_PREFIX = 'cpalius.fields.bundle.';
    private const CACHE_TTL = 3600;

    /** @var array<string, list<FieldDefinition>> per-request memo */
    private array $memo = [];

    public function __construct(
        private readonly FieldDefinitionRepository $repository,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return list<FieldDefinition> detached, ordered by weight then name
     */
    public function getFieldsForBundle(string $bundle): array
    {
        if (isset($this->memo[$bundle])) {
            return $this->memo[$bundle];
        }

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->cache->get(self::CACHE_PREFIX.$bundle, function (ItemInterface $item) use ($bundle): array {
                $item->expiresAfter(self::CACHE_TTL);

                return array_map(
                    static fn (FieldDefinition $f): array => $f->toArray(),
                    $this->repository->findByBundle($bundle),
                );
            });
        } catch (Throwable) {
            return $this->memo[$bundle] = $this->repository->findByBundle($bundle);
        }

        return $this->memo[$bundle] = array_map(
            static fn (array $row): FieldDefinition => FieldDefinition::fromArray($row),
            $rows,
        );
    }

    public function getField(string $bundle, string $name): ?FieldDefinition
    {
        foreach ($this->getFieldsForBundle($bundle) as $definition) {
            if ($definition->getName() === $name) {
                return $definition;
            }
        }

        return null;
    }

    public function hasFields(string $bundle): bool
    {
        return $this->getFieldsForBundle($bundle) !== [];
    }

    /**
     * @return list<string>
     */
    public function allBundles(): array
    {
        return $this->repository->distinctBundles();
    }

    public function invalidate(?string $bundle = null): void
    {
        $this->memo = [];

        if (!$this->cache instanceof CacheItemPoolInterface) {
            return;
        }

        try {
            if ($bundle !== null) {
                $this->cache->deleteItem(self::CACHE_PREFIX.$bundle);

                return;
            }

            foreach ($this->repository->distinctBundles() as $known) {
                $this->cache->deleteItem(self::CACHE_PREFIX.$known);
            }
        } catch (Throwable) {
            // Stale cache expires within the TTL; a field write must not 500.
        }
    }
}

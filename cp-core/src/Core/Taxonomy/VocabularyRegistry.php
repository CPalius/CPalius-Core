<?php

declare(strict_types=1);

namespace App\Core\Taxonomy;

use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\Repository\VocabularyRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * Cached read model for vocabularies (structure, rarely changed, read on every
 * field form / reference validation). Managed entities still come from the
 * repository; this hands back plain metadata rows.
 *
 * Writes (AACP + config import + seeder) must call invalidate().
 */
class VocabularyRegistry
{
    private const CACHE_KEY = 'cpalius.taxonomy.vocabularies';
    private const CACHE_TTL = 3600;

    /** @var array<string, array<string, mixed>>|null machine_name => row */
    private ?array $memo = null;

    public function __construct(
        private readonly VocabularyRepository $repository,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, array{machine_name: string, label: string, description: ?string, hierarchical: bool, weight: int}>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            /** @var array<string, array<string, mixed>> $rows */
            $rows = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);
                $out = [];
                foreach ($this->repository->findAllOrdered() as $vocabulary) {
                    $out[$vocabulary->getMachineName()] = $vocabulary->toArray();
                }

                return $out;
            });
        } catch (Throwable) {
            $rows = [];
            foreach ($this->repository->findAllOrdered() as $vocabulary) {
                $rows[$vocabulary->getMachineName()] = $vocabulary->toArray();
            }
        }

        return $this->memo = $rows;
    }

    public function has(string $machineName): bool
    {
        return isset($this->all()[$machineName]);
    }

    /**
     * @return array{machine_name: string, label: string, description: ?string, hierarchical: bool, weight: int}|null
     */
    public function get(string $machineName): ?array
    {
        return $this->all()[$machineName] ?? null;
    }

    /**
     * @return list<string>
     */
    public function machineNames(): array
    {
        return array_keys($this->all());
    }

    /**
     * machine_name => label, for select lists.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $out = [];
        foreach ($this->all() as $name => $row) {
            $out[$name] = $row['label'];
        }

        return $out;
    }

    public function invalidate(): void
    {
        $this->memo = null;

        if ($this->cache instanceof CacheItemPoolInterface) {
            try {
                $this->cache->deleteItem(self::CACHE_KEY);
            } catch (Throwable) {
                // Stale entry expires within the TTL; a write must not fail here.
            }
        }
    }
}

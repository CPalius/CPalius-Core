<?php

declare(strict_types=1);

namespace App\Core\Field\Display;

use App\Core\Field\ReferenceTargetResolver;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped cache of referenced entities so a list that renders reference
 * fields costs one query per target type, not one per row (Manifesto Law 6.1).
 *
 * Consumers should call collect() for every id first, then get() — a lone get()
 * on an uncollected id falls back to a single-row load.
 */
final class ReferenceBatchLoader implements ResetInterface
{
    /** @var array<string, array<int, object|null>> target => id => entity|null */
    private array $loaded = [];

    /** @var array<string, array<int, true>> target => pending ids */
    private array $pending = [];

    public function __construct(
        private readonly ReferenceTargetResolver $resolver,
    ) {
    }

    public function collect(string $target, int $id): void
    {
        if ($id > 0 && !isset($this->loaded[$target][$id])) {
            $this->pending[$target][$id] = true;
        }
    }

    public function get(string $target, int $id): ?object
    {
        if ($id < 1) {
            return null;
        }

        if (\array_key_exists($id, $this->loaded[$target] ?? [])) {
            return $this->loaded[$target][$id];
        }

        $this->collect($target, $id);
        $this->flush($target);

        return $this->loaded[$target][$id] ?? null;
    }

    private function flush(string $target): void
    {
        $ids = array_keys($this->pending[$target] ?? []);
        if ($ids === []) {
            return;
        }

        $rows = $this->resolver->load($target, $ids);
        foreach ($ids as $id) {
            $this->loaded[$target][$id] = $rows[$id] ?? null;
        }

        unset($this->pending[$target]);
    }

    public function reset(): void
    {
        $this->loaded = [];
        $this->pending = [];
    }
}

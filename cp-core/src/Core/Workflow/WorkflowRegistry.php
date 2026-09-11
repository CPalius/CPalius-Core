<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Passive store of workflow definitions, parsed once and cached. Editorial YAML
 * changes need a cache clear (same as other config-as-code surfaces).
 */
class WorkflowRegistry
{
    private const CACHE_KEY = 'cpalius.workflows';
    private const CACHE_TTL = 3600;

    /** @var array<string, WorkflowDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly WorkflowDefinitionLoader $loader,
        private readonly CacheInterface $cache,
    ) {
    }

    public function has(string $name): bool
    {
        return isset($this->load()[$name]);
    }

    public function get(string $name): ?WorkflowDefinition
    {
        return $this->load()[$name] ?? null;
    }

    /**
     * @return array<string, WorkflowDefinition>
     */
    public function all(): array
    {
        return $this->load();
    }

    /**
     * @return array<string, WorkflowDefinition>
     */
    private function load(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        try {
            $serialized = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return array_map(
                    static fn (WorkflowDefinition $d): array => self::serialize($d),
                    $this->loader->loadAll(),
                );
            });

            return $this->definitions = array_map(self::unserialize(...), $serialized);
        } catch (\Throwable) {
            return $this->definitions = $this->loader->loadAll();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(WorkflowDefinition $d): array
    {
        return [
            'name' => $d->name,
            'places' => $d->places,
            'initial' => $d->initialPlace,
            'property' => $d->subjectProperty,
            'auditable' => $d->auditable,
            'labels' => $d->placeLabels,
            'publish_places' => $d->publishPlaces,
            'transitions' => array_map(
                static fn (WorkflowTransition $t): array => [
                    'name' => $t->name, 'from' => $t->from, 'to' => $t->to,
                    'capability' => $t->capability, 'label' => $t->label,
                ],
                $d->transitions,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function unserialize(array $data): WorkflowDefinition
    {
        $transitions = [];
        foreach ((array) ($data['transitions'] ?? []) as $name => $t) {
            $transitions[$name] = new WorkflowTransition(
                (string) $t['name'], (array) $t['from'], (string) $t['to'],
                $t['capability'] ?? null, $t['label'] ?? null,
            );
        }

        return new WorkflowDefinition(
            (string) $data['name'],
            array_values((array) $data['places']),
            $transitions,
            (string) $data['initial'],
            (string) ($data['property'] ?? 'status'),
            (bool) ($data['auditable'] ?? true),
            (array) ($data['labels'] ?? []),
            array_values((array) ($data['publish_places'] ?? [])),
        );
    }
}

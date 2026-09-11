<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Single source of truth for all #[AutoconfigureTag('cpalius.module_plugin')] PluginInterface implementations.
 * TaggedIterator injection with a lazy index for lookup by getName().
 */
final class PluginRegistry
{
    /** @var array<string, PluginInterface>|null */
    private ?array $pluginsByName = null;

    /**
     * @param iterable<PluginInterface> $plugins
     */
    public function __construct(
        #[TaggedIterator('cpalius.module_plugin')]
        private readonly iterable $plugins,
    ) {
    }

    public function getPlugin(string $name): ?PluginInterface
    {
        return $this->indexedPlugins()[$name] ?? null;
    }

    /**
     * @return list<PluginInterface>
     */
    public function getActivePlugins(): array
    {
        return array_values(array_filter(
            $this->indexedPlugins(),
            static fn (PluginInterface $plugin): bool => $plugin->isActive(),
        ));
    }

    /**
     * Returns all registered plugins without isActive() filter (AACP plugin management screen).
     *
     * @return list<PluginInterface>
     */
    public function getAllPlugins(): array
    {
        return array_values($this->indexedPlugins());
    }

    /**
     * @return array<string, PluginInterface>
     */
    private function indexedPlugins(): array
    {
        if ($this->pluginsByName !== null) {
            return $this->pluginsByName;
        }

        $indexed = [];
        foreach ($this->plugins as $plugin) {
            $indexed[$plugin->getName()] = $plugin;
        }

        return $this->pluginsByName = $indexed;
    }
}

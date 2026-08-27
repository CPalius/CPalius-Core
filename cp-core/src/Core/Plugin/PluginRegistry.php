<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * #[AutoconfigureTag('cpalius.module_plugin')] ile etiketlenmiş TÜM
 * PluginInterface implementasyonlarının tek doğruluk kaynağı.
 *
 * AACPController'ın $systemWidgetProviders enjeksiyonuyla aynı desen
 * (TaggedIterator üzerinden iterable enjeksiyonu) — burada ayrıca
 * isimle (getName()) erişim sağlamak için lazy bir indeks kurulur.
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
     * Faz 4: AACP "Modül Eklentileri" yönetim ekranı için — isActive()
     * FİLTRESİ OLMADAN kayıtlı TÜM plugin'leri döner. getActivePlugins()
     * burada KULLANILAMAZ: bir yönetici zaten pasif ettiği bir eklentiyi
     * de listede görüp tekrar aktive edebilmelidir (bkz. AACPController::plugins()).
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

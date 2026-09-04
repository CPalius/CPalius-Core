<?php

declare(strict_types=1);

namespace App\Core\Portal;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Module-supplied data for a homepage portal block.
 * ThemeController tries tagged providers; first supports($id) wins.
 * Disabled modules are absent from the container — blocks then use Twig-only fallbacks.
 */
#[AutoconfigureTag('cpalius.portal.block_data_provider')]
interface PortalBlockDataProviderInterface
{
    public function supports(string $blockId): bool;

    /**
     * @param array<string, mixed> $block Studio layout row (id, limit, title, …)
     *
     * @return array<string, mixed>|null null = hide the block / no data
     */
    public function provide(string $blockId, array $block, string $locale): ?array;
}

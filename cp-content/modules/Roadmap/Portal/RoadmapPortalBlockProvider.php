<?php

declare(strict_types=1);

namespace Modules\Roadmap\Portal;

use App\Core\Portal\PortalBlockDataProviderInterface;
use App\Core\Settings\SettingsRegistry;
use Modules\Roadmap\Service\RoadmapFeedService;

/**
 * Homepage "roadmap" portal block: live native + blog + forum data.
 */
final class RoadmapPortalBlockProvider implements PortalBlockDataProviderInterface
{
    public function __construct(
        private readonly RoadmapFeedService $feedService,
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    public function supports(string $blockId): bool
    {
        return $blockId === 'roadmap';
    }

    public function provide(string $blockId, array $block, string $locale): ?array
    {
        // Studio homepage block "limit" may override the native count.
        $nativeLimit = (int) ($block['limit'] ?? 0);
        if ($nativeLimit <= 0) {
            $nativeLimit = (int) $this->settingsRegistry->get('roadmap.portal_native_limit', 3);
        }

        return $this->feedService->buildPortalSnapshot(
            $locale,
            $nativeLimit,
            (int) $this->settingsRegistry->get('roadmap.portal_blog_limit', 3),
            (int) $this->settingsRegistry->get('roadmap.portal_forum_limit', 3),
        );
    }
}

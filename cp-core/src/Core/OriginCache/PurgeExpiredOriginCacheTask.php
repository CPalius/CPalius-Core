<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Settings\SettingsRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Drops origin HTML snapshots older than the configured TTL.
 */
final class PurgeExpiredOriginCacheTask
{
    public function __construct(
        private readonly OriginCacheStore $store,
        private readonly OriginCachePolicy $policy,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[CpCronJob(schedule: '*/15 * * * *', name: 'cpalius.origin_cache.purge', description: 'Purge expired CPalius origin HTML cache files')]
    public function execute(): string
    {
        $ttl = $this->policy->normalizeTtl($this->settingsRegistry->get(
            'performance.cpalius.ttl',
            OriginCachePolicy::DEFAULT_TTL,
        ));
        $deleted = $this->store->purgeExpired($ttl);

        return $this->translator->trans('aacp.origin_cache.purge_result', [
            'count' => $deleted,
            'ttl' => $ttl,
        ]);
    }
}

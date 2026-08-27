<?php

declare(strict_types=1);

namespace App\Core\Aacp\Widgets;

use App\Core\Aacp\SystemWidgetData;
use App\Core\Aacp\SystemWidgetProviderInterface;
use App\Repository\PerformanceBackendStatusRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ana AACP dashboard'unda Memcached durumunu gösteren kart — bkz.
 * RedisSystemWidgetProvider docblock'u (aynı "sadece son kayıt, canlı
 * probe yok" ve "description PHP tarafında önceden çevrilir" ilkeleri).
 */
final class MemcachedSystemWidgetProvider implements SystemWidgetProviderInterface
{
    public function __construct(
        private readonly PerformanceBackendStatusRepository $repository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getWidget(): SystemWidgetData
    {
        $status = $this->repository->findOneByBackendId('memcached');
        $enabled = $status?->isEnabled() ?? false;

        return new SystemWidgetData(
            title: 'Memcached',
            value: $enabled ? 1 : 0,
            description: $status === null || $status->getLastTestedAt() === null
                ? $this->translator->trans('aacp.performance.never_tested')
                : $this->translator->trans('aacp.performance.widget_summary', [
                    'status' => $this->translator->trans($status->isLastTestSuccess() ? 'aacp.performance.status.ok_disabled' : 'aacp.performance.status.disabled'),
                    'date' => $status->getLastTestedAt()->format('d.m.Y H:i'),
                ]),
            linkRoute: 'aacp_performance',
            variant: $enabled ? 'default' : 'danger',
        );
    }
}

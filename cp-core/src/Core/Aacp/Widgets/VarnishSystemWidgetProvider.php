<?php

declare(strict_types=1);

namespace App\Core\Aacp\Widgets;

use App\Core\Aacp\SystemWidgetData;
use App\Core\Aacp\SystemWidgetProviderInterface;
use App\Core\Performance\PerformanceBackendRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Varnish status card on AACP dashboard — see RedisSystemWidgetProvider (last DB record, no live probe).
 */
final class VarnishSystemWidgetProvider implements SystemWidgetProviderInterface
{
    public function __construct(
        private readonly PerformanceBackendRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getWidget(): SystemWidgetData
    {
        $status = $this->registry->getStatus('varnish');
        $enabled = $status?->isEnabled() ?? false;

        return new SystemWidgetData(
            title: 'Varnish',
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

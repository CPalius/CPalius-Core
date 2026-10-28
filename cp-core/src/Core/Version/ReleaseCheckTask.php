<?php

declare(strict_types=1);

namespace App\Core\Version;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Extension\ExtensionCatalogue;

/**
 * Daily "is there a new CPalius?" check.
 *
 * Scheduled at 04:17 rather than on the hour: every installation running this
 * would otherwise hit the update server within the same second, and the
 * point of a version check is not to look like a thundering herd.
 */
final class ReleaseCheckTask
{
    public function __construct(
        private readonly ReleaseChecker $releases,
        private readonly PatchChecker $patches,
        private readonly ExtensionCatalogue $catalogue,
        private readonly UpdateNotifier $notifier,
    ) {
    }

    /**
     * Both checks share one job rather than having one each.
     *
     * They answer the same question — "is there something newer?" — and an
     * operator who wants to know the answer wants both halves of it. Two jobs
     * would also mean two schedules to keep apart and two chances for one of
     * them to be disabled without anyone noticing the other was the one
     * carrying security patches.
     *
     * Neither call throws; both return a line for the cron log and keep their
     * previous result on failure.
     */
    #[CpCronJob(schedule: '17 */6 * * *', name: 'core.release_check', description: 'Check for a newer CPalius release, module/theme versions and pending file patches, and tell the administrators')]
    public function execute(): string
    {
        $line = $this->releases->refresh().' '.$this->patches->refresh().' '.$this->catalogue->refresh();

        try {
            $line .= ' Announced '.$this->notifier->announce().' update(s).';
        } catch (\Throwable $e) {
            $line .= ' Update announcement failed: '.$e->getMessage();
        }

        return $line;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Media;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Settings\SettingsRegistry;

/**
 * Nightly catch-up for media offload.
 *
 * Uploads are pushed synchronously when they arrive, so this is not the main
 * path — it is the one that closes the gaps that path structurally cannot:
 *
 *   - thumbnails, which ImageProcessor generates during a page render, where
 *     a blocking upload to a bucket would put someone else's network on the
 *     critical path of a visitor's page
 *   - anything whose push failed while the target was unreachable
 *   - the entire existing library, on a site that turns offload on years in
 *
 * Off by default. An operator who has just configured a bucket should see the
 * sweep run because they asked it to, not discover at 03:40 that their site
 * spent the night uploading forty thousand files.
 */
final class MediaOffloadSweepTask
{
    public function __construct(
        private readonly MediaOffloader $offloader,
        private readonly SettingsRegistry $settings,
    ) {
    }

    #[CpCronJob(schedule: '40 3 * * *', name: 'core.media_offload_sweep', description: 'Push media that is not yet on the remote storage target')]
    public function execute(): string
    {
        if ((bool) $this->settings->get('storage.media.sweep_enabled', false) !== true) {
            return 'Media offload sweep is disabled.';
        }

        if (!$this->offloader->isEnabled()) {
            return 'No verified media storage target; nothing swept.';
        }

        $report = $this->offloader->sweep();

        return sprintf(
            'Swept %d file(s): %d uploaded, %d already present, %d failed.%s',
            $report['scanned'],
            $report['uploaded'],
            $report['skipped'],
            $report['failed'],
            $report['wrapped'] ? ' Reached the end; the next run starts over.' : ' Continues from '.$report['cursor'].'.',
        );
    }
}

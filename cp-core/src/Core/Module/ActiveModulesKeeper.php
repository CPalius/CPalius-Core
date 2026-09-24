<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Puts the site's module list back after an update that was started by an
 * older release.
 *
 * Releases before this one overwrite cp-core/config/active_modules.php with
 * the list shipped in the package, which turns off modules back on. The
 * updater does keep a backup of the file it replaced. The first request on
 * the new code copies that backup back, once. Later edits by the operator
 * are left alone.
 */
final class ActiveModulesKeeper
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function restoreFromLatestBackup(): void
    {
        $backups = glob($this->projectDir.'/cp-core/var/update/backup-*/cp-core/config/active_modules.php') ?: [];
        if ($backups === []) {
            return;
        }

        usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $backup = $backups[0];
        $marker = $this->projectDir.'/cp-core/var/update/.active-modules-kept';

        if (is_file($marker) && file_get_contents($marker) === $backup) {
            return;
        }

        $live = $this->projectDir.'/cp-core/config/active_modules.php';
        if (!@copy($backup, $live)) {
            return;
        }

        @file_put_contents($marker, $backup);
    }
}

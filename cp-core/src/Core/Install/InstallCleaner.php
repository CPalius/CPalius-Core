<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * Removes the web installer after a successful run.
 *
 * The classes under App\Core\Install stay: the front controller still asks
 * InstallGate on every request. What goes away is the form, the session
 * store, and any path that would accept database credentials again.
 */
final class InstallCleaner
{
    /**
     * @return list<string> Paths that could not be removed
     */
    public function cleanup(string $projectDir): array
    {
        $left = [];

        $wizard = $projectDir.'/cp-core/install';
        if (is_dir($wizard) && !$this->removeTree($wizard)) {
            $left[] = 'cp-core/install';
        }

        $sessions = $projectDir.'/cp-core/var/install-sessions';
        if (is_dir($sessions) && !$this->removeTree($sessions)) {
            $left[] = 'cp-core/var/install-sessions';
        }

        return $left;
    }

    private function removeTree(string $dir): bool
    {
        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        $ok = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.\DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $ok = $this->removeTree($path) && $ok;
                continue;
            }

            if (!unlink($path) && is_file($path)) {
                $ok = false;
            }
        }

        if (!rmdir($dir) && is_dir($dir)) {
            return false;
        }

        return $ok;
    }
}

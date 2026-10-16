<?php

declare(strict_types=1);

namespace App\Core\Version;

use App\Core\Account\AdminAlertNotifier;
use App\Core\Extension\ExtensionCatalogue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Puts every available update (core, modules, themes) into the administrators' notification bell.
 *
 * One notification per item and version: the dedupe key stops the daily check from repeating itself,
 * and a newer version of the same item raises a fresh one.
 */
final class UpdateNotifier
{
    public const EVENT = 'system.update.available';

    public function __construct(
        private readonly ReleaseChecker $releases,
        private readonly ExtensionCatalogue $catalogue,
        private readonly AdminAlertNotifier $alerts,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return int how many items were announced to the administrators
     */
    public function announce(): int
    {
        $sent = 0;
        $status = $this->releases->status();

        if ($status !== null && $status['outdated']) {
            $this->send('core', 'CPalius CMF', $status['version'], 'aacp_updates_index');
            ++$sent;
        }

        foreach (['modules', 'themes'] as $type) {
            foreach ($this->catalogue->entries($type) as $entry) {
                if ($entry['status'] !== 'update' || !$entry['compatible']) {
                    continue;
                }

                $this->send($type.':'.$entry['name'], $entry['title'], $entry['latest'], $type === 'modules' ? 'aacp_store' : 'aacp_theme_store');
                ++$sent;
            }
        }

        return $sent;
    }

    private function send(string $key, string $title, string $version, string $route): void
    {
        $this->alerts->notifyAdmins(self::EVENT, [
            'name' => $title,
            'version' => $version,
            'url' => $this->urls->generate($route),
        ], null, 'update:'.$key.':'.$version);
    }
}

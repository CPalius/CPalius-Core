<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remembers which update hooks have already run.
 *
 * Stored in cp_settings rather than a table of its own, following the
 * convention module versions already use (module_lifecycle.*.installed_version).
 * That keeps cp:update working on an installation with no extra migration
 * applied — which matters, because the fi rst thing cp:update does is apply
 * migrations, and a ledger that needed its own table could not record the run
 * that created it.
 *
 * Writes go through the repository directly rather than SettingsRegistry: the
 * registry is a read-through cache shaped for request-time reads, and the
 * ledger must be durable the moment a hook finishes. Reads go through
 * SettingsRegistry::getRaw() instead of a repository query of their own: this
 * class's own per-request memo already made hasRun() cheap within one
 * request, but every admin page that also checks for pending updates, the
 * latest release, and this ledger was still three-plus separate SELECTs
 * against cp_settings, on top of whatever SettingsRegistry itself reads for
 * the page — easily enough to trip Law 6.1's ten-per-table guard on a busy
 * dashboard and get this hook's inspection silently skipped for that request.
 * getRaw() folds this into the query SettingsRegistry was already going to
 * run anyway.
 */
final class UpdateHookLedger
{
    private const KEY = 'update.core.applied_hooks';

    /** @var list<string>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    public function hasRun(string $hookId): bool
    {
        return \in_array($hookId, $this->applied(), true);
    }

    /**
     * @return list<string>
     */
    public function applied(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $raw = $this->settingsRegistry->getRaw(self::KEY);

        if (!\is_string($raw) || trim($raw) === '') {
            return $this->memo = [];
        }

        $decoded = json_decode($raw, true);

        // A corrupt ledger must not be read as "everything already ran": that
        // would silently skip every pending hook. An unreadable ledger is
        // treated as empty, so hooks re-run — which their idempotence covers.
        if (!\is_array($decoded)) {
            return $this->memo = [];
        }

        return $this->memo = array_values(array_filter($decoded, 'is_string'));
    }

    public function markRun(string $hookId): void
    {
        $applied = $this->applied();

        if (\in_array($hookId, $applied, true)) {
            return;
        }

        $applied[] = $hookId;
        $this->persist($applied);
    }

    /**
     * Records a hook without running it — used when a fresh installation is
     * stamped as already current, so historical hooks do not fire against a
     * database that was created with their outcome baked in.
     *
     * @param list<string> $hookIds
     */
    public function markAllRun(array $hookIds): void
    {
        $applied = $this->applied();

        foreach ($hookIds as $hookId) {
            if (!\in_array($hookId, $applied, true)) {
                $applied[] = $hookId;
            }
        }

        $this->persist($applied);
    }

    /**
     * @param list<string> $applied
     */
    private function persist(array $applied): void
    {
        $setting = $this->settings->findOneBy(['settingKey' => self::KEY]);

        if (!$setting instanceof Setting) {
            $setting = new Setting(self::KEY);
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue(json_encode($applied, \JSON_THROW_ON_ERROR));

        $this->entityManager->flush();

        $this->memo = $applied;

        // Otherwise getRaw() keeps serving the pre-write value for up to
        // SettingsRegistry::CACHE_TTL, and a hook that just ran would still
        // look pending to the next request.
        $this->settingsRegistry->clearCache();
    }
}

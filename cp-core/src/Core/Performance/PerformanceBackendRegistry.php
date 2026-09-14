<?php

declare(strict_types=1);

namespace App\Core\Performance;

use App\Core\OriginCache\OriginCacheStore;
use App\Core\Settings\SettingsRegistry;
use App\Entity\PerformanceBackendStatus;
use App\Entity\Setting;
use App\Repository\PerformanceBackendStatusRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Orchestrates performance-backend config, live connection tests, and enable gating.
 * enable() is allowed only when the last recorded test succeeded.
 */
final class PerformanceBackendRegistry implements ResetInterface
{
    private const CONFIG_KEYS = [
        'redis' => ['host', 'port', 'password', 'timeout'],
        'memcached' => ['host', 'port', 'timeout'],
        'varnish' => ['backend_url', 'port', 'ttl', 'excludes', 'timeout'],
        'pagespeed' => ['check_url', 'timeout'],
        'cpalius' => ['ttl', 'excludes', 'minify', 'compress_assets', 'compress_images', 'shield'],
    ];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = ['minify', 'compress_assets', 'compress_images', 'shield'];

    private const URL_FIELDS = ['backend_url', 'check_url'];

    /** @var list<PerformanceBackendCheckerInterface> */
    private readonly array $checkers;

    /** @var array<string, PerformanceBackendStatus>|null request-scoped memo */
    private ?array $statusMap = null;

    public function __construct(
        RedisConnectionTester $redisConnectionTester,
        MemcachedConnectionTester $memcachedConnectionTester,
        VarnishStatusChecker $varnishStatusChecker,
        NginxPageSpeedStatusChecker $nginxPageSpeedStatusChecker,
        OriginCacheChecker $originCacheChecker,
        private readonly OriginCacheStore $originCacheStore,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly PerformanceBackendStatusRepository $statusRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->checkers = [
            $redisConnectionTester,
            $memcachedConnectionTester,
            $varnishStatusChecker,
            $nginxPageSpeedStatusChecker,
            $originCacheChecker,
        ];
    }

    public function isKnownBackend(string $backendId): bool
    {
        return isset(self::CONFIG_KEYS[$backendId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(string $backendId): array
    {
        $config = [];
        foreach (self::CONFIG_KEYS[$backendId] ?? [] as $field) {
            $config[$field] = $this->settingsRegistry->get('performance.'.$backendId.'.'.$field);
        }

        return $config;
    }

    public function getStatus(string $backendId): ?PerformanceBackendStatus
    {
        return $this->statusMap()[$backendId] ?? null;
    }

    /**
     * @return array<string, PerformanceBackendStatus>
     */
    public function getAllStatuses(): array
    {
        return $this->statusMap();
    }

    /**
     * Every status row in one query, remembered for the rest of the request.
     *
     * Asking the repository per backend was a real N+1: five backends read
     * from several call sites each put this table over the eleven-read budget
     * in QueryCounter, and the guard then aborted whatever was running — in
     * practice the origin cache write, which silently stopped caching pages.
     *
     * The map holds managed entities, so enable()/disable() mutations are
     * visible through it without invalidation; only a newly persisted row has
     * to be added by hand.
     *
     * @return array<string, PerformanceBackendStatus>
     */
    private function statusMap(): array
    {
        return $this->statusMap ??= $this->statusRepository->findAllAsMap();
    }

    /**
     * Long-running workers (FrankenPHP, Swoole) keep services between
     * requests; without this the map would outlive the data it describes.
     */
    public function reset(): void
    {
        $this->statusMap = null;
    }

    /**
     * Persist submitted fields, then probe with that payload (no separate Save button).
     * SettingsRegistry is cleared so the test never re-reads a stale cache.app snapshot.
     *
     * @param array<string, string> $submittedConfig
     */
    public function saveConfigAndTest(string $backendId, array $submittedConfig): PerformanceCheckResult
    {
        if (!$this->isKnownBackend($backendId)) {
            throw new \InvalidArgumentException(\sprintf('Unknown performance backend: %s', $backendId));
        }

        $merged = $this->getConfig($backendId);

        foreach (self::CONFIG_KEYS[$backendId] as $field) {
            if (\in_array($field, self::BOOLEAN_FIELDS, true)) {
                $value = isset($submittedConfig[$field]) && $submittedConfig[$field] !== '0' && $submittedConfig[$field] !== ''
                    ? '1'
                    : '0';
            } elseif (!\array_key_exists($field, $submittedConfig)) {
                continue;
            } else {
                $value = \trim((string) $submittedConfig[$field]);
                if (\in_array($field, self::URL_FIELDS, true)) {
                    $value = HttpHeaderProbe::normalizeUrl($value);
                }
            }

            $merged[$field] = $value;

            $key = 'performance.'.$backendId.'.'.$field;
            $setting = $this->settingRepository->findOneBy(['settingKey' => $key]);
            if (!$setting instanceof Setting) {
                $setting = new Setting($key, 'core');
                $this->entityManager->persist($setting);
            }

            $setting->setSettingValue($value);
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();

        $checker = $this->findChecker($backendId);
        $result = $checker->testConnection($merged);

        $status = $this->getStatus($backendId);
        if (!$status instanceof PerformanceBackendStatus) {
            $status = new PerformanceBackendStatus($backendId);
            $this->entityManager->persist($status);
            // getStatus() above populated the memo, so this keeps it truthful.
            $this->statusMap[$backendId] = $status;
        }

        $status->recordTestResult($result->success, $result->status, $result->messageKey, $result->messageParams);
        $this->entityManager->flush();

        if ($backendId === 'cpalius' && !$result->success) {
            $this->originCacheStore->disable();
        }

        return $result;
    }

    /**
     * Enable only after a successful test. Throws a translation key, not a display string.
     */
    public function enable(string $backendId): void
    {
        $status = $this->getStatus($backendId);

        if ($status === null || !$status->isLastTestSuccess()) {
            throw new \DomainException('aacp.performance.enable_requires_success_test');
        }

        $status->setIsEnabled(true);
        $this->entityManager->flush();

        if ($backendId === 'cpalius') {
            $this->originCacheStore->enable();
        }
    }

    public function disable(string $backendId): void
    {
        $status = $this->getStatus($backendId);
        if ($status === null) {
            return;
        }

        $status->setIsEnabled(false);
        $this->entityManager->flush();

        if ($backendId === 'cpalius') {
            $this->originCacheStore->disable();
        }
    }

    private function findChecker(string $backendId): PerformanceBackendCheckerInterface
    {
        foreach ($this->checkers as $checker) {
            if ($checker->getBackendId() === $backendId) {
                return $checker;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Unknown performance backend: %s', $backendId));
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Performance;

use App\Core\Settings\SettingsRegistry;
use App\Entity\PerformanceBackendStatus;
use App\Entity\Setting;
use App\Repository\PerformanceBackendStatusRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PerformanceController'ın tek servis kaynağı: dört backend'in config
 * kaydını, bağlantı testini ve aktivasyon gating'ini tek bir yerde
 * orkestre eder (CacheRebuildManager'ın "controller ince, iş mantığı
 * serviste" ilkesiyle aynı).
 *
 * Gating KURALI (bu sınıfın var oluş sebebi): enable() bir backend'i
 * yalnızca en son kaydedilmiş PerformanceBackendStatus::isLastTestSuccess()
 * true İSE etkinleştirebilir. Bu kontrol İSTEMCİ TARAFI değil, sunucu
 * tarafıdır — PerformanceController bu DomainException'ı yakalayıp 422
 * döner, böylece "sunucuda Redis yoksa aktif edilemez" kuralı sadece
 * UI'da buton gizleme ile değil, gerçek bir sunucu tarafı zorunlulukla
 * sağlanır.
 *
 * Checker'lar bir #[TaggedIterator] İLE DEĞİL, 4 somut ve adı bilinen
 * servis olarak doğrudan enjekte edilir — CacheRebuildManager'ın
 * "cache.app" için yaptığı tercihle aynı gerekçe: set kapalı/sabit (sadece
 * bu 4 backend var, bir modülün yeni bir tane eklemesi beklenmiyor), bu
 * yüzden açık ve statik analiz dostu somut bağımlılık, soyut bir
 * tagged_iterator'dan daha basittir (YAGNI).
 */
final class PerformanceBackendRegistry
{
    private const CONFIG_KEYS = [
        'redis' => ['host', 'port', 'password', 'timeout'],
        'memcached' => ['host', 'port', 'timeout'],
        'varnish' => ['backend_url', 'timeout'],
        'pagespeed' => ['check_url', 'timeout'],
    ];

    /** @var list<PerformanceBackendCheckerInterface> */
    private readonly array $checkers;

    public function __construct(
        RedisConnectionTester $redisConnectionTester,
        MemcachedConnectionTester $memcachedConnectionTester,
        VarnishStatusChecker $varnishStatusChecker,
        NginxPageSpeedStatusChecker $nginxPageSpeedStatusChecker,
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
        return $this->statusRepository->findOneByBackendId($backendId);
    }

    /**
     * @return array<string, PerformanceBackendStatus>
     */
    public function getAllStatuses(): array
    {
        return $this->statusRepository->findAllAsMap();
    }

    /**
     * Gönderilen config alanlarını kaydeder, ardından ilgili checker ile
     * bağlantıyı test eder ve sonucu PerformanceBackendStatus'a yazar.
     * "Test = kaydet + dene" tek bir adımdır (bkz. plan) — ayrı bir "kaydet"
     * butonu yoktur.
     *
     * @param array<string, string> $submittedConfig
     */
    public function saveConfigAndTest(string $backendId, array $submittedConfig): PerformanceCheckResult
    {
        if (!$this->isKnownBackend($backendId)) {
            throw new \InvalidArgumentException(\sprintf('Bilinmeyen performans backend\'i: %s', $backendId));
        }

        foreach (self::CONFIG_KEYS[$backendId] as $field) {
            if (!\array_key_exists($field, $submittedConfig)) {
                continue;
            }

            $key = 'performance.'.$backendId.'.'.$field;
            $setting = $this->settingRepository->findOneBy(['settingKey' => $key]);
            if (!$setting instanceof Setting) {
                $setting = new Setting($key, 'core');
                $this->entityManager->persist($setting);
            }

            $setting->setSettingValue(\trim((string) $submittedConfig[$field]));
        }

        $this->entityManager->flush();

        $checker = $this->findChecker($backendId);
        $result = $checker->testConnection($this->getConfig($backendId));

        $status = $this->statusRepository->findOneByBackendId($backendId);
        if (!$status instanceof PerformanceBackendStatus) {
            $status = new PerformanceBackendStatus($backendId);
            $this->entityManager->persist($status);
        }

        $status->recordTestResult($result->success, $result->status, $result->messageKey, $result->messageParams);
        $this->entityManager->flush();

        return $result;
    }

    /**
     * Yalnızca en son test BAŞARILIYSA etkinleştirir. Sunucuda backend
     * yoksa veya bağlantı testi hiç yapılmadıysa DomainException fırlatır.
     *
     * İstisna mesajı BİLİNÇLİ OLARAK hazır bir metin değil, bir çeviri
     * anahtarıdır ('aacp.performance.enable_requires_success_test') —
     * PerformanceController bunu yakalayıp TranslatorInterface ile
     * isteğin diline göre çevirir (bkz. PerformanceController::enable()).
     */
    public function enable(string $backendId): void
    {
        $status = $this->statusRepository->findOneByBackendId($backendId);

        if ($status === null || !$status->isLastTestSuccess()) {
            throw new \DomainException('aacp.performance.enable_requires_success_test');
        }

        $status->setIsEnabled(true);
        $this->entityManager->flush();
    }

    public function disable(string $backendId): void
    {
        $status = $this->statusRepository->findOneByBackendId($backendId);
        if ($status === null) {
            return;
        }

        $status->setIsEnabled(false);
        $this->entityManager->flush();
    }

    private function findChecker(string $backendId): PerformanceBackendCheckerInterface
    {
        foreach ($this->checkers as $checker) {
            if ($checker->getBackendId() === $backendId) {
                return $checker;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Bilinmeyen performans backend\'i: %s', $backendId));
    }
}

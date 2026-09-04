<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Performance;

use App\Core\Localization\LocaleProvider;
use App\Core\Performance\MemcachedConnectionTester;
use App\Core\Performance\NginxPageSpeedStatusChecker;
use App\Core\Performance\PerformanceBackendRegistry;
use App\Core\Performance\RedisConnectionTester;
use App\Core\Performance\VarnishStatusChecker;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\LocaleRepository;
use App\Repository\PerformanceBackendStatusRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(PerformanceBackendRegistry::class)]
final class PerformanceBackendRegistryTest extends TestCase
{
    public function testSaveConfigAndTestPersistsNormalizedUrlAndClearsSettingsCache(): void
    {
        $urlSetting = new Setting('performance.varnish.backend_url', 'core');
        $urlSetting->setSettingValue('http://127.0.0.1/');
        $timeoutSetting = new Setting('performance.varnish.timeout', 'core');
        $timeoutSetting->setSettingValue('2');

        $localeRepository = $this->createMock(LocaleRepository::class);
        $localeRepository->method('findActive')->willReturn([]);
        $localeProvider = new LocaleProvider($localeRepository, new ArrayAdapter(), 'tr', 'tr');

        $settingRepository = $this->createMock(SettingRepository::class);
        $settingRepository->expects(self::exactly(2))->method('findAllAsMap')->willReturn([
            'performance.varnish.backend_url' => 'http://127.0.0.1/',
            'performance.varnish.timeout' => '2',
        ]);
        $settingRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($urlSetting, $timeoutSetting): ?Setting {
                return match ($criteria['settingKey'] ?? '') {
                    'performance.varnish.backend_url' => $urlSetting,
                    'performance.varnish.timeout' => $timeoutSetting,
                    default => null,
                };
            },
        );

        $settingsRegistry = new SettingsRegistry($settingRepository, $localeProvider, new RequestStack(), new ArrayAdapter());
        $settingsRegistry->addDefinition(new SettingDefinition(
            'performance.varnish.backend_url',
            'Varnish check URL',
            'text',
            'http://127.0.0.1/',
            [],
            'performance',
            'performance',
        ));
        $settingsRegistry->addDefinition(new SettingDefinition(
            'performance.varnish.timeout',
            'Varnish timeout',
            'text',
            '2',
            [],
            'performance',
            'performance',
        ));

        $statusRepository = $this->createMock(PerformanceBackendStatusRepository::class);
        $statusRepository->method('findOneByBackendId')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('flush');
        $entityManager->expects(self::atLeastOnce())->method('persist');

        $registry = new PerformanceBackendRegistry(
            new RedisConnectionTester(),
            new MemcachedConnectionTester(),
            new VarnishStatusChecker(),
            new NginxPageSpeedStatusChecker(),
            $settingsRegistry,
            $settingRepository,
            $statusRepository,
            $entityManager,
        );

        $registry->saveConfigAndTest('varnish', [
            'backend_url' => '127.0.0.1:6081',
            'timeout' => '2',
        ]);

        self::assertSame('http://127.0.0.1:6081', $urlSetting->getSettingValue());

        // Second findAllAsMap: proves clearCache() dropped the in-request memo.
        $settingsRegistry->get('performance.varnish.backend_url');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Settings;

use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(SettingsRegistry::class)]
final class SettingsRegistryTest extends TestCase
{
    public function testDefaultLocaleVariantsComeFromLocaleProvider(): void
    {
        $localeRepository = $this->createMock(LocaleRepository::class);
        $localeRepository->method('findActive')->willReturn([]);
        $localeProvider = new LocaleProvider($localeRepository, new ArrayAdapter(), 'tr,de', 'tr');

        $repository = $this->createMock(SettingRepository::class);
        $repository->method('findAllAsMap')->willReturn([]);

        $registry = new SettingsRegistry($repository, $localeProvider, new RequestStack(), new ArrayAdapter());
        $registry->addDefinition(new SettingDefinition(
            SettingsRegistry::DEFAULT_LOCALE_KEY,
            'Default locale',
            'select',
            'tr',
            [],
            'core',
            'genel',
        ));

        $definition = $registry->getDefinition(SettingsRegistry::DEFAULT_LOCALE_KEY);

        self::assertNotNull($definition);
        self::assertSame(['tr', 'de'], array_keys($definition->variants));
        self::assertNotSame('', $definition->variants['de']);
    }

    public function testClearCacheForcesASecondRepositoryRead(): void
    {
        $localeRepository = $this->createMock(LocaleRepository::class);
        $localeRepository->method('findActive')->willReturn([]);
        $localeProvider = new LocaleProvider($localeRepository, new ArrayAdapter(), 'tr', 'tr');

        $repository = $this->createMock(SettingRepository::class);
        $repository->expects(self::exactly(2))->method('findAllAsMap')->willReturnOnConsecutiveCalls(
            ['core.site_name' => '{"tr":"Bir"}'],
            ['core.site_name' => '{"tr":"Iki"}'],
        );

        $registry = new SettingsRegistry($repository, $localeProvider, new RequestStack(), new ArrayAdapter());
        $registry->addDefinition(new SettingDefinition(
            'core.site_name',
            'Site name',
            'text',
            'CPalius',
            [],
            'core',
            'genel',
            true,
        ));

        self::assertSame('Bir', $registry->get('core.site_name'));
        $registry->clearCache();
        self::assertSame('Iki', $registry->get('core.site_name'));
    }
}

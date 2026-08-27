<?php

declare(strict_types=1);

namespace App\Core\Cron\DependencyInjection\Compiler;

use App\Core\Cron\CronCommandWhitelist;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * CronCommandWhitelist'i container derleme zamanında doldurur.
 *
 * SettingsRegistrationPass/AdminMenuRegistrationPass ile BİREBİR AYNI
 * iskelet: dosya sistemi taraması + Reflection, modül izolasyonu
 * try/catch(Throwable) ile sağlanır. #[AsCommand] attribute'u TARGET_CLASS
 * olduğu için (REPEATABLE değil) her sınıftan en fazla bir komut adı
 * toplanır.
 *
 * Taranan konumlar:
 *   1) cp-core/src/Core/Command — çekirdek uygulama komutları.
 *   2) Her modülün Command dizini (varsa) — modül izolasyonu ile.
 *
 * Sadece "cp:" önekli komut adları toplanır (bkz. CronCommandWhitelist
 * docblock'undaki güvenlik gerekçesi) — vendor/çekirdek Symfony/Doctrine
 * komutları (ör. "cache:clear", "dbal:run-sql") bu tarama tarafından hiç
 * ELE ALINMAZ, sonradan filtrelenmez; whitelist'e girmeleri imkansızdır.
 */
final class CronCommandRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';
    private const ALLOWED_PREFIX = 'cp:';

    /**
     * Hibrit Otomasyon Motoru'nun kod tabanlı görevler için köprü komutu
     * (bkz. RunVirtualCronJobCommand) BİLİNÇLİ olarak DB "Yeni Cron İşi"
     * formunun komut dropdown'ında GÖRÜNMEZ: bu komut serbest bir "jobName"
     * argümanı bekler ve sadece CronManager (dispatcher/AACP "Şimdi
     * Çalıştır") tarafından, zaten bilinen bir sanal görev adıyla iç kaynak
     * olarak tetiklenmelidir — bir yöneticinin DB'den elle rastgele bir
     * jobName ile bu köprüyü çağırması kavramsal olarak "DB cron işi"
     * değildir (whitelist güvenlik sınırını bypass etmez, ama kod/DB
     * kulvarlarının birbirine karışmasını önler).
     */
    private const EXCLUDED_COMMAND_NAMES = ['cp:cron:run-virtual'];

    public const CONTAINER_PARAMETER = 'cpalius.cron_allowed_commands';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<string> $collected */
        $collected = [];

        $coreCommandDir = $projectDir.'/cp-core/src/Core/Command';
        foreach ($this->scanDirectory($coreCommandDir, 'App\\Core\\Command\\', $container) as $name) {
            $collected[] = $name;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleCommandDir = rtrim((string) $bundleMeta['path'], '/').'/Command';
            $moduleCommandNamespace = $bundleMeta['namespace'].'\\Command\\';

            try {
                foreach ($this->scanDirectory($moduleCommandDir, $moduleCommandNamespace, $container) as $name) {
                    $collected[] = $name;
                }
            } catch (Throwable) {
                // Modül izolasyonu: bir modülün Command dizini taranırken
                // hata oluşursa sadece o modülün komutları kayıt olmaz.
            }
        }

        sort($collected);
        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition(CronCommandWhitelist::class)) {
            $container->getDefinition(CronCommandWhitelist::class)
                ->setArgument('$allowedCommandNames', $collected);
        }
    }

    /**
     * @return list<string>
     */
    private function scanDirectory(string $dir, string $namespacePrefix, ContainerBuilder $container): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $container->addResource(new DirectoryResource($dir, '/\.php$/'));

        $collected = [];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $relativePath = ltrim(substr((string) $file->getPathname(), strlen($dir)), '/\\');
            $className = $namespacePrefix.str_replace(['/', '\\'], '\\', substr($relativePath, 0, -4));

            try {
                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);

                $commandAttributes = $reflection->getAttributes(AsCommand::class);
                if ($commandAttributes === []) {
                    continue;
                }

                /** @var AsCommand $asCommand */
                $asCommand = $commandAttributes[0]->newInstance();
                $commandName = $asCommand->name;

                if ($commandName !== null
                    && str_starts_with($commandName, self::ALLOWED_PREFIX)
                    && !in_array($commandName, self::EXCLUDED_COMMAND_NAMES, true)
                ) {
                    $collected[] = $commandName;
                }
            } catch (Throwable) {
                // Tek bir dosyanın reflection'ı başarısız olursa (namespace
                // uyuşmazlığı, eksik parent class vb.) o dosya atlanır;
                // tüm tarama iptal edilmez.
                continue;
            }
        }

        return $collected;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Cron;

/**
 * Bir CronJob'un çalıştırabileceği console komutlarını "cp:" önekiyle
 * SINIRLAYAN güvenlik sınırı. AACP "Yeni Cron İşi" formu, komut adını
 * serbest metin olarak KABUL ETMEZ — bu sınıfın döndürdüğü listeden bir
 * seçim yapılır (bkz. AACPController::cronJobs() render'ındaki dropdown).
 *
 * Neden sadece "cp:*": bu projenin kendi yazdığı, tehlike yüzeyi bilinen
 * uygulama komutlarıdır (ör. cp:cache:rebuild). "dbal:run-sql",
 * "doctrine:database:drop" gibi çekirdek/vendor komutları veya üçüncü
 * parti bundle komutları BİLİNÇLİ olarak HARİÇ TUTULUR — aksi halde bir
 * yönetici (veya ele geçirilmiş bir admin hesabı) cron üzerinden rastgele
 * SQL çalıştırabilir veya veritabanını silebilirdi.
 *
 * Liste, CronCommandRegistrationPass tarafından derleme zamanında
 * SettingsRegistrationPass/AdminMenuRegistrationPass ile AYNI iskelet
 * kullanılarak (cp-core/src/Core/Command + her modülün Command/ dizini
 * taranarak, #[AsCommand] attribute'u okunarak) toplanır — Symfony'nin
 * "Application" sınıfını runtime'da enjekte etmek yerine bu yol seçildi
 * çünkü Application, web (non-console) kernel bağlamında container'a HİÇ
 * kayıtlı değildir (bkz. console.command.ids parametresinin web
 * bağlamında boş olması) ve AACP tamamen bir web isteğidir.
 *
 * Bu whitelist'in KENDİSİ de RunDueCronJobsCommand içinde tekrar
 * uygulanır (savunma derinliği): formu bypass edip DB'ye doğrudan
 * "cp_cron_jobs.command_name = 'dbal:run-sql'" yazan biri olsa bile,
 * dispatcher yine de bu whitelist'te olmayan bir komutu çalıştırmayı
 * REDDEDER.
 */
final class CronCommandWhitelist
{
    /**
     * @param list<string> $allowedCommandNames CronCommandRegistrationPass
     *   tarafından derleme zamanında toplanan "cp:" önekli komut adları
     *   (bkz. cpalius.cron_allowed_commands container parametresi).
     */
    public function __construct(
        private readonly array $allowedCommandNames,
    ) {
    }

    /**
     * @return list<string> "cp:" önekli, alfabetik sıralı komut adları.
     */
    public function allowedCommandNames(): array
    {
        return $this->allowedCommandNames;
    }

    public function isAllowed(string $commandName): bool
    {
        return \in_array($commandName, $this->allowedCommandNames, true);
    }
}

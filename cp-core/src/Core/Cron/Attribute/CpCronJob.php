<?php

declare(strict_types=1);

namespace App\Core\Cron\Attribute;

/**
 * Attribute Kulvarı (Symfony tarzı): bir servis metodunu, DB'ye (cp_cron_jobs)
 * hiç kayıt açmadan, koda gömülü bir "Sanal Cron Görevi" haline getiren
 * sözleşme. CpHook/CpApi ile AYNI felsefe: modül geliştiricisi Symfony'nin
 * DI/routing karmaşasına dokunmadan, tek bir attribute ekleyerek CronManager'ın
 * hibrit görev listesine (bkz. CronManager::getTasks()) dahil olur.
 *
 * Flat-File Kulvarı'nın (cp-content/modules/*\/Hooks/cron.{job_name}.php)
 * DI konteynerine ihtiyaç duyan alternatifi: EntityManager, Repository gibi
 * bağımlılıkları olan görevler için #[CpCronJob] kullanılır.
 *
 * TARGET_METHOD, IS_REPEATABLE DEĞİL: her metot TEK bir cron görevini temsil
 * eder (CpApi ile aynı gerekçe) — bir görevin iki farklı zamanlamaya sahip
 * olması iki ayrı metotla ifade edilmelidir.
 *
 * Kullanım (modül tarafında):
 *   final class PublishScheduledPostsTask
 *   {
 *       #[CpCronJob(schedule: "*\/5 * * * *", name: "blog.publish_scheduled", description: "...")]
 *       public function execute(): string { ... }
 *   }
 * Ekstra services.yaml tag'i GEREKMEZ — CronRegistrationPass derleme
 * zamanında bu attribute'u taşıyan tüm servis metotlarını toplar (bkz.
 * HookRegistrationPass/ApiRegistrationPass ile aynı tarama iskeleti).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CpCronJob
{
    /**
     * @param string $schedule Standart 5 alanlı cron ifadesi (ör. "*\/5 * * * *").
     *   CronExpressionEvaluator ile aynı sözdizimini paylaşır — DB'deki
     *   CronJob.cronExpression alanıyla birebir aynı formattır.
     * @param string $name Sistem genelinde benzersiz, insan-okunur olmayan
     *   bir kimlik (ör. "blog.publish_scheduled") — RunVirtualCronJobCommand
     *   bu adla ilgili sanal görevi bulur, AACP "Şimdi Çalıştır" ucu da
     *   aynı adı kullanır. DB'deki CronJob.name alanıyla ÇAKIŞABİLİR ama
     *   ayrı bir ad alanıdır (birbirine karıştırılmaz).
     * @param string $description AACP panelinde ve CLI çıktısında gösterilen
     *   kısa açıklama.
     */
    public function __construct(
        public readonly string $schedule,
        public readonly string $name,
        public readonly string $description = '',
    ) {
    }
}

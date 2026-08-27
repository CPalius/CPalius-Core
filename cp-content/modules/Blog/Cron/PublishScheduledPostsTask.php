<?php

declare(strict_types=1);

namespace Modules\Blog\Cron;

use App\Core\Cron\Attribute\CpCronJob;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CPalius'un Birleşik Otomasyon Motoru'nun İLK Attribute Kulvarı kod cron
 * görevi. Eskiden cp-core'da sabit kodlu, ayrı bir #[AsCommand] olan
 * "cp:posts:publish-scheduled" komutunun (bkz. git geçmişi) yerini alır:
 * artık DB'de bir cp_cron_jobs satırı GEREKTİRMEDEN, doğrudan koddan
 * keşfedilen bir sanal görevdir (bkz. CronRegistrationPass, CronManager).
 *
 * cp-core'a değil Blog modülüne taşınmasının nedeni SADECE organizasyoneldir
 * — Node/STATUS_SCHEDULED çekirdek bir kavram olmaya devam eder (Manifesto
 * Law 3.1), ancak "zamanlanmış İÇERİĞİ yayınlama" iş akışının somut cron
 * tetikleyicisi, ilk kullanım örneği burada olduğu için Blog modülünde
 * yaşar. Sayfalar veya başka Node type'ları için ayrı bir zamanlama görevi
 * gerekirse, aynı NodeRepository::findDueScheduledNodes() sorgusu başka bir
 * modülden de kendi #[CpCronJob] görevini tanımlayarak kullanılabilir.
 *
 * Node::publish() üzerinden TEK TEK işlenir (toplu bir DQL UPDATE değil):
 * NodeIndexListener SADECE Doctrine'in postPersist/postUpdate event'lerini
 * dinler — bir DQL bulk UPDATE bu event'leri hiç tetiklemez ve
 * NodeFieldIndex ile Node.data senkronsuz kalırdı. Entity-bazlı işleme,
 * event'lerin tetiklenmesini garanti eder.
 */
final class PublishScheduledPostsTask
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRepository $nodeRepository,
    ) {
    }

    #[CpCronJob(schedule: '*/5 * * * *', name: 'blog.publish_scheduled', description: 'Zamanlanmış blog yazılarını otomatik yayınlar')]
    public function execute(): string
    {
        $dueNodes = $this->nodeRepository->findDueScheduledNodes();

        if ($dueNodes === []) {
            return 'Yayın zamanı gelmiş, zamanlanmış içerik yok.';
        }

        $publishedTitles = [];
        foreach ($dueNodes as $node) {
            $node->publish($node->getPublishedAt());
            $publishedTitles[] = sprintf('#%d "%s" (%s)', $node->getId(), $node->getTitle(), $node->getType());
        }

        $this->entityManager->flush();

        return sprintf("%d içerik yayına alındı.\n%s", \count($dueNodes), implode("\n", $publishedTitles));
    }
}

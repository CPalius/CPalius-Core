<?php

declare(strict_types=1);

namespace Modules\Blog\Cron;

use App\Core\Cron\Attribute\CpCronJob;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #[CpCronJob] that publishes due scheduled posts via Node::publish() (not bulk DQL).
 * Lives in Blog for organization; other modules can reuse findDueScheduledNodes() the same way.
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

<?php

declare(strict_types=1);

namespace Modules\Blog\Cron;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\OriginCache\OriginCachePurger;
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
        private readonly OriginCachePurger $originCachePurger,
    ) {
    }

    #[CpCronJob(schedule: '*/5 * * * *', name: 'blog.publish_scheduled', description: 'Publish scheduled blog posts when their publish time is due')]
    public function execute(): string
    {
        $dueNodes = $this->nodeRepository->findDueScheduledNodes();

        if ($dueNodes === []) {
            return 'No scheduled content is due for publication.';
        }

        $publishedTitles = [];
        foreach ($dueNodes as $node) {
            $node->publish($node->getPublishedAt());
            $publishedTitles[] = sprintf('#%d "%s" (%s)', $node->getId(), $node->getTitle(), $node->getType());
        }

        $this->entityManager->flush();
        $areas = ['blog', 'home', 'roadmap'];
        foreach ($dueNodes as $node) {
            if ($node->getType() === 'page') {
                $areas[] = $node->getSlug();
            }
        }
        $this->originCachePurger->purgeAreas(...array_values(array_unique($areas)));

        return sprintf("%d item(s) published.\n%s", \count($publishedTitles), implode("\n", $publishedTitles));
    }
}

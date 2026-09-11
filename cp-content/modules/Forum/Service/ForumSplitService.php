<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumPostRepository;

final class ForumSplitService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumStatsService $statsService,
    ) {
    }

    /**
     * @param list<int> $postIds
     */
    public function split(ForumTopic $source, array $postIds, string $newTitle, User $moderator, ?ForumSection $target = null): ForumTopic
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $postIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === [] || trim($newTitle) === '') {
            throw new \InvalidArgumentException('Split requires a title and at least one post.');
        }

        $posts = $this->postRepository->findBy(['id' => $ids], ['createdAt' => 'ASC']);
        $movable = [];
        foreach ($posts as $post) {
            if ($post->getTopic()->getId() === $source->getId()) {
                $movable[] = $post;
            }
        }
        if ($movable === []) {
            throw new \InvalidArgumentException('No posts to split.');
        }

        $first = $movable[0];
        $section = $target ?? $source->getSection();
        $newTopic = new ForumTopic($section, trim($newTitle), $first->getPosterName());
        $newTopic->setLocale($source->getLocale());
        $slugger = new \Symfony\Component\String\Slugger\AsciiSlugger();
        $slug = mb_strtolower($slugger->slug(trim($newTitle))->toString());
        $newTopic->setSlug($slug !== '' ? mb_substr($slug, 0, 180) : 'konu');
        $newTopic->setFirstPoster($first->getAuthor());
        $newTopic->setDiscussionState(ForumDiscussionState::Visible);
        $this->entityManager->persist($newTopic);
        $this->entityManager->flush();

        foreach ($movable as $post) {
            $post->setTopic($newTopic);
            $post->setSection($section);
        }

        $newTopic->setFirstPostId($first->getId());
        $last = $movable[\count($movable) - 1];
        $newTopic->setLastPostId($last->getId());
        $newTopic->setLastPoster($last->getAuthor());
        $newTopic->setLastPosterName($last->getPosterName());
        $newTopic->setLastPostDate($last->getCreatedAt());
        $newTopic->setPostCount(\count($movable));
        $newTopic->touch();
        $source->touch();

        $this->entityManager->flush();
        $this->statsService->syncTopic($source);
        $this->statsService->syncTopic($newTopic);
        $this->statsService->syncSection($source->getSection());
        if ($section->getId() !== $source->getSection()->getId()) {
            $this->statsService->syncSection($section);
        }

        return $newTopic;
    }
}

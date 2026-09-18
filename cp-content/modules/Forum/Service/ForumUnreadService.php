<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumReadMarker;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumReadMarkerRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Repository\ForumTopicUserStateRepository;

final class ForumUnreadService
{
    public function __construct(
        private readonly ForumTopicUserStateRepository $stateRepository,
        private readonly ForumReadMarkerRepository $markerRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumWordFilterService $wordFilterService,
    ) {
    }

    public function markTopicRead(ForumTopic $topic, User $user): void
    {
        $this->stateRepository->findOrCreate($topic, $user)->touch();
        $this->entityManager->flush();
    }

    public function markSectionRead(ForumSection $section, User $user): void
    {
        $marker = $this->markerRepository->findForSection($user, $section);
        if ($marker === null) {
            $marker = new ForumReadMarker($user, $section);
            $this->entityManager->persist($marker);
        } else {
            $marker->touch();
        }

        $this->entityManager->flush();
    }

    public function markAllRead(User $user): void
    {
        $marker = $this->markerRepository->findGlobal($user);
        if ($marker === null) {
            $marker = new ForumReadMarker($user, null);
            $this->entityManager->persist($marker);
        } else {
            $marker->touch();
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<ForumTopic> $topics
     *
     * @return array<int, true>
     */
    public function unreadTopicIdMap(User $user, array $topics): array
    {
        $ids = [];
        foreach ($topics as $topic) {
            $id = $topic->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $views = $this->stateRepository->lastSeenByTopicIds($user, $ids);
        $global = $this->markerRepository->findGlobal($user)?->getMarkedAt();
        $bySection = $this->markerRepository->markedAtBySection($user);
        $unread = [];

        foreach ($topics as $topic) {
            $id = $topic->getId();
            if ($id === null) {
                continue;
            }

            $activity = $topic->getLastPostDate() ?? $topic->getUpdatedAt();
            $floor = $views[$id] ?? null;
            $sectionId = $topic->getSection()->getId();
            $sectionMark = $sectionId !== null ? ($bySection[$sectionId] ?? null) : null;

            foreach ([$global, $sectionMark] as $marker) {
                if ($marker !== null && ($floor === null || $marker > $floor)) {
                    $floor = $marker;
                }
            }

            if ($floor === null || $activity > $floor) {
                $unread[$id] = true;
            }
        }

        return $unread;
    }

    /** @return list<ForumTopic> */
    public function unreadTopics(User $user, int $limit = 40): array
    {
        $topics = $this->topicRepository->findLatest($limit * 3, 0, null, $this->wordFilterService->termsFor($user));
        $unread = $this->unreadTopicIdMap($user, $topics);
        $out = [];
        foreach ($topics as $topic) {
            $id = $topic->getId();
            if ($id !== null && isset($unread[$id])) {
                $out[] = $topic;
            }
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}

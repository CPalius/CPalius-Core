<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicsPosted;
use Modules\Forum\Repository\ForumTopicsPostedRepository;

final class ForumParticipationService
{
    public function __construct(
        private readonly ForumTopicsPostedRepository $topicsPostedRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function markPosted(User $user, ForumTopic $topic): void
    {
        if ($this->topicsPostedRepository->findOne($user, $topic) !== null) {
            return;
        }

        $this->entityManager->persist(new ForumTopicsPosted($user, $topic));
        $this->entityManager->flush();
    }
}

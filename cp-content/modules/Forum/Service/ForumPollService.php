<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumNodePermission;
use Modules\Forum\Entity\ForumPoll;
use Modules\Forum\Entity\ForumPollOption;
use Modules\Forum\Entity\ForumPollVote;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumPollRepository;
use Modules\Forum\Repository\ForumPollVoteRepository;

final class ForumPollService
{
    public function __construct(
        private readonly ForumPollRepository $pollRepository,
        private readonly ForumPollVoteRepository $voteRepository,
        private readonly ForumPermissionService $permissionService,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.polls_enabled', true);
    }

    public function canCreate(ForumTopic $topic, ?User $user): bool
    {
        return $this->isEnabled()
            && $user !== null
            && $this->permissionService->isAllowed($topic->getSection(), $user, ForumNodePermission::PERM_POLL);
    }

    /**
     * @param list<string> $optionLabels
     */
    public function create(ForumTopic $topic, User $user, string $question, array $optionLabels, int $maxChoices = 1, bool $hideUntilClose = false, ?\DateTimeImmutable $closesAt = null): ?ForumPoll
    {
        if (!$this->canCreate($topic, $user) || $this->pollRepository->findOneByTopic($topic) !== null) {
            return null;
        }

        $labels = [];
        foreach ($optionLabels as $label) {
            $trimmed = trim($label);
            if ($trimmed !== '') {
                $labels[] = mb_substr($trimmed, 0, 255);
            }
        }
        if ($question === '' || \count($labels) < 2) {
            return null;
        }

        $poll = new ForumPoll($topic, mb_substr($question, 0, 255));
        $poll->setMaxChoices($maxChoices);
        $poll->setHideUntilClose($hideUntilClose);
        $poll->setClosesAt($closesAt);
        $this->entityManager->persist($poll);

        foreach ($labels as $i => $label) {
            $this->entityManager->persist(new ForumPollOption($poll, $label, $i));
        }

        $this->entityManager->flush();

        return $poll;
    }

    /**
     * @param list<int> $optionIds
     */
    public function vote(ForumPoll $poll, User $user, array $optionIds): bool
    {
        if ($poll->isClosed()) {
            throw new \DomainException('forum.error.poll_closed');
        }

        $existing = $this->voteRepository->findByPollAndUser($poll, $user);
        if ($existing !== [] && !$poll->allowsChange()) {
            throw new \DomainException('forum.error.poll_cannot_change_vote');
        }

        $wanted = array_values(array_unique(array_filter(
            array_map('intval', $optionIds),
            static fn (int $id): bool => $id > 0,
        )));
        $wanted = \array_slice($wanted, 0, $poll->getMaxChoices());
        if ($wanted === []) {
            return false;
        }

        $valid = [];
        foreach ($poll->getOptions() as $option) {
            $id = $option->getId();
            if ($id !== null && \in_array($id, $wanted, true)) {
                $valid[] = $option;
            }
        }
        if ($valid === []) {
            return false;
        }

        foreach ($existing as $vote) {
            $vote->getOption()->decrementVoteCount();
            $this->entityManager->remove($vote);
        }

        foreach ($valid as $option) {
            $this->entityManager->persist(new ForumPollVote($poll, $option, $user));
            $option->incrementVoteCount();
        }
        $this->entityManager->flush();

        return true;
    }

    public function findForTopic(ForumTopic $topic): ?ForumPoll
    {
        return $this->pollRepository->findOneByTopic($topic);
    }

    /** @return list<int> */
    public function votedOptionIds(ForumPoll $poll, User $user): array
    {
        return $this->voteRepository->optionIdsVotedByUser($poll, $user);
    }

    /**
     * @return array<int, list<User>>
     */
    public function publicVoters(ForumPoll $poll): array
    {
        if (!$poll->isPublic()) {
            return [];
        }

        return $this->voteRepository->votersGroupedByOption($poll);
    }
}

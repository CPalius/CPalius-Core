<?php

declare(strict_types=1);

namespace Modules\Forum\Migrate;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;

/**
 * Writes imported threads into forum topics.
 *
 * THE DATE IS THE POINT
 * A forum archive is an ordered conversation; its value is when things were
 * said and in what order. An import that stamps every topic with the day of
 * the migration keeps the words and throws away the archive, so the original
 * start time is put back through ForumTopic::restoreCreatedAt().
 *
 * A thread whose author is not among the imported users keeps the name the
 * source recorded and no account. That is the honest result: forums are full
 * of posts by people whose accounts were deleted years ago, and inventing an
 * account for them would create logins nobody asked for.
 */
final class ForumTopicDestination implements MigrationDestinationInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function describe(): string
    {
        return 'Forum topics';
    }

    public function entityType(): string
    {
        return 'forum_topic';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $title = trim($row->getString('title'));

        if ($title === '') {
            throw new \RuntimeException('A topic needs a "title".');
        }

        $section = $this->section($row);
        $posterName = trim($row->getString('posterName'));

        if ($posterName === '') {
            $posterName = 'Guest';
        }

        $topic = $existingId === null ? null : $this->entityManager->find(ForumTopic::class, (int) $existingId);

        if ($topic === null) {
            $topic = new ForumTopic($section, $title, $posterName);
            $this->entityManager->persist($topic);
        } else {
            $topic->setSection($section);
            $topic->setTitle($title);
        }

        $topic->setLocale($section->getLocale());
        $topic->setSticky(trim($row->getString('sticky')) === '1');
        $topic->setLocked(trim($row->getString('locked')) === '1');
        $topic->setState(trim($row->getString('locked')) === '1' ? ForumTopic::STATE_LOCKED : ForumTopic::STATE_OPEN);
        $topic->setDiscussionState($this->discussionState($row));

        foreach ([['viewCount', 'setViewCount'], ['postCount', 'setPostCount']] as [$key, $setter]) {
            $value = trim($row->getString($key));

            if (is_numeric($value)) {
                $topic->{$setter}((int) $value);
            }
        }

        $author = $this->user($row, 'authorUserId');
        $topic->setFirstPoster($author);
        $topic->setLastPoster($this->user($row, 'lastPosterUserId') ?? $author);
        $topic->setLastPosterName(trim($row->getString('lastPosterName')) ?: $posterName);

        $createdAt = $this->timestamp($row, 'createdAt');

        if ($createdAt !== null) {
            $topic->restoreCreatedAt($createdAt);
        }

        $topic->setLastPostDate($this->timestamp($row, 'lastPostAt') ?? $createdAt);

        $this->entityManager->flush();

        $id = $topic->getId();

        if ($id === null) {
            throw new \RuntimeException('The topic was flushed but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $topic = $this->entityManager->find(ForumTopic::class, (int) $destinationId);

        if ($topic === null) {
            return false;
        }

        $this->entityManager->remove($topic);
        $this->entityManager->flush();

        return true;
    }

    private function section(MigrationRow $row): ForumSection
    {
        $sectionId = trim($row->getString('sectionId'));

        if ($sectionId === '' || !is_numeric($sectionId)) {
            throw new \RuntimeException('A topic needs a "sectionId"; a topic with no forum to sit in cannot be shown anywhere.');
        }

        $section = $this->entityManager->find(ForumSection::class, (int) $sectionId);

        if ($section === null) {
            throw new \RuntimeException(sprintf('Forum section %s is not there any more, so its topic cannot be attached.', $sectionId));
        }

        return $section;
    }

    private function user(MigrationRow $row, string $key): ?User
    {
        $id = trim($row->getString($key));

        if ($id !== '' && is_numeric($id)) {
            $user = $this->entityManager->find(User::class, (int) $id);
            if ($user instanceof User) {
                return $user;
            }
        }

        $nameKey = $key === 'lastPosterUserId' ? 'lastPosterName' : 'posterName';
        $name = trim($row->getString($nameKey));

        return $name === '' ? null : $this->entityManager->getRepository(User::class)->findOneBy(['username' => $name]);
    }

    /**
     * Anything not plainly visible at the source stays out of sight here.
     * A thread the old board had hidden reappearing on the new one is a
     * disclosure, and the moderation decision was already made.
     */
    private function discussionState(MigrationRow $row): ForumDiscussionState
    {
        return match (strtolower(trim($row->getString('state')))) {
            'visible' => ForumDiscussionState::Visible,
            'deleted' => ForumDiscussionState::Deleted,
            default => ForumDiscussionState::Moderated,
        };
    }

    private function timestamp(MigrationRow $row, string $key): ?\DateTimeImmutable
    {
        $raw = trim($row->getString($key));

        if ($raw === '') {
            return null;
        }

        // Forum packages store Unix timestamps; anything else is given to
        // DateTimeImmutable, which understands the usual SQL shapes.
        try {
            return ctype_digit($raw)
                ? (new \DateTimeImmutable('@'.$raw))->setTimezone(new \DateTimeZone('UTC'))
                : new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}

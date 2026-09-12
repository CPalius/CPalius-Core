<?php

declare(strict_types=1);

namespace Modules\Forum\Migrate;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;

/**
 * Writes imported replies into forum posts.
 *
 * The section is taken from the topic rather than from the row: a post that
 * disagreed with its own topic about which forum it is in would show up in one
 * listing and not the other, and no import should be able to produce that.
 *
 * Poster IP addresses are not carried over — personal data describing where
 * somebody was years ago on a board that no longer exists, which nothing here
 * acts on. Same decision as blog comments, for the same reason.
 */
final class ForumPostDestination implements MigrationDestinationInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function describe(): string
    {
        return 'Forum posts';
    }

    public function entityType(): string
    {
        return 'forum_post';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $topic = $this->topic($row);
        $body = $row->getString('body');

        if (trim($body) === '') {
            throw new \RuntimeException('A post needs a body.');
        }

        $posterName = trim($row->getString('posterName'));

        if ($posterName === '') {
            $posterName = 'Guest';
        }

        $post = $existingId === null ? null : $this->entityManager->find(ForumPost::class, (int) $existingId);

        if ($post === null) {
            $post = new ForumPost($topic, $topic->getSection(), $posterName, $body);
            $this->entityManager->persist($post);
        } else {
            $post->setTopic($topic);
            $post->setSection($topic->getSection());
            $post->setPosterName($posterName);
            $post->setBody($body);
        }

        $post->setAuthor($this->author($row));
        $post->setDiscussionState($this->discussionState($row));

        $createdAt = $this->timestamp($row, 'createdAt');

        if ($createdAt !== null) {
            $post->restoreCreatedAt($createdAt);
        }

        $this->entityManager->flush();

        $id = $post->getId();

        if ($id === null) {
            throw new \RuntimeException('The post was flushed but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $post = $this->entityManager->find(ForumPost::class, (int) $destinationId);

        if ($post === null) {
            return false;
        }

        $this->entityManager->remove($post);
        $this->entityManager->flush();

        return true;
    }

    private function topic(MigrationRow $row): ForumTopic
    {
        $topicId = trim($row->getString('topicId'));

        if ($topicId === '' || !is_numeric($topicId)) {
            throw new \RuntimeException('A post needs a "topicId"; a reply with no topic cannot be shown anywhere.');
        }

        $topic = $this->entityManager->find(ForumTopic::class, (int) $topicId);

        if ($topic === null) {
            throw new \RuntimeException(sprintf('Topic %s is not there any more, so its post cannot be attached.', $topicId));
        }

        return $topic;
    }

    private function author(MigrationRow $row): ?User
    {
        $id = trim($row->getString('authorUserId'));

        return $id === '' || !is_numeric($id) ? null : $this->entityManager->find(User::class, (int) $id);
    }

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

        try {
            return ctype_digit($raw)
                ? (new \DateTimeImmutable('@'.$raw))->setTimezone(new \DateTimeZone('UTC'))
                : new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}

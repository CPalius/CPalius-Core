<?php

declare(strict_types=1);

namespace Modules\Blog\Migrate;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use App\Entity\Node;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\Entity\BlogComment;

/**
 * Writes imported comments onto blog posts.
 *
 * It lives in the Blog module rather than in the importer because Blog owns
 * BlogComment and what it means to write one. The importer knows what a
 * WordPress comment looks like; it has no business knowing this entity's
 * invariants, and a second importer for some other forum should reuse this
 * rather than reinvent it.
 *
 * Poster IP addresses are deliberately NOT carried over. They are personal
 * data, they describe where somebody was years ago on a site that no longer
 * exists, and nothing here acts on them — the moderation decisions they
 * informed were already made and arrive as the comment's status. Importing
 * them would mean taking on a retention obligation for data with no use.
 */
final class BlogCommentDestination implements MigrationDestinationInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function describe(): string
    {
        return 'Blog comments';
    }

    public function entityType(): string
    {
        return 'blog_comment';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $nodeId = trim($row->getString('nodeId'));

        if ($nodeId === '' || !is_numeric($nodeId)) {
            throw new \RuntimeException('A comment row needs a "nodeId"; a comment with no post to sit on cannot be shown anywhere.');
        }

        $node = $this->entityManager->find(Node::class, (int) $nodeId);

        if ($node === null) {
            throw new \RuntimeException(sprintf('Post %s is not there any more, so its comment cannot be attached.', $nodeId));
        }

        $body = trim($row->getString('body'));

        if ($body === '') {
            throw new \RuntimeException('A comment row needs a non-empty "body".');
        }

        $comment = $existingId === null ? null : $this->entityManager->find(BlogComment::class, (int) $existingId);

        if ($comment === null) {
            $comment = new BlogComment($node, $body);
            $this->entityManager->persist($comment);
        } else {
            $comment->setBody($body);
        }

        $comment->setStatus($this->status($row));
        $comment->setParent($this->parent($row, $comment));
        $this->applyAuthor($comment, $row);

        $this->entityManager->flush();

        $id = $comment->getId();

        if ($id === null) {
            throw new \RuntimeException('The comment was flushed but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $comment = $this->entityManager->find(BlogComment::class, (int) $destinationId);

        if ($comment === null) {
            return false;
        }

        $this->entityManager->remove($comment);
        $this->entityManager->flush();

        return true;
    }

    /**
     * An unknown status becomes "pending" rather than "approved": the cost of
     * a real comment waiting for a moderator is a delay, the cost of publishing
     * something the old site had hidden is publishing something the old site
     * had hidden.
     */
    private function status(MigrationRow $row): string
    {
        return match (trim($row->getString('status'))) {
            BlogComment::STATUS_APPROVED => BlogComment::STATUS_APPROVED,
            BlogComment::STATUS_SPAM => BlogComment::STATUS_SPAM,
            BlogComment::STATUS_REJECTED => BlogComment::STATUS_REJECTED,
            default => BlogComment::STATUS_PENDING,
        };
    }

    /**
     * Threading, given as a CPalius comment id the migration resolved through
     * the map. A comment is never its own parent, and a parent that is not
     * imported yet leaves it at the top level — source exports are not ordered
     * parents-first, and a second run puts it right.
     */
    private function parent(MigrationRow $row, BlogComment $comment): ?BlogComment
    {
        $parentId = trim($row->getString('parentId'));

        if ($parentId === '' || !is_numeric($parentId) || (int) $parentId === $comment->getId()) {
            return null;
        }

        return $this->entityManager->find(BlogComment::class, (int) $parentId);
    }

    /**
     * A comment is attached to an account when its address belongs to one, and
     * left as a guest comment otherwise.
     *
     * Matching on email is matching on what the source system itself used to
     * identify the commenter, so it is exactly as trustworthy as the rest of
     * the export — and no more. Nothing here grants the account anything; the
     * comment merely shows as authored rather than anonymous.
     */
    private function applyAuthor(BlogComment $comment, MigrationRow $row): void
    {
        $email = trim($row->getString('authorEmail'));
        $name = trim($row->getString('authorName'));

        $user = $email === ''
            ? null
            : $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user instanceof User) {
            $comment->setAuthor($user);
            $comment->setGuestName(null);
            $comment->setGuestEmail(null);

            return;
        }

        $comment->setAuthor(null);
        $comment->setGuestName($name !== '' ? $name : null);
        $comment->setGuestEmail($email !== '' ? $email : null);
    }
}

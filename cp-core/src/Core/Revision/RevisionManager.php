<?php

declare(strict_types=1);

namespace App\Core\Revision;

use App\Core\Revision\Entity\NodeRevision;
use App\Core\Revision\Repository\NodeRevisionRepository;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Node;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns node revision capture, restore and pruning. Auto-capture is driven by
 * NodeRevisionListener; controllers stage a log message + author before flushing.
 */
class RevisionManager
{
    public const SETTING_ENABLED = 'content.revisions.enabled';
    public const SETTING_MAX = 'content.revisions.max_per_node';
    public const DEFAULT_MAX = 25;

    private ?string $pendingLog = null;
    private ?User $pendingAuthor = null;
    private bool $suspended = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRevisionRepository $repository,
        private readonly NodeSnapshot $snapshot,
        private readonly SettingsRegistry $settings,
    ) {
    }

    /** Controllers call this before persisting an editorial change. */
    public function stageContext(?string $logMessage, ?User $author): void
    {
        $this->pendingLog = $logMessage;
        $this->pendingAuthor = $author;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING_ENABLED, true);
    }

    public function maxPerNode(): int
    {
        return max(1, (int) $this->settings->get(self::SETTING_MAX, self::DEFAULT_MAX));
    }

    /**
     * Capture the node's current state. Returns null when disabled, mid-restore,
     * the node is unsaved, or the state is identical to the last revision.
     * The caller is responsible for flushing.
     */
    public function capture(Node $node): ?NodeRevision
    {
        if ($this->suspended || !$this->isEnabled() || $node->getId() === null) {
            return null;
        }

        $data = $this->snapshot->capture($node);
        $hash = $this->snapshot->hash($data);

        $latest = $this->repository->latestForNode($node);
        if ($latest !== null && $latest->getSnapshotHash() === $hash) {
            return null;
        }

        $revision = new NodeRevision($node, $data['title'], $data, $hash, $this->pendingAuthor, $this->pendingLog);
        $this->entityManager->persist($revision);

        $this->pendingLog = null;
        $this->pendingAuthor = null;

        return $revision;
    }

    /**
     * Apply a stored revision back onto its node. A fresh "restore" revision is
     * recorded by the listener on the next flush (which the caller performs).
     */
    public function restore(NodeRevision $revision, ?User $by = null): void
    {
        $node = $revision->getNode();

        $this->suspended = true;
        try {
            $this->snapshot->apply($revision->getSnapshot(), $node);
        } finally {
            $this->suspended = false;
        }

        $this->stageContext(sprintf('Restored revision #%d', (int) $revision->getId()), $by);
    }

    /**
     * @return list<NodeRevision>
     */
    public function list(Node $node): array
    {
        return $this->repository->findByNode($node);
    }

    public function prune(Node $node): int
    {
        return $this->repository->pruneNode($node, $this->maxPerNode());
    }
}

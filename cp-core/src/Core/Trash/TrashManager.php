<?php

declare(strict_types=1);

namespace App\Core\Trash;

use App\Core\Trash\Entity\TrashItem;
use App\Core\Trash\Entity\TrashRule;
use App\Core\Trash\Repository\TrashItemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Restore, permanent delete and expiry for the recycle bin.
 *
 * Restore writes the stored row back with plain SQL INSERT, so it behaves the
 * same whether or not the entity class still exists. A row whose primary key
 * has been taken since, or whose parent is gone, fails with the database's own
 * message instead of being silently merged into something else.
 */
final class TrashManager
{
    /**
     * @param iterable<TrashPurgeHandlerInterface> $purgeHandlers
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly TrashItemRepository $items,
        private readonly TrashPolicy $policy,
        #[AutowireIterator('cp.trash_purge_handler')]
        private readonly iterable $purgeHandlers = [],
    ) {
    }

    /**
     * @throws \RuntimeException when the row cannot be inserted back
     */
    public function restore(TrashItem $item): void
    {
        $this->insertRow($item);
        $this->forget($item);
        $this->entityManager->flush();
    }

    /**
     * Restores everything deleted by the same flush as $item. Rows that depend
     * on one another go back in whatever order the database accepts: failures
     * are retried until a pass makes no progress, so a child captured before
     * its parent does not strand the pair.
     *
     * @return array{restored: int, failed: list<array{item: TrashItem, error: string}>}
     */
    public function restoreBatch(string $batchId): array
    {
        $pending = $this->items->findBatch($batchId);
        $restored = 0;
        $errors = [];

        while ($pending !== []) {
            $stillPending = [];
            $errors = [];

            foreach ($pending as $item) {
                try {
                    $this->insertRow($item);
                    $this->forget($item);
                    ++$restored;
                } catch (\RuntimeException $e) {
                    $stillPending[] = $item;
                    $errors[$item->getId()] = $e->getMessage();
                }
            }

            if (\count($stillPending) === \count($pending)) {
                break;
            }

            $pending = $stillPending;
        }

        $this->entityManager->flush();

        $failed = [];
        foreach ($pending as $item) {
            if (isset($errors[$item->getId()])) {
                $failed[] = ['item' => $item, 'error' => $errors[$item->getId()]];
            }
        }

        return ['restored' => $restored, 'failed' => $failed];
    }

    public function delete(TrashItem $item): void
    {
        $this->forget($item);
        $this->entityManager->flush();
        $this->cleanUp([$item]);
    }

    /**
     * Permanently removes every item (or every item of one type).
     */
    public function emptyBin(?string $type = null): int
    {
        $doomed = $this->collectForCleanUp($this->items->createFilteredQueryBuilder(['type' => $type]));
        $removed = $this->items->purgeAll($type);
        $this->cleanUp($doomed);

        return $removed;
    }

    public function purgeExpired(): int
    {
        $now = new \DateTimeImmutable();

        $doomed = $this->collectForCleanUp(
            $this->items->createQueryBuilder('t')
                ->andWhere('t.expiresAt IS NOT NULL')
                ->andWhere('t.expiresAt < :now')
                ->setParameter('now', $now),
        );
        $removed = $this->items->purgeExpired($now);
        $this->cleanUp($doomed);

        return $removed;
    }

    /**
     * The items a bulk DELETE is about to remove that some handler cares about.
     * Only those are read into memory, so the common case — no handler, or a bin
     * of plain rows — stays a single DELETE statement.
     *
     * @return list<TrashItem>
     */
    private function collectForCleanUp(QueryBuilder $matching): array
    {
        $doomed = [];

        foreach ($matching->getQuery()->toIterable() as $item) {
            if ($this->handlerFor($item) !== null) {
                $doomed[] = $item;
            }
            $this->entityManager->detach($item);
        }

        return $doomed;
    }

    private function handlerFor(TrashItem $item): ?TrashPurgeHandlerInterface
    {
        foreach ($this->purgeHandlers as $handler) {
            if ($handler->supports($item)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Handlers run AFTER the rows are gone, so "is anything else still using
     * this file?" sees only the survivors. Run before, two bin items sharing
     * one file would each find the other and neither would remove it.
     *
     * @param list<TrashItem> $items
     */
    private function cleanUp(array $items): void
    {
        foreach ($items as $item) {
            $handler = $this->handlerFor($item);
            if ($handler === null) {
                continue;
            }

            try {
                $handler->onPurge($item);
            } catch (\Throwable) {
                // An orphaned file is cheaper than an item that can never leave the bin.
            }
        }
    }

    /**
     * Saves the global switch/retention and one rule per resource type.
     * A type row equal to "inherit" (blank days, enabled) is dropped.
     *
     * @param array<string, array{enabled: bool, days: ?int}> $perType
     */
    public function saveRules(bool $globalEnabled, int $globalDays, array $perType): void
    {
        $repo = $this->entityManager->getRepository(TrashRule::class);

        $wanted = [TrashRule::GLOBAL => [$globalEnabled, $globalDays]];
        foreach ($perType as $name => $rule) {
            if ($rule['enabled'] && $rule['days'] === null) {
                continue;
            }
            $wanted[$name] = [$rule['enabled'], $rule['days'] ?? $globalDays];
        }

        foreach ($repo->findAll() as $existing) {
            if (!isset($wanted[$existing->getResourceName()])) {
                $this->entityManager->remove($existing);
            }
        }

        foreach ($wanted as $name => [$enabled, $days]) {
            $rule = $repo->find($name) ?? new TrashRule($name);
            $rule->setEnabled($enabled)->setRetentionDays($days);
            $this->entityManager->persist($rule);
        }

        $this->entityManager->flush();
        $this->policy->reset();
    }

    /**
     * Every resource type whose deletes can be captured, for the settings screen.
     *
     * @return list<string>
     */
    public function knownTypes(): array
    {
        $names = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass || !$this->policy->isTrashableClass($metadata->getName())) {
                continue;
            }
            $names[$this->policy->resourceName($metadata->getName())] = true;
        }

        $names = array_keys($names);
        sort($names, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $names;
    }

    private function forget(TrashItem $item): void
    {
        $this->entityManager->remove($item);
    }

    private function insertRow(TrashItem $item): void
    {
        $row = TrashRowCodec::decode($item->getRowData());
        if ($row === []) {
            throw new \RuntimeException('Stored row is empty.');
        }

        $columns = array_map($this->connection->quoteIdentifier(...), array_keys($row));
        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->connection->quoteIdentifier($item->getTableName()),
            implode(', ', $columns),
            implode(', ', array_fill(0, \count($row), '?')),
        );

        try {
            $this->connection->executeStatement($sql, array_values($row));
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Migrate\Map;

use App\Core\Migrate\Entity\MigrationMapEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The persistent map, backed by cp_migration_map.
 *
 * Each record() flushes on its own. That is deliberate and it is the difference
 * between an import that survives being killed and one that does not: batching
 * the map into one flush at the end means a run interrupted at row 40 000 has
 * written 40 000 records and remembers none of them, so the retry imports them
 * all again. The map is small and written once per row — the flush is not where
 * an import spends its time.
 */
final class DoctrineMigrationMap implements MigrationMapInterface
{
    /**
     * One SELECT per migration id, then every find() in this request is a
     * hash lookup. A WordPress post resolves its author, categories, tags and
     * every attachment from the map; asking findOneBy for each of those is
     * the N+1 Law 6.1 exists to catch (cp_migration_map eleven times inside
     * one row).
     *
     * @var array<string, array<string, MigrationMapEntry>>
     */
    private array $bySourceId = [];

    /** @var array<string, true> */
    private array $hydrated = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(string $migrationId, string $sourceId): ?MigrationMapRecord
    {
        $entry = $this->entry($migrationId, $sourceId);

        return $entry === null ? null : $this->toRecord($entry);
    }

    public function record(MigrationMapRecord $record): void
    {
        $entry = $this->entry($record->migrationId, $record->sourceId);

        if ($entry === null) {
            $entry = new MigrationMapEntry(
                $record->migrationId,
                $record->sourceId,
                $record->checksum,
                $record->destinationType,
                $record->destinationId,
            );
            $this->entityManager->persist($entry);
            $this->bySourceId[$record->migrationId][$record->sourceId] = $entry;
        } else {
            $entry->refresh($record->checksum, $record->destinationType, $record->destinationId);
        }

        $this->entityManager->flush();
    }

    public function forget(string $migrationId, string $sourceId): void
    {
        $entry = $this->entry($migrationId, $sourceId);

        if ($entry === null) {
            return;
        }

        $this->entityManager->remove($entry);
        unset($this->bySourceId[$migrationId][$sourceId]);
        $this->entityManager->flush();
    }

    public function entries(string $migrationId): array
    {
        $this->hydrate($migrationId);

        $entries = array_values($this->bySourceId[$migrationId]);
        usort(
            $entries,
            static fn (MigrationMapEntry $a, MigrationMapEntry $b): int => ($b->getId() ?? 0) <=> ($a->getId() ?? 0),
        );

        return array_map($this->toRecord(...), $entries);
    }

    public function countFor(string $migrationId): int
    {
        // COUNT in the database rather than findBy()->count(): the old form
        // hydrated every mapped row into an entity just to measure how many
        // there were, so opening a screen after a fifty-thousand-row import
        // loaded fifty thousand objects to print one number.
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(MigrationMapEntry::class, 'm')
            ->where('m.migrationId = :id')
            ->setParameter('id', $migrationId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countsFor(array $migrationIds): array
    {
        $counts = array_fill_keys($migrationIds, 0);

        if ($migrationIds === []) {
            return $counts;
        }

        /** @var list<array{migrationId: string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.migrationId AS migrationId, COUNT(m.id) AS total')
            ->from(MigrationMapEntry::class, 'm')
            ->where('m.migrationId IN (:ids)')
            ->setParameter('ids', $migrationIds)
            ->groupBy('m.migrationId')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $counts[$row['migrationId']] = (int) $row['total'];
        }

        return $counts;
    }

    private function entry(string $migrationId, string $sourceId): ?MigrationMapEntry
    {
        $this->hydrate($migrationId);

        return $this->bySourceId[$migrationId][$sourceId] ?? null;
    }

    private function hydrate(string $migrationId): void
    {
        if (isset($this->hydrated[$migrationId])) {
            return;
        }

        /** @var list<MigrationMapEntry> $entries */
        $entries = $this->entityManager->getRepository(MigrationMapEntry::class)
            ->findBy(['migrationId' => $migrationId]);

        $this->bySourceId[$migrationId] = [];

        foreach ($entries as $entry) {
            $this->bySourceId[$migrationId][$entry->getSourceId()] = $entry;
        }

        $this->hydrated[$migrationId] = true;
    }

    private function toRecord(MigrationMapEntry $entry): MigrationMapRecord
    {
        return new MigrationMapRecord(
            $entry->getMigrationId(),
            $entry->getSourceId(),
            $entry->getChecksum(),
            $entry->getDestinationType(),
            $entry->getDestinationId(),
        );
    }
}

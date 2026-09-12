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
        $this->entityManager->flush();
    }

    public function entries(string $migrationId): array
    {
        /** @var list<MigrationMapEntry> $entries */
        $entries = $this->entityManager->getRepository(MigrationMapEntry::class)
            ->findBy(['migrationId' => $migrationId], ['id' => 'DESC']);

        return array_map($this->toRecord(...), $entries);
    }

    public function countFor(string $migrationId): int
    {
        return \count($this->entityManager->getRepository(MigrationMapEntry::class)
            ->findBy(['migrationId' => $migrationId]));
    }

    private function entry(string $migrationId, string $sourceId): ?MigrationMapEntry
    {
        /** @var MigrationMapEntry|null $entry */
        $entry = $this->entityManager->getRepository(MigrationMapEntry::class)
            ->findOneBy(['migrationId' => $migrationId, 'sourceId' => $sourceId]);

        return $entry;
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

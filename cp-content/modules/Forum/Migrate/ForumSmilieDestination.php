<?php

declare(strict_types=1);

namespace Modules\Forum\Migrate;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSmilie;

/**
 * Writes imported smilies into the forum catalog.
 *
 * Matching is by the primary trigger (":)") so a second run updates the
 * picture XenForo pointed at rather than creating a duplicate smile.
 */
final class ForumSmilieDestination implements MigrationDestinationInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function describe(): string
    {
        return 'Forum smilies';
    }

    public function entityType(): string
    {
        return 'forum_smilie';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $code = trim($row->getString('code'));

        if ($code === '') {
            throw new \RuntimeException('A smilie needs a trigger code.');
        }

        $smilie = $existingId === null ? null : $this->entityManager->find(ForumSmilie::class, (int) $existingId);
        $smilie ??= $this->entityManager->getRepository(ForumSmilie::class)->findOneBy(['code' => $code]);

        if ($smilie === null) {
            $smilie = new ForumSmilie($code, trim($row->getString('title')) ?: $code);
            $this->entityManager->persist($smilie);
        } else {
            $smilie->setCode($code);
            $title = trim($row->getString('title'));
            if ($title !== '') {
                $smilie->setTitle($title);
            }
        }

        $extra = $row->getString('extraCodes');
        $smilie->setExtraCodes($extra === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $extra))))));

        $smilie->setImageUrl(trim($row->getString('imageUrl')) ?: null);
        $smilie->setEmoji(trim($row->getString('emoji')) ?: null);
        $smilie->setCategory(trim($row->getString('category')) ?: 'default');
        $sort = trim($row->getString('sortOrder'));
        if (is_numeric($sort)) {
            $smilie->setSortOrder((int) $sort);
        }
        $smilie->setDisplayInEditor(trim($row->getString('displayInEditor', '1')) !== '0');
        $smilie->setImportedFrom(trim($row->getString('importedFrom')) ?: null);

        $this->entityManager->flush();

        $id = $smilie->getId();

        if ($id === null) {
            throw new \RuntimeException('The smilie was flushed but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $smilie = $this->entityManager->find(ForumSmilie::class, (int) $destinationId);

        if ($smilie === null) {
            return false;
        }

        $this->entityManager->remove($smilie);
        $this->entityManager->flush();

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Revision;

use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads/writes the editorial slice of a Node that a revision records. Structural
 * metadata (type, locale, translation group) is intentionally out of scope — a
 * revision restores content, not identity.
 */
final class NodeSnapshot
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{title: string, slug: string, status: string, data: array<string, mixed>, published_at: ?string, category_id: ?int, tag_ids: list<int>}
     */
    public function capture(Node $node): array
    {
        $tagIds = [];
        foreach ($node->getTags() as $tag) {
            if ($tag->getId() !== null) {
                $tagIds[] = $tag->getId();
            }
        }
        sort($tagIds);

        return [
            'title' => $node->getTitle(),
            'slug' => $node->getSlug(),
            'status' => $node->getStatus(),
            'moderation_state' => $node->getModerationState(),
            'data' => $node->getData(),
            'published_at' => $node->getPublishedAt()?->format(\DATE_ATOM),
            'category_id' => $node->getCategory()?->getId(),
            'tag_ids' => $tagIds,
        ];
    }

    /**
     * Canonical JSON for hashing — key order fixed so equal content hashes equal.
     *
     * @param array<string, mixed> $snapshot
     */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->normalize($snapshot), \JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * Applies a stored snapshot back onto the node (used by RevisionManager::restore).
     *
     * @param array<string, mixed> $snapshot
     */
    public function apply(array $snapshot, Node $node): void
    {
        if (isset($snapshot['title']) && \is_string($snapshot['title'])) {
            $node->setTitle($snapshot['title']);
        }
        if (isset($snapshot['slug']) && \is_string($snapshot['slug'])) {
            $node->setSlug($snapshot['slug']);
        }
        if (isset($snapshot['status']) && \is_string($snapshot['status'])) {
            $node->setStatus($snapshot['status']);
        }
        if (\array_key_exists('moderation_state', $snapshot)) {
            $node->setModerationState(\is_string($snapshot['moderation_state']) ? $snapshot['moderation_state'] : null);
        }
        $node->setData(\is_array($snapshot['data'] ?? null) ? $snapshot['data'] : []);

        $publishedAt = $snapshot['published_at'] ?? null;
        if (\is_string($publishedAt) && $publishedAt !== '') {
            try {
                $node->publish(new \DateTimeImmutable($publishedAt));
                $node->setStatus(\is_string($snapshot['status'] ?? null) ? $snapshot['status'] : $node->getStatus());
            } catch (\Exception) {
                // keep current publish time
            }
        }

        $categoryId = $snapshot['category_id'] ?? null;
        $node->setCategory(\is_int($categoryId) || (\is_string($categoryId) && ctype_digit($categoryId))
            ? $this->entityManager->find(Term::class, (int) $categoryId)
            : null);

        foreach ($node->getTags()->toArray() as $tag) {
            $node->removeTag($tag);
        }
        foreach ((array) ($snapshot['tag_ids'] ?? []) as $tagId) {
            if (is_numeric($tagId)) {
                $tag = $this->entityManager->find(Term::class, (int) $tagId);
                if ($tag instanceof Term) {
                    $node->addTag($tag);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function normalize(array $snapshot): array
    {
        ksort($snapshot);
        if (\is_array($snapshot['data'] ?? null)) {
            ksort($snapshot['data']);
        }

        return $snapshot;
    }
}

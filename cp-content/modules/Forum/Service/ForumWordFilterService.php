<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Modules\Forum\Entity\ForumTopic;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Per-member topic-title blocklist: terms a reader never wants to see in a list.
 *
 * This is a reader's tool, not a moderator's. Nothing is deleted, nothing is
 * hidden from anybody else, and a direct link to a filtered topic still opens —
 * what changes is that the topic stops appearing in the lists this member
 * browses. A forum where one subject floods every board for a month is the case
 * this exists for; censoring that subject for everyone would be a different
 * feature with a different owner.
 *
 * Terms live in User::$data, like the rest of the per-member forum preferences,
 * because a blocklist of a dozen words does not earn a table.
 */
final class ForumWordFilterService
{
    public const DATA_KEY = 'forum_word_filters';

    /** Enough for a flooded board, small enough that the NOT LIKE chain stays sane. */
    private const MAX_TERMS = 25;
    private const MIN_TERM_LENGTH = 2;
    private const MAX_TERM_LENGTH = 60;

    public function __construct(
        private readonly Security $security,
        private readonly SettingsRegistry $settings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('forum.word_filter_enabled', true);
    }

    /**
     * Terms for the member making this request. Every list-building call site
     * uses this rather than reaching for the token itself.
     *
     * @return list<string>
     */
    public function termsForViewer(): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->termsFor($user) : [];
    }

    /**
     * @return list<string>
     */
    public function termsFor(?User $user): array
    {
        if ($user === null || !$this->isEnabled()) {
            return [];
        }

        $stored = $user->getDataValue(self::DATA_KEY);
        if (!\is_array($stored)) {
            return [];
        }

        $terms = [];
        foreach ($stored as $term) {
            if (\is_string($term) && trim($term) !== '') {
                $terms[] = trim($term);
            }
        }

        return \array_slice($terms, 0, self::MAX_TERMS);
    }

    /**
     * Parses the textarea on the account screen: one term per line, commas also
     * accepted because that is how people type a list.
     *
     * @return list<string>
     */
    public function parse(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/u', $raw) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            // Collapse inner whitespace so "yapay   zeka" and "yapay zeka" are one term.
            $term = trim(preg_replace('/\s+/u', ' ', $part) ?? $part);

            if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
                continue;
            }

            $term = mb_substr($term, 0, self::MAX_TERM_LENGTH);

            // Case-insensitive de-duplication, but the member's own spelling is
            // what gets stored and shown back to them.
            foreach ($terms as $existing) {
                if (mb_strtolower($existing) === mb_strtolower($term)) {
                    continue 2;
                }
            }

            $terms[] = $term;

            if (\count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        return $terms;
    }

    public function save(User $user, string $raw): void
    {
        $user->setDataValue(self::DATA_KEY, $this->parse($raw));
        $this->entityManager->flush();
    }

    public function asText(?User $user): string
    {
        return implode("\n", $this->termsFor($user));
    }

    public function maxTerms(): int
    {
        return self::MAX_TERMS;
    }

    /**
     * Adds one `NOT LIKE` per term to a topic query.
     *
     * Deliberately no LOWER() around the column: the tables are utf8mb4_unicode_ci,
     * so LIKE is already case-insensitive, and LOWER() in SQL would drag the
     * Turkish dotted/dotless I problem into a comparison that does not have it.
     *
     * @param list<string> $terms
     */
    public function applyToQuery(QueryBuilder $qb, array $terms, string $alias = 't'): void
    {
        $index = 0;

        foreach ($terms as $term) {
            // Counted, not keyed: the parameter names have to be unique and
            // sequential whatever the caller's array looks like.
            $parameter = 'cpWordFilter'.$index++;

            $qb->andWhere(sprintf('%s.title NOT LIKE :%s', $alias, $parameter))
                ->setParameter($parameter, '%'.addcslashes($term, '%_').'%');
        }
    }

    /**
     * @param list<string> $terms
     */
    public function matches(string $title, array $terms): bool
    {
        foreach ($terms as $term) {
            if (mb_stripos($title, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * In-memory pass for the lists that are already arrays of entities by the
     * time anybody can filter them (unread, search results).
     *
     * @param iterable<ForumTopic> $topics
     * @param list<string>         $terms
     *
     * @return list<ForumTopic>
     */
    public function filterTopics(iterable $topics, array $terms): array
    {
        $out = [];

        foreach ($topics as $topic) {
            if ($terms === [] || !$this->matches($topic->getTitle(), $terms)) {
                $out[] = $topic;
            }
        }

        return $out;
    }

    /**
     * Same pass for rows that have already been mapped to arrays (activity feed,
     * portal blocks).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $terms
     *
     * @return list<array<string, mixed>>
     */
    public function filterRows(array $rows, array $terms, string $titleKey = 'title'): array
    {
        if ($terms === []) {
            return $rows;
        }

        $out = [];

        foreach ($rows as $row) {
            $title = $row[$titleKey] ?? null;

            if (!\is_string($title) || !$this->matches($title, $terms)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;

/**
 * @extends ServiceEntityRepository<ForumTopic>
 */
final class ForumTopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumTopic::class);
    }

    /**
     * @param list<int>|null $sectionIds        Translation-group board ids. Defaults to this section only.
     * @param list<string>   $excludeTitleTerms reader's own word filter; see ForumWordFilterService
     */
    public function createSectionTopicsQueryBuilder(
        ForumSection $section,
        ?User $viewer,
        bool $hidePrivate,
        string $filter = 'all',
        bool $canModerate = false,
        ?string $sortOverride = null,
        ?array $sectionIds = null,
        ?string $contentLocale = null,
        array $excludeTitleTerms = [],
    ): QueryBuilder {
        $ids = $sectionIds ?? [$section->getId() ?? 0];
        $ids = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        ));
        if ($ids === []) {
            $ids = [$section->getId() ?? 0];
        }

        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.prefix', 'prefix')->addSelect('prefix')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->leftJoin('t.firstPoster', 'fp')->addSelect('fp')
            ->andWhere('IDENTITY(t.section) IN (:sectionIds)')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('sectionIds', $ids);

        if ($contentLocale !== null && $contentLocale !== '') {
            $qb->andWhere('t.locale = :contentLocale')
                ->setParameter('contentLocale', $contentLocale);
        }

        $this->applyDiscussionVisibility($qb, $viewer, $canModerate);

        if ($hidePrivate) {
            if ($viewer === null) {
                $qb->andWhere('t.mode = :normal')
                    ->setParameter('normal', ForumTopic::MODE_NORMAL);
            } elseif (!$this->canSeeAllPrivate($viewer)) {
                $qb->andWhere('t.mode = :normal OR (t.mode = :private AND t.firstPoster = :viewer)')
                    ->setParameter('normal', ForumTopic::MODE_NORMAL)
                    ->setParameter('private', ForumTopic::MODE_PRIVATE)
                    ->setParameter('viewer', $viewer);
            }
        }

        if ($filter === 'solved') {
            $qb->andWhere('prefix.cssClass LIKE :solvedClass OR LOWER(prefix.label) IN (:solvedLabels)')
                ->setParameter('solvedClass', '%solved%')
                ->setParameter('solvedLabels', ['çözüldü', 'solved']);
        }

        if ($filter === 'mine' && $viewer !== null) {
            $qb->andWhere('t.firstPoster = :mineViewer')
                ->setParameter('mineViewer', $viewer);

            // "My topics" is the one list a word filter must not touch: hiding a
            // member's own thread from them because its title contains a term
            // they muted would read as data loss, not as a filter.
        } else {
            $this->excludeTitleTerms($qb, $excludeTitleTerms);
        }

        $sort = $sortOverride ?? $section->getDefaultTopicSort();
        if ($filter === 'latest') {
            $sort = 'latest';
        }
        if ($filter === 'popular') {
            $sort = 'views';
        }

        $qb->orderBy('t.sticky', 'DESC');
        match ($sort) {
            'created' => $qb->addOrderBy('t.createdAt', 'DESC')->addOrderBy('t.id', 'DESC'),
            'title' => $qb->addOrderBy('t.title', 'ASC'),
            'replies' => $qb->addOrderBy('t.postCount', 'DESC')->addOrderBy('t.updatedAt', 'DESC'),
            'views' => $qb->addOrderBy('t.viewCount', 'DESC')->addOrderBy('t.updatedAt', 'DESC'),
            default => $qb->addOrderBy('t.lastPostDate', 'DESC')->addOrderBy('t.updatedAt', 'DESC'),
        };

        return $qb;
    }

    public function countBySection(ForumSection $section): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.section = :section')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('section', $section)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countVisiblePublic(?string $contentLocale = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible);

        if ($contentLocale !== null && $contentLocale !== '') {
            $qb->andWhere('t.locale = :contentLocale')
                ->setParameter('contentLocale', $contentLocale);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Visible topic counts in a translation group, keyed by content locale.
     *
     * @param list<int> $sectionIds
     *
     * @return array<string, int>
     */
    public function countVisibleBySectionIdsGroupedByLocale(array $sectionIds): array
    {
        $sectionIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $sectionIds),
            static fn (int $id): bool => $id > 0,
        ));
        if ($sectionIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('t')
            ->select('t.locale AS locale, COUNT(t.id) AS cnt')
            ->andWhere('IDENTITY(t.section) IN (:ids)')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.discussionState = :visible')
            ->andWhere('t.mode = :normal')
            ->setParameter('ids', $sectionIds)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->groupBy('t.locale')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['locale']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * @param list<string> $excludeTitleTerms reader word filter; see ForumWordFilterService
     *
     * @return list<ForumTopic>
     */
    public function findLatest(int $limit = 10, int $offset = 0, ?string $contentLocale = null, array $excludeTitleTerms = []): array
    {
        return $this->createPublicTopicListQuery($contentLocale, $excludeTitleTerms)
            ->orderBy('t.updatedAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<string> $excludeTitleTerms reader word filter; see ForumWordFilterService
     *
     * @return list<ForumTopic>
     */
    public function findNewestOpened(int $limit = 10, int $offset = 0, ?string $contentLocale = null, array $excludeTitleTerms = []): array
    {
        return $this->createPublicTopicListQuery($contentLocale, $excludeTitleTerms)
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<string> $excludeTitleTerms reader word filter; see ForumWordFilterService
     *
     * @return list<ForumTopic>
     */
    public function findLatestReplied(int $limit = 10, int $offset = 0, ?string $contentLocale = null, array $excludeTitleTerms = []): array
    {
        return $this->createPublicTopicListQuery($contentLocale, $excludeTitleTerms)
            ->andWhere('t.postCount > 1')
            ->orderBy('t.lastPostDate', 'DESC')
            ->addOrderBy('t.updatedAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneVisibleBySlug(string $slug): ?ForumTopic
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.slug = :slug')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('slug', $slug)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ForumTopic>
     */
    public function findDeleted(int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->andWhere('t.discussionState = :deleted')
            ->setParameter('deleted', ForumDiscussionState::Deleted)
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<ForumTopic>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Studio topic list — section and prefix loaded in one query (N+1 guard).
     */
    public function createAdminListQueryBuilder(?string $search = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->leftJoin('t.prefix', 'prefix')->addSelect('prefix')
            ->orderBy('t.sticky', 'DESC')
            ->addOrderBy('t.updatedAt', 'DESC');

        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $qb->andWhere('t.title LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb;
    }

    /**
     * @param list<string> $excludeTitleTerms reader's word filter; see ForumWordFilterService
     */
    private function createPublicTopicListQuery(?string $contentLocale = null, array $excludeTitleTerms = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->leftJoin('t.firstPoster', 'fp')->addSelect('fp')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible);

        if ($contentLocale !== null && $contentLocale !== '') {
            $qb->andWhere('t.locale = :contentLocale')
                ->setParameter('contentLocale', $contentLocale);
        }

        $this->excludeTitleTerms($qb, $excludeTitleTerms);

        return $qb;
    }

    /**
     * Per-reader title blocklist, applied in SQL so pagination still counts what
     * the reader can actually see.
     *
     * No LOWER() on the column: the tables are utf8mb4_unicode_ci so LIKE is
     * already case-insensitive, and LOWER() would import the Turkish dotted-I
     * problem into a comparison that does not have it.
     *
     * @param list<string> $terms
     */
    private function excludeTitleTerms(QueryBuilder $qb, array $terms, string $alias = 't'): void
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
     * @param list<string> $excludeTitleTerms reader word filter; see ForumWordFilterService
     *
     * @return list<ForumTopic>
     */
    public function findPopular(int $limit = 10, ?string $contentLocale = null, array $excludeTitleTerms = []): array
    {
        return $this->createPublicTopicListQuery($contentLocale, $excludeTitleTerms)
            ->orderBy('t.viewCount', 'DESC')
            ->addOrderBy('t.postCount', 'DESC')
            ->addOrderBy('t.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPublicByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.firstPoster = :author')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function createPublicByAuthorQueryBuilder(User $author): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.firstPoster = :author')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->orderBy('t.createdAt', 'DESC');
    }

    public function createRepliedTopicsByAuthorQueryBuilder(User $author): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->andWhere('EXISTS (
                SELECT 1 FROM Modules\Forum\Entity\ForumPost p
                WHERE p.topic = t AND p.author = :author
                AND p.createdAt > (
                    SELECT MIN(fp.createdAt) FROM Modules\Forum\Entity\ForumPost fp WHERE fp.topic = t
                )
            )')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->orderBy('t.updatedAt', 'DESC');
    }

    public function countRepliedTopicsByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('EXISTS (
                SELECT 1 FROM Modules\Forum\Entity\ForumPost p
                WHERE p.topic = t AND p.author = :author
                AND p.createdAt > (
                    SELECT MIN(fp.createdAt) FROM Modules\Forum\Entity\ForumPost fp WHERE fp.topic = t
                )
            )')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $userIds
     *
     * @return array<int, int>
     */
    public function countTopicsForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.firstPoster) AS userId, COUNT(t.id) AS cnt')
            ->andWhere('t.firstPoster IN (:userIds)')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('userIds', $userIds)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->groupBy('t.firstPoster')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['cnt'];
        }

        return $map;
    }

    private function applyDiscussionVisibility(QueryBuilder $qb, ?User $viewer, bool $canModerate): void
    {
        if ($canModerate) {
            $qb->andWhere('t.discussionState IN (:boardStates)')
                ->setParameter('boardStates', [ForumDiscussionState::Visible, ForumDiscussionState::Moderated]);

            return;
        }

        if ($viewer !== null) {
            $qb->andWhere('t.discussionState = :visible OR (t.discussionState = :moderated AND t.firstPoster = :stateViewer)')
                ->setParameter('visible', ForumDiscussionState::Visible)
                ->setParameter('moderated', ForumDiscussionState::Moderated)
                ->setParameter('stateViewer', $viewer);

            return;
        }

        $qb->andWhere('t.discussionState = :visible')
            ->setParameter('visible', ForumDiscussionState::Visible);
    }

    /**
     * Guest-visible public threads for a locale (sitemap).
     *
     * @return list<ForumTopic>
     */
    public function findPublicForSitemap(string $locale, int $limit, int $offset): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.section', 's')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.discussionState = :visible')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('locale', $locale)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    private function canSeeAllPrivate(User $viewer): bool
    {
        return \in_array('admin', $viewer->getCpaliusRoles(), true);
    }
}

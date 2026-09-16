<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Resolves a compose target from an id, username or e-mail without opening a
 * generic user search to strangers.
 */
final class MessagesUserLookup
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public static function normalizeHandle(string $raw): string
    {
        $raw = trim(str_replace(["\0", "\r", "\n", "\t"], '', $raw));
        $raw = ltrim($raw, '@');

        return trim($raw);
    }

    public function resolve(string $raw): ?User
    {
        $raw = self::normalizeHandle($raw);
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            $user = $this->users->find((int) $raw);

            return $user instanceof User ? $user : null;
        }

        $user = $this->users->createQueryBuilder('u')
            ->andWhere('LOWER(u.username) = :username')
            ->setParameter('username', mb_strtolower($raw))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($user instanceof User) {
            return $user;
        }

        return $this->users->findOneByEmail($raw);
    }

    /**
     * @return list<User>
     */
    public function suggest(string $term, User $except, int $limit = 8): array
    {
        $term = self::normalizeHandle($term);
        $term = str_replace(['%', '_'], '', $term);
        if ($term === '') {
            return [];
        }

        $like = '%'.mb_strtolower($term).'%';

        return $this->users->createQueryBuilder('u')
            ->andWhere('u.status = :active')
            ->andWhere('u.id != :except')
            ->andWhere('u.username IS NOT NULL AND u.username != \'\'')
            ->andWhere('LOWER(u.username) LIKE :q')
            ->setParameter('active', User::STATUS_ACTIVE)
            ->setParameter('except', $except->getId())
            ->setParameter('q', $like)
            ->orderBy('u.username', 'ASC')
            ->setMaxResults(max(1, min(20, $limit)))
            ->getQuery()
            ->getResult();
    }
}

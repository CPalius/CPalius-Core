<?php

declare(strict_types=1);

namespace App\Core\Search\Admin\Provider;

use App\Core\Search\Admin\AdminSearchProviderInterface;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * User accounts by e-mail or username, for staff who may manage users.
 */
final class UserSearchProvider implements AdminSearchProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function search(string $term, string $panel, int $limit): ?array
    {
        if (!$this->security->isGranted('system.users.manage')) {
            return null;
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('u.id', 'u.email', 'u.username')
            ->from(User::class, 'u')
            ->where('u.email LIKE :q OR u.username LIKE :q')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $hits = [];

        foreach ($rows as $row) {
            $username = trim((string) ($row['username'] ?? ''));

            $hits[] = [
                'title' => $username !== '' ? $username : (string) $row['email'],
                'url' => $this->urls->generate('aacp_users_edit', ['id' => (int) $row['id']]),
                'subtitle' => $username !== '' ? (string) $row['email'] : null,
            ];
        }

        return ['key' => 'users', 'label' => $this->translator->trans('admin.search.group.users'), 'icon' => 'tabler:users', 'hits' => $hits];
    }
}

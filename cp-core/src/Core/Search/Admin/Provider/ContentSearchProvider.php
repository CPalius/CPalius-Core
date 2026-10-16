<?php

declare(strict_types=1);

namespace App\Core\Search\Admin\Provider;

use App\Core\Search\Admin\AdminSearchProviderInterface;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Content (posts, pages and any type with an edit screen) by title, for Studio users.
 */
final class ContentSearchProvider implements AdminSearchProviderInterface
{
    /** Content type => edit route taking {id}. A type without an entry is not searchable here. */
    private const EDIT_ROUTES = [
        'post' => 'admin_posts_edit',
        'page' => 'admin_pages_edit',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function search(string $term, string $panel, int $limit): ?array
    {
        if ($panel !== 'studio' || !$this->security->isGranted('admin.access')) {
            return null;
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('n.id', 'n.title', 'n.type', 'n.status', 'n.locale')
            ->from(Node::class, 'n')
            ->where('n.title LIKE :q AND n.deletedAt IS NULL AND n.type IN (:types)')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%')
            ->setParameter('types', array_keys(self::EDIT_ROUTES))
            ->orderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $hits = [];

        foreach ($rows as $row) {
            try {
                $url = $this->urls->generate(self::EDIT_ROUTES[$row['type']], ['id' => (int) $row['id']]);
            } catch (\Throwable) {
                // The module that owns this type is not active.
                continue;
            }

            $hits[] = [
                'title' => (string) $row['title'],
                'url' => $url,
                'subtitle' => $this->translator->trans('admin.search.type.'.$row['type']).' · '.$row['status'].' · '.$row['locale'],
            ];
        }

        return ['key' => 'content', 'label' => $this->translator->trans('admin.search.group.content'), 'icon' => 'tabler:file-text', 'hits' => $hits];
    }
}

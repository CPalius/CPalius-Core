<?php

declare(strict_types=1);

namespace App\Core\Mail\Repository;

use App\Core\Mail\Entity\MailTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailTemplate>
 */
class MailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailTemplate::class);
    }

    public function findOneFor(string $templateKey, string $locale): ?MailTemplate
    {
        return $this->findOneBy(['templateKey' => $templateKey, 'locale' => $locale]);
    }

    /**
     * Every stored row of one template, keyed by locale, so the edit screen
     * loads all languages in one query instead of one per column.
     *
     * @return array<string, MailTemplate>
     */
    public function findByKeyIndexedByLocale(string $templateKey): array
    {
        $rows = [];

        foreach ($this->findBy(['templateKey' => $templateKey]) as $row) {
            $rows[$row->getLocale()] = $row;
        }

        return $rows;
    }

    /**
     * Which (key, locale) pairs an operator has actually customised — the list
     * screen needs this to show coverage without loading the bodies.
     *
     * @return array<string, list<string>> template key => locales
     */
    public function customisedLocalesByKey(): array
    {
        /** @var list<array{templateKey: string, locale: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.templateKey AS templateKey, t.locale AS locale')
            ->andWhere('t.enabled = true')
            ->getQuery()
            ->getArrayResult();

        $map = [];

        foreach ($rows as $row) {
            $map[$row['templateKey']][] = $row['locale'];
        }

        return $map;
    }
}

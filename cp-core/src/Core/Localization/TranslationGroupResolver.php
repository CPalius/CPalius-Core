<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Core\Localization\Contract\TranslatableInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Siblings and missing locales for any TranslatableInterface via DQL on the concrete class.
 * Query failure returns [] so admin tabs stay empty rather than taking the page down.
 */
final class TranslationGroupResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    /**
     * Translation siblings keyed by locale, excluding $entity.
     *
     * @return array<string, TranslatableInterface>
     */
    public function findSiblings(TranslatableInterface $entity): array
    {
        $groupId = $entity->getTranslationGroupId();

        if ($groupId === null) {
            return [];
        }

        try {
            // Use metadata class name: $entity::class may be a Doctrine proxy that DQL does not know.
            $className = $this->entityManager->getClassMetadata($entity::class)->getName();

            $rows = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from($className, 'e')
                ->andWhere('e.translationGroupId = :groupId')
                ->setParameter('groupId', $groupId, 'uuid')
                ->getQuery()
                ->getResult();
        } catch (\Throwable) {
            return [];
        }

        $siblings = [];

        foreach ($rows as $row) {
            if (!$row instanceof TranslatableInterface || $row === $entity) {
                continue;
            }

            $siblings[$row->getLocale()] = $row;
        }

        return $siblings;
    }

    /**
     * Full group including $entity, keyed by locale.
     *
     * @return array<string, TranslatableInterface>
     */
    public function findGroup(TranslatableInterface $entity): array
    {
        return [$entity->getLocale() => $entity] + $this->findSiblings($entity);
    }

    /**
     * Active locales still missing a sibling — source for "add translation" buttons.
     *
     * @return list<string>
     */
    public function missingLocales(TranslatableInterface $entity): array
    {
        $existing = array_keys($this->findGroup($entity));

        return array_values(array_filter(
            $this->localeProvider->getCodes(),
            static fn (string $code): bool => !\in_array($code, $existing, true),
        ));
    }

    /**
     * Join $translation to $source's group (creates the group if needed). Does not flush.
     */
    public function link(TranslatableInterface $source, TranslatableInterface $translation): void
    {
        $translation->joinTranslationGroup($source->ensureTranslationGroup());
    }

    /**
     * One admin tab per active locale (edit if present, "add" if not). Works for ungrouped rows too.
     *
     * @return list<TranslationTab>
     */
    public function tabsFor(TranslatableInterface $entity): array
    {
        $group = $this->findGroup($entity);
        $currentLocale = $entity->getLocale();
        $tabs = [];

        foreach ($this->localeProvider->getLocales() as $locale) {
            $sibling = $group[$locale->code] ?? null;

            $tabs[] = new TranslationTab(
                code: $locale->code,
                nativeName: $locale->nativeName,
                exists: $sibling instanceof TranslatableInterface,
                id: $sibling instanceof TranslatableInterface ? $this->extractId($sibling) : null,
                isCurrent: $locale->code === $currentLocale,
                label: $sibling instanceof TranslatableInterface ? $this->extractLabel($sibling) : null,
            );
        }

        return $tabs;
    }

    /**
     * Optional getId() via method_exists — TranslatableInterface does not require it.
     */
    private function extractId(TranslatableInterface $entity): ?int
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }

        $id = $entity->getId();

        return \is_int($id) ? $id : null;
    }

    /**
     * Tooltip label: try getTitle / getName / getLabel in that order.
     */
    private function extractLabel(TranslatableInterface $entity): ?string
    {
        foreach (['getTitle', 'getName', 'getLabel'] as $getter) {
            if (!method_exists($entity, $getter)) {
                continue;
            }

            $value = $entity->{$getter}();

            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

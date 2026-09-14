<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Entity\EntityTypeRegistry;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldValuePersister;
use App\Core\Field\ReferenceTargetResolver;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * T1.3 — Vocabulary + Term as a first-class, fieldable entity type.
 *
 * The point of the taxonomy rewrite was that a Term is not a special case: it
 * is an ordinary #[CpEntityType] that happens to be organised into
 * vocabularies. These tests hold that claim to account — the bundle really is
 * the vocabulary machine name, so two vocabularies can carry different fields;
 * the hierarchy really persists; and EntityReference can really target
 * "term:{vid}" without the Field API knowing anything about taxonomy.
 */
#[CoversClass(Term::class)]
#[CoversClass(Vocabulary::class)]
final class TaxonomyTest extends IntegrationTestCase
{
    public function testTermIsFieldablePerVocabularyAndHierarchical(): void
    {
        $container = $this->container();
        $em = $this->em();

        $genres = new Vocabulary('genres', 'Genres');
        $genres->setHierarchical(true);
        $regions = new Vocabulary('regions', 'Regions');
        $em->persist($genres);
        $em->persist($regions);

        // The bundle of a Term is its vocabulary's machine name, so a field
        // defined for "genres" must not appear on a "regions" term.
        $em->persist(new FieldDefinition('genres', 'mood', 'text', 'Mood'));
        $em->flush();

        /** @var FieldDefinitionRegistry $fields */
        $fields = $container->get(FieldDefinitionRegistry::class);
        $fields->invalidate();

        self::assertTrue($fields->hasFields('genres'));
        self::assertFalse($fields->hasFields('regions'), 'a field must not leak across vocabularies');

        $rock = new Term($genres, 'Rock', 'rock', 'en');
        $em->persist($rock);
        $em->flush();

        self::assertSame('taxonomy_term', $rock->fieldableEntityTypeId());
        self::assertSame('genres', $rock->fieldableBundle(), 'bundle is the vocabulary machine name');
        self::assertSame('en', $rock->fieldableLocale());

        /** @var FieldValuePersister $persister */
        $persister = $container->get(FieldValuePersister::class);
        $violations = $persister->persist($rock, ['mood' => 'loud']);
        self::assertSame([], $violations);
        $em->flush();
        $em->clear();

        /** @var TermRepository $terms */
        $terms = $container->get(TermRepository::class);
        $reloaded = $terms->find($rock->getId());
        self::assertInstanceOf(Term::class, $reloaded);
        self::assertSame('loud', $reloaded->getFieldableData()['mood'] ?? null);

        // Hierarchy: a child keeps its parent across a reload.
        $punk = new Term($reloaded->getVocabulary(), 'Punk', 'punk', 'en');
        $punk->setParent($reloaded);
        $em->persist($punk);
        $em->flush();
        $em->clear();

        $reloadedChild = $terms->find($punk->getId());
        self::assertInstanceOf(Term::class, $reloadedChild);
        self::assertSame('rock', $reloadedChild->getParent()?->getSlug());
    }

    public function testEntityTypeAndReferenceResolution(): void
    {
        $container = $this->container();
        $em = $this->em();

        /** @var EntityTypeRegistry $entityTypes */
        $entityTypes = $container->get(EntityTypeRegistry::class);

        // Discovered at compile time from the #[CpEntityType] attribute, not
        // registered by hand anywhere.
        self::assertTrue($entityTypes->has('taxonomy_term'));
        $definition = $entityTypes->get('taxonomy_term');
        self::assertTrue($definition->bundleable);
        self::assertContains('taxonomy_term', array_keys($entityTypes->fieldable()));

        $genres = new Vocabulary('genres', 'Genres');
        $regions = new Vocabulary('regions', 'Regions');
        $em->persist($genres);
        $em->persist($regions);

        $rock = new Term($genres, 'Rock', 'rock', 'en');
        $europe = new Term($regions, 'Europe', 'europe', 'en');
        $em->persist($rock);
        $em->persist($europe);
        $em->flush();

        /** @var ReferenceTargetResolver $resolver */
        $resolver = $container->get(ReferenceTargetResolver::class);

        // isValidTarget() checks the SHAPE of a target string, not whether the
        // vocabulary exists — a field definition may legitimately name a
        // vocabulary that is created later. Existence is enforced at write
        // time by exists(), asserted below.
        self::assertTrue($resolver->isValidTarget('term'));
        self::assertTrue($resolver->isValidTarget('term:genres'));
        self::assertTrue($resolver->isValidTarget('term:not_yet_created'));
        self::assertFalse($resolver->isValidTarget('term:Not Valid!'), 'a malformed machine name is rejected');
        self::assertFalse($resolver->isValidTarget('nonsense'));

        // A vocabulary-scoped target must refuse a term from another
        // vocabulary; otherwise "genre" fields could quietly hold regions.
        self::assertTrue($resolver->exists('term:genres', (int) $rock->getId()));
        self::assertFalse($resolver->exists('term:genres', (int) $europe->getId()));
        self::assertTrue($resolver->exists('term', (int) $europe->getId()), 'the unscoped target accepts any term');

        $loaded = $resolver->load('term:genres', [(int) $rock->getId(), (int) $europe->getId()]);
        self::assertCount(1, $loaded, 'load() filters by vocabulary as well');
        self::assertSame('Rock', reset($loaded)?->getName());
    }
}

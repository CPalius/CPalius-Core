<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AACPTaxonomyController;
use App\Core\Config\Provider\TaxonomyConfigProvider;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Core\Taxonomy\Repository\VocabularyRepository;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The taxonomy admin screen: vocabulary CRUD, term CRUD with hierarchy, the
 * guarded delete, and the config provider round trip.
 *
 * The guarded delete is the one worth stating plainly. Terms cascade, so
 * deleting a vocabulary that still holds them would take content with it and
 * offer no way back. The screen refuses instead, which is why the assertion
 * below deletes the terms first and only then succeeds.
 */
#[CoversClass(AACPTaxonomyController::class)]
final class AacpTaxonomyControllerTest extends IntegrationTestCase
{
    public function testVocabularyCreateEditDeleteRoundTrip(): void
    {
        [$controller, $tokens] = $this->boot();

        /** @var VocabularyRepository $vocabularies */
        $vocabularies = $this->container()->get(VocabularyRepository::class);

        $controller->newVocabulary($this->post($tokens['vocabulary'], [
            'machine_name' => 'genres',
            'label' => 'Genres',
            'description' => 'Music genres',
            'hierarchical' => '1',
            'weight' => '5',
        ]));

        $vocabulary = $vocabularies->findOneByMachineName('genres');
        self::assertInstanceOf(Vocabulary::class, $vocabulary);
        self::assertSame('Genres', $vocabulary->getLabel());
        self::assertTrue($vocabulary->isHierarchical());

        $controller->editVocabulary('genres', $this->post($tokens['vocabulary'], [
            'label' => 'Music genres',
            'weight' => '9',
        ]));
        self::assertSame('Music genres', $vocabularies->findOneByMachineName('genres')?->getLabel());

        $controller->deleteVocabulary('genres', $this->post($tokens['vocabulary'], []));
        self::assertNull($vocabularies->findOneByMachineName('genres'), 'an empty vocabulary deletes cleanly');
    }

    public function testTermHierarchyLocaleAndGuardedVocabularyDelete(): void
    {
        [$controller, $tokens] = $this->boot();
        $container = $this->container();
        $em = $this->em();

        /** @var TermRepository $terms */
        $terms = $container->get(TermRepository::class);
        /** @var VocabularyRepository $vocabularies */
        $vocabularies = $container->get(VocabularyRepository::class);

        $vocabulary = new Vocabulary('genres', 'Genres');
        $vocabulary->setHierarchical(true);
        $em->persist($vocabulary);
        $em->flush();

        $controller->newTerm('genres', $this->post($tokens['term'], [
            'name' => 'Rock', 'slug' => '', 'locale' => 'en', 'weight' => '0',
        ]));

        $rock = $terms->findOneBySlug($vocabulary, 'rock', 'en');
        self::assertInstanceOf(Term::class, $rock, 'an empty slug is derived from the name');

        $controller->newTerm('genres', $this->post($tokens['term'], [
            'name' => 'Punk', 'slug' => '', 'locale' => 'en', 'weight' => '0',
            'parent_id' => (string) $rock->getId(),
        ]));

        $punk = $terms->findOneBySlug($vocabulary, 'punk', 'en');
        self::assertInstanceOf(Term::class, $punk);
        self::assertSame($rock->getId(), $punk->getParent()?->getId());

        // Guarded: a vocabulary holding terms is not deletable, because the
        // cascade would silently take the terms (and the content referencing
        // them) with it.
        $controller->deleteVocabulary('genres', $this->post($tokens['vocabulary'], []));
        self::assertNotNull(
            $vocabularies->findOneByMachineName('genres'),
            'the delete is refused while terms remain',
        );

        $controller->deleteTerm('genres', (int) $punk->getId(), $this->post($tokens['term'], []));
        self::assertNull($terms->findOneBySlug($vocabulary, 'punk', 'en'));

        $controller->deleteTerm('genres', (int) $rock->getId(), $this->post($tokens['term'], []));

        $controller->deleteVocabulary('genres', $this->post($tokens['vocabulary'], []));
        self::assertNull($vocabularies->findOneByMachineName('genres'), 'now empty, it deletes');
    }

    public function testTermManagementIsDeniedWithoutATaxonomyCapability(): void
    {
        [$controller, $tokens] = $this->boot();

        $em = $this->em();
        $em->persist(new Vocabulary('genres', 'Genres'));
        $em->flush();

        // "member" holds forum and account capabilities but nothing from the
        // taxonomy family — neither taxonomy.manage nor taxonomy.genres.manage.
        $this->authenticateAs('member');

        $this->expectException(AccessDeniedException::class);
        $controller->newTerm('genres', $this->post($tokens['term'], [
            'name' => 'Rock', 'slug' => '', 'locale' => 'en', 'weight' => '0',
        ]));
    }

    public function testConfigProviderExportsAndUpsertsVocabularyStructure(): void
    {
        [$controller, $tokens] = $this->boot();
        $container = $this->container();

        /** @var VocabularyRepository $vocabularies */
        $vocabularies = $container->get(VocabularyRepository::class);

        $controller->newVocabulary($this->post($tokens['vocabulary'], [
            'machine_name' => 'tags', 'label' => 'Tags', 'weight' => '0',
        ]));

        /** @var TaxonomyConfigProvider $provider */
        $provider = $container->get(TaxonomyConfigProvider::class);

        self::assertContains('taxonomy.tags', $provider->documents());
        self::assertTrue($provider->ownsDocument('taxonomy.tags'));

        $exported = $provider->exportDocument('taxonomy.tags');
        self::assertSame('Tags', $exported['label']);
        self::assertArrayNotHasKey('machine_name', $exported, 'the machine name is implied by the document name');

        self::assertSame([], $provider->diffDocument('taxonomy.tags', $exported), 'export round-trips with no diff');
        self::assertNotSame([], $provider->diffDocument('taxonomy.tags', ['label' => 'Different']));

        // Upsert-only, unlike the field and display providers: this one never
        // deletes, because removing a vocabulary would cascade into terms and
        // the content that references them. Deletion stays a deliberate act in
        // the admin screen, where the guard above applies.
        $provider->importDocument('taxonomy.tags', ['label' => 'Tags v2', 'hierarchical' => false]);
        $this->em()->flush();

        $reloaded = $vocabularies->findOneByMachineName('tags');
        self::assertSame('Tags v2', $reloaded?->getLabel());
        self::assertFalse($reloaded->isHierarchical());

        $provider->importDocument('taxonomy.moods', ['label' => 'Moods']);
        $this->em()->flush();
        self::assertNotNull($vocabularies->findOneByMachineName('moods'), 'an unknown document creates the vocabulary');
    }

    /**
     * @return array{AACPTaxonomyController, array{vocabulary: string, term: string}}
     */
    private function boot(): array
    {
        $container = $this->container();
        $this->pushRequest();

        // Term routes are capability-guarded (taxonomy.manage, or the
        // per-vocabulary taxonomy.{vid}.manage added in GC1), so these tests
        // need a real subject rather than an anonymous one.
        $this->authenticateAs('admin');

        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $container->get('security.csrf.token_manager');

        /** @var AACPTaxonomyController $controller */
        $controller = $container->get(AACPTaxonomyController::class);

        return [$controller, [
            'vocabulary' => $csrf->getToken('aacp_taxonomy_vocabulary')->getValue(),
            'term' => $csrf->getToken('aacp_taxonomy_term')->getValue(),
        ]];
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $token, array $fields): Request
    {
        $request = new Request(request: ['_token' => $token] + $fields);
        $request->setMethod('POST');

        return $request;
    }
}

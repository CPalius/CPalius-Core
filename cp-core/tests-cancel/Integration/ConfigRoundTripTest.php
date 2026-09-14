<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Config\Provider\FieldConfigProvider;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\Repository\FieldDefinitionRepository;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Configuration management: what leaves the database must come back identical.
 *
 * A config system earns trust from one property — export, re-import, and
 * nothing changed. Without it, "deploy your configuration" becomes "hope your
 * configuration survives", and drift between environments is discovered in
 * production. The field provider is authoritative, so this also pins the
 * removal case: a definition dropped from the document is dropped from the
 * database, not silently kept.
 */
#[CoversClass(FieldConfigProvider::class)]
final class ConfigRoundTripTest extends IntegrationTestCase
{
    public function testFieldDefinitionsRoundTrip(): void
    {
        $container = $this->container();
        $em = $this->em();

        /** @var FieldTypeRegistry $types */
        $types = $container->get(FieldTypeRegistry::class);

        // Settings are normalised at write time by every real path (the AACP
        // screen, the module seeder, config import). Constructing a definition
        // by hand without doing so would leave raw settings in the database and
        // produce drift that never resolves, so the test writes them the same
        // way production does.
        $subtitle = new FieldDefinition('page', 'subtitle', 'text', 'Subtitle');
        $subtitle->setRequired(true)->setWeight(2)->setHelp('Shown under the title')
            ->setSettings($types->get('text')->normalizeSettings([]));

        $rank = new FieldDefinition('page', 'rank', 'integer', 'Rank');
        $rank->setQueryable(true)->setWeight(5)
            ->setSettings($types->get('integer')->normalizeSettings([]));

        $em->persist($subtitle);
        $em->persist($rank);
        $em->flush();

        /** @var FieldDefinitionRegistry $registry */
        $registry = $container->get(FieldDefinitionRegistry::class);
        $registry->invalidate();

        /** @var FieldConfigProvider $provider */
        $provider = $container->get(FieldConfigProvider::class);

        self::assertContains('field.page', $provider->documents());
        self::assertTrue($provider->ownsDocument('field.page'));
        self::assertFalse($provider->ownsDocument('display.page'), 'providers only claim their own documents');

        $exported = $provider->exportDocument('field.page');

        // The decisive assertion: exporting and diffing the same document
        // reports no change. A provider that cannot round-trip its own output
        // will report phantom drift on every deploy.
        self::assertSame([], $provider->diffDocument('field.page', $exported), 'export re-imports with no diff');

        // A genuine difference is still detected — otherwise the empty diff
        // above would prove nothing.
        $modified = $exported;
        $modified['subtitle']['label'] = 'Changed';
        self::assertNotSame([], $provider->diffDocument('field.page', $modified));

        // Importing the modified document applies it.
        $provider->importDocument('field.page', $modified);
        $em->flush();
        $registry->invalidate();

        /** @var FieldDefinitionRepository $repository */
        $repository = $container->get(FieldDefinitionRepository::class);
        self::assertSame('Changed', $repository->findOneByBundleAndName('page', 'subtitle')?->getLabel());

        // Authoritative: a definition absent from the incoming document is
        // removed rather than left behind as invisible drift.
        $withoutRank = $modified;
        unset($withoutRank['rank']);
        $provider->importDocument('field.page', $withoutRank);
        $em->flush();
        $registry->invalidate();

        self::assertNull(
            $repository->findOneByBundleAndName('page', 'rank'),
            'a field dropped from the document is dropped from the database',
        );
        self::assertNotNull($repository->findOneByBundleAndName('page', 'subtitle'));
    }
}

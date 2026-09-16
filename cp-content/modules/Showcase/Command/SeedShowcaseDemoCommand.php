<?php

declare(strict_types=1);

namespace Modules\Showcase\Command;

use App\Core\Localization\LocaleProvider;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Entity\Asset;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Showcase\Dto\ShowcaseItemInput;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseItemMedia;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;
use Modules\Showcase\Service\ShowcaseDemoImageFactory;
use Modules\Showcase\Service\ShowcaseItemManager;
use Modules\Showcase\Service\ShowcasePresetLibrary;
use Modules\Showcase\Service\ShowcaseTypeManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills the showcase with believable demo entries so a fresh install is not an
 * empty grid.
 *
 * Everything goes through ShowcaseItemManager and AssetManager rather than raw
 * INSERTs: the demo rows are then indistinguishable from member submissions —
 * sanitized bodies, flat-index rows, real image assets — so what an operator
 * sees while evaluating the module is what they will get in production.
 */
#[AsCommand(
    name: 'cp:showcase:seed-demo',
    description: 'Creates demo showcase types, categories, entries and gallery images.',
)]
final class SeedShowcaseDemoCommand extends Command
{
    /** Types the demo set builds on, with the preset that shapes each one. */
    private const DEMO_TYPES = ['vehicle', 'website', 'software', 'service', 'property'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseTypeManager $typeManager,
        private readonly ShowcaseItemManager $itemManager,
        private readonly ShowcasePresetLibrary $presets,
        private readonly ShowcaseDemoImageFactory $images,
        private readonly TermRepository $terms,
        private readonly UserRepository $users,
        private readonly LocaleProvider $localeProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('items', null, InputOption::VALUE_REQUIRED, 'How many entries to create', '18')
            ->addOption('no-images', null, InputOption::VALUE_NONE, 'Skip image generation (much faster)')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete previously seeded demo entries first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = max(1, min(120, (int) $input->getOption('items')));
        $withImages = !$input->getOption('no-images');
        $locale = $this->localeProvider->getDefaultCode();

        $owners = $this->users->findBy([], ['id' => 'ASC'], 8);

        if ($owners === []) {
            $io->error('No users exist; a showcase entry needs an owner.');

            return Command::FAILURE;
        }

        if ($input->getOption('purge')) {
            $io->writeln(sprintf('Removed %d previously seeded entries.', $this->purge()));
        }

        if ($withImages && !$this->images->isAvailable()) {
            $io->warning('GD is not available; entries will be created without images.');
            $withImages = false;
        }

        $types = $this->ensureTypes($io, $locale);

        if ($types === []) {
            $io->error('No usable showcase types.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Creating %d entries across %d types…', $count, \count($types)));
        $io->progressStart($count);

        $created = 0;
        $withGallery = 0;

        for ($i = 0; $i < $count; ++$i) {
            $presetId = self::DEMO_TYPES[$i % \count(self::DEMO_TYPES)];
            $type = $types[$presetId] ?? null;

            if (!$type instanceof ShowcaseType) {
                $io->progressAdvance();
                continue;
            }

            $item = $this->createItem($type, $presetId, $owners[$i % \count($owners)], $locale, $i);

            if ($item === null) {
                $io->progressAdvance();
                continue;
            }

            ++$created;

            if ($withImages) {
                // Two to four slides, so the detail page exercises both the
                // single-image and the slider rendering.
                $slides = 2 + ($i % 3);

                if ($this->attachImages($item, $slides) > 0) {
                    ++$withGallery;
                }
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success(sprintf(
            '%d entries created, %d with galleries. Visit /%s/showcase',
            $created,
            $withGallery,
            $locale,
        ));

        return Command::SUCCESS;
    }

    /**
     * Ensures every demo type exists and carries its preset's field schema.
     *
     * @return array<string, ShowcaseType>
     */
    private function ensureTypes(SymfonyStyle $io, string $locale): array
    {
        $out = [];

        foreach (self::DEMO_TYPES as $presetId) {
            $type = $this->types->findOneByMachineName($presetId);

            if ($type instanceof ShowcaseType) {
                // An existing type may be missing fields — a hand-made type, or
                // one left half-built by an interrupted run. Top it up instead of
                // skipping it, or the entries below would have nothing to fill.
                $added = $this->presets->seedFields($type, $presetId);

                if ($added > 0) {
                    $io->writeln(sprintf('  "%s" already existed; added %d missing fields.', $presetId, $added));
                }
            } else {
                $result = $this->presets->apply($presetId);
                $type = $result['type'];

                if (!$type instanceof ShowcaseType) {
                    $io->writeln(sprintf('  skipped "%s": %s', $presetId, (string) $result['error']));
                    continue;
                }

                $io->writeln(sprintf('  created type "%s" with %d fields.', $presetId, $result['fieldsCreated']));
            }

            $this->ensureCategories($type, $presetId, $locale);
            $out[$presetId] = $type;
        }

        return $out;
    }

    /**
     * Gives each type a handful of categories so the front-end category filter
     * has something to filter by.
     */
    private function ensureCategories(ShowcaseType $type, string $presetId, string $locale): void
    {
        $vocabulary = $this->typeManager->vocabularyOf($type);

        if ($vocabulary === null) {
            return;
        }

        $existing = $this->terms->findByVocabulary($vocabulary, $locale);

        if ($existing !== []) {
            return;
        }

        foreach (DemoContent::categories($presetId) as $index => $name) {
            $slug = $this->slugify($presetId.'-'.$name);
            $term = new Term($vocabulary, $name, $slug, $locale);
            $term->setWeight($index * 10);
            $this->entityManager->persist($term);
        }

        $this->entityManager->flush();
    }

    private function createItem(ShowcaseType $type, string $presetId, User $owner, string $locale, int $index): ?ShowcaseItem
    {
        $blueprint = DemoContent::item($presetId, $index);
        $vocabulary = $this->typeManager->vocabularyOf($type);
        $termIds = [];

        if ($vocabulary !== null) {
            $terms = $this->terms->findByVocabulary($vocabulary, $locale);

            if ($terms !== []) {
                $term = $terms[$index % \count($terms)];
                $id = $term->getId();

                if ($id !== null) {
                    $termIds[] = $id;
                }
            }
        }

        $input = new ShowcaseItemInput(
            title: $blueprint['title'],
            summary: $blueprint['summary'],
            body: $blueprint['body'],
            bodyFormat: 'basic_html',
            priceMode: $blueprint['priceMode'],
            price: $blueprint['price'],
            priceCurrency: 'TRY',
            externalUrl: $blueprint['externalUrl'],
            demoUrl: $blueprint['demoUrl'],
            contactEmail: $owner->getEmail(),
            contactPhone: $blueprint['phone'],
            location: $blueprint['location'],
            fields: $blueprint['fields'],
            termIds: $termIds,
        );

        $result = $this->itemManager->create($type, $owner, $locale, $input, canPublishDirectly: true);
        $item = $result['item'];

        if (!$item instanceof ShowcaseItem) {
            return null;
        }

        // Stamp the entry so --purge can find it again. Written straight onto the
        // data bag: the Field API only manages keys backed by a definition and
        // leaves everything else alone, so this survives later edits.
        $item->setFieldableData($item->getFieldableData() + [DemoContent::MARKER_KEY => true]);
        $this->entityManager->flush();

        // A demo set where nothing is promoted never shows the "featured" strip.
        if ($index % 5 === 0) {
            $this->itemManager->setFeatured($item, true);
        }

        return $item;
    }

    /**
     * Generates and attaches gallery images, first one becoming the cover.
     */
    private function attachImages(ShowcaseItem $item, int $count): int
    {
        $assets = $this->images->createSet($item->getTitle(), $count);

        if ($assets === []) {
            return 0;
        }

        $weight = 0;

        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $assetId = $asset->getId();

            if ($assetId === null) {
                continue;
            }

            $media = new ShowcaseItemMedia($item, $assetId);
            $media->setWeight($weight);
            $item->addMedia($media);
            $this->entityManager->persist($media);

            if ($weight === 0) {
                $item->setCoverAssetId($assetId);
            }

            $weight += 10;
        }

        $this->entityManager->flush();

        return $item->getMedia()->count();
    }

    /**
     * Removes entries this command created, recognised by the marker stamped on
     * their data bag, so an operator's own entries are never touched.
     */
    private function purge(): int
    {
        $removed = 0;

        foreach ($this->items->findAll() as $item) {
            if (!$item instanceof ShowcaseItem || $item->getDataValue(DemoContent::MARKER_KEY) !== true) {
                continue;
            }

            $this->itemManager->purgeItem($item);
            ++$removed;
        }

        return $removed;
    }

    private function slugify(string $value): string
    {
        $map = ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u'];
        $value = strtr(mb_strtolower($value, 'UTF-8'), $map);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($slug, '-');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\Repository\FieldDefinitionRepository;
use App\Core\Localization\LocaleProvider;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\Repository\VocabularyRepository;
use App\Core\Taxonomy\VocabularyRegistry;
use App\Core\Taxonomy\VocabularySeeder;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Entity\ShowcaseTypeTranslation;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;

/**
 * Creates and edits showcase types — the operation that makes this module
 * general-purpose rather than a fixed product catalogue.
 *
 * Creating a type does three things at once: it writes the type row, it opens a
 * Field API bundle so the owner can attach any fields they like, and (optionally)
 * it seeds a dedicated taxonomy vocabulary so this type's categories never mix
 * with another type's.
 */
final class ShowcaseTypeManager
{
    /**
     * Machine names an operator must not claim, because they would collide with
     * the module's own routes or with a reserved query parameter.
     */
    private const RESERVED = ['new', 'edit', 'delete', 'create', 'admin', 'api', 'search', 'all', 'me'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcaseItemRepository $items,
        private readonly VocabularySeeder $vocabularySeeder,
        private readonly VocabularyRepository $vocabularies,
        private readonly VocabularyRegistry $vocabularyRegistry,
        private readonly FieldDefinitionRepository $fieldDefinitions,
        private readonly FieldDefinitionRegistry $fieldRegistry,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public static function isValidMachineName(string $machineName): bool
    {
        return preg_match(ShowcaseType::MACHINE_NAME_PATTERN, $machineName) === 1
            && !\in_array($machineName, self::RESERVED, true);
    }

    /**
     * @param array<string, array{label: string, description: string}> $labels        locale => text
     * @param array<string, bool>                                      $features
     *
     * @return array{type: ?ShowcaseType, error: ?string}
     */
    public function create(string $machineName, array $labels, array $features, string $icon, bool $withVocabulary): array
    {
        $machineName = strtolower(trim($machineName));

        if (!self::isValidMachineName($machineName)) {
            return ['type' => null, 'error' => 'showcase.types.error.bad_machine_name'];
        }

        if ($this->types->findOneByMachineName($machineName) !== null) {
            return ['type' => null, 'error' => 'showcase.types.error.duplicate'];
        }

        // Validated before anything is handed to the entity manager: a rejected
        // submission must not leave half-built rows scheduled in the unit of work.
        if (!$this->hasAnyLabel($labels)) {
            return ['type' => null, 'error' => 'showcase.types.error.label_required'];
        }

        $type = new ShowcaseType($machineName);
        $type->setIcon($icon);
        $type->setSettings(['features' => $this->normalizeFeatures($features)]);

        // The type is persisted BEFORE its translations exist. applyLabels()
        // attaches ShowcaseTypeTranslation rows whose ManyToOne points back here,
        // so the owning side has to be known to the entity manager first —
        // otherwise the next flush walks that association, finds an unmanaged
        // ShowcaseType and refuses to guess whether to insert it.
        $this->entityManager->persist($type);
        $this->applyLabels($type, $labels);
        $this->entityManager->flush();

        // Only now, on a settled unit of work: VocabularySeeder flushes on its
        // own, and that flush must not be the one that first meets this type.
        if ($withVocabulary) {
            $type->setVocabulary($this->ensureVocabulary($type));
            $this->entityManager->flush();
        }

        return ['type' => $type, 'error' => null];
    }

    /**
     * The machine name is deliberately NOT editable: it keys the Field API bundle
     * and the taxonomy vocabulary, so renaming it would orphan every field
     * definition and every category attached to the type.
     *
     * @param array<string, array{label: string, description: string}> $labels
     * @param array<string, bool>                                      $features
     *
     * @return array{success: bool, error: ?string}
     */
    public function update(ShowcaseType $type, array $labels, array $features, string $icon, bool $enabled, int $weight, bool $withVocabulary): array
    {
        // Checked up front for the same reason as in create(): applyLabels()
        // removes translation rows for emptied locales, and bailing out after
        // that would leave those removals scheduled against a type the caller
        // was told had not changed.
        if (!$this->hasAnyLabel($labels)) {
            return ['success' => false, 'error' => 'showcase.types.error.label_required'];
        }

        $type->setIcon($icon);
        $type->setEnabled($enabled);
        $type->setWeight($weight);

        // Merge rather than replace: settings may carry keys this form does not
        // show, and a future setting must not be wiped by an older edit screen.
        $settings = $type->getSettings();
        $settings['features'] = $this->normalizeFeatures($features);
        $type->setSettings($settings);

        $this->applyLabels($type, $labels);
        $this->entityManager->flush();

        if ($withVocabulary && $type->getVocabulary() === null) {
            $type->setVocabulary($this->ensureVocabulary($type));
            $this->entityManager->flush();
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Refuses while items still reference the type: dropping it would either
     * cascade real user content away or leave a foreign key dangling. The
     * operator disables the type instead, which hides it everywhere without
     * destroying anything.
     *
     * @return array{success: bool, error: ?string}
     */
    public function delete(ShowcaseType $type): array
    {
        if ($this->items->countForType($type) > 0) {
            return ['success' => false, 'error' => 'showcase.types.error.in_use'];
        }

        $bundle = $type->fieldBundle();

        foreach ($this->fieldDefinitions->findByBundle($bundle) as $definition) {
            $this->entityManager->remove($definition);
        }

        // No index rows to clean: the guard above refuses to delete a type that
        // still holds items, and index rows only exist for items.
        $this->entityManager->remove($type);
        $this->entityManager->flush();

        $this->fieldRegistry->invalidate($bundle);

        // The vocabulary is left in place on purpose: its terms may be referenced
        // from elsewhere, and /aacp/taxonomy is the right screen to remove it.

        return ['success' => true, 'error' => null];
    }

    /**
     * Creates the type's category vocabulary if it does not exist yet and returns
     * its machine name. Idempotent — VocabularySeeder never overwrites.
     */
    public function ensureVocabulary(ShowcaseType $type): string
    {
        $machineName = mb_substr('showcase_'.$type->getMachineName(), 0, 63);

        if (preg_match(Vocabulary::MACHINE_NAME_PATTERN, $machineName) !== 1) {
            return $machineName;
        }

        $this->vocabularySeeder->ensure([[
            'machine_name' => $machineName,
            'label' => $type->label($this->localeProvider->getDefaultCode()),
            'hierarchical' => true,
            'weight' => 50,
        ]]);

        $this->vocabularyRegistry->invalidate();

        return $machineName;
    }

    public function vocabularyOf(ShowcaseType $type): ?Vocabulary
    {
        $machineName = $type->getVocabulary();

        return $machineName !== null ? $this->vocabularies->findOneByMachineName($machineName) : null;
    }

    /**
     * @param array<string, array{label: string, description: string}> $labels
     */
    private function applyLabels(ShowcaseType $type, array $labels): void
    {
        $supported = $this->localeProvider->getCodes();

        foreach ($labels as $locale => $texts) {
            if (!\in_array($locale, $supported, true)) {
                continue;
            }

            $label = trim((string) ($texts['label'] ?? ''));
            $description = (string) ($texts['description'] ?? '');
            $existing = $type->getTranslation($locale);

            if ($label === '') {
                // An emptied label removes that locale rather than storing a blank
                // one, so ShowcaseType::label() can fall back to a filled sibling.
                if ($existing !== null) {
                    $type->removeTranslation($existing);
                    $this->entityManager->remove($existing);
                }

                continue;
            }

            if ($existing === null) {
                $existing = new ShowcaseTypeTranslation($type, $locale, $label);
                $type->addTranslation($existing);
                $this->entityManager->persist($existing);
            }

            $existing->setLabel($label);
            $existing->setDescription($description);
        }
    }

    /**
     * Does the submission carry a usable name in at least one supported locale?
     *
     * Reads the RAW input rather than the entity, so it can answer before any
     * translation row is built — which is what lets create() and update() reject
     * a submission without having touched the entity manager. The locale filter
     * matches applyLabels(), so a label for a locale the site does not run
     * cannot make an otherwise empty submission look valid.
     *
     * @param array<string, array{label: string, description: string}> $labels
     */
    private function hasAnyLabel(array $labels): bool
    {
        $supported = $this->localeProvider->getCodes();

        foreach ($labels as $locale => $texts) {
            if (!\in_array($locale, $supported, true)) {
                continue;
            }

            if (trim((string) ($texts['label'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $features
     *
     * @return array<string, bool>
     */
    private function normalizeFeatures(array $features): array
    {
        $clean = [];
        foreach (ShowcaseType::FEATURES as $name => $default) {
            $clean[$name] = \array_key_exists($name, $features) ? (bool) $features[$name] : $default;
        }

        return $clean;
    }
}

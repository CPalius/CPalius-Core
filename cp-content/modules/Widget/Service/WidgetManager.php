<?php

declare(strict_types=1);

namespace Modules\Widget\Service;

use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Widget\WidgetField;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Widget\Entity\WidgetInstance;
use Modules\Widget\Repository\WidgetInstanceRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything that changes widgets: adding one, applying a ready-made arrangement, copying, ordering,
 * deleting, and the one-time seeding of a new area. Callers (the Studio controller, the update hook)
 * stay thin, and every change ends by dropping the public page cache so a sidebar edit shows at once.
 */
final class WidgetManager
{
    private const SEEDED_KEY = 'widget.seeded.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WidgetInstanceRepository $widgets,
        private readonly WidgetRegistry $registry,
        private readonly WidgetFieldSanitizer $sanitizer,
        private readonly SettingRepository $settingRepository,
        private readonly OriginCachePurger $purger,
        private readonly LocaleProvider $locales,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The title to show for a language: what the admin typed for it, else the default language's, else
     * any language's (so a widget titled in one language only shows that title everywhere); with nothing
     * typed, the default title (a translation key) in this language.
     */
    public function titleFor(WidgetInstance $widget, string $locale): string
    {
        $titles = $widget->getTitles();
        if ($titles !== []) {
            return trim(WidgetField::localized($titles, $locale, $this->locales->getDefaultCode()));
        }
        $key = trim($widget->getTitle());

        return $key === '' ? '' : trim($this->translator->trans($key, [], null, $locale));
    }

    /**
     * Title text per language as the editor shows it: what was typed, or the default title translated
     * into each language.
     *
     * @return array<string, string>
     */
    public function titlesForForm(WidgetInstance $widget): array
    {
        $typed = $widget->getTitles();
        $key = trim($widget->getTitle());
        $form = [];
        foreach ($this->locales->getCodes() as $code) {
            $form[$code] = $typed !== [] ? ($typed[$code] ?? '') : ($key === '' ? '' : $this->translator->trans($key, [], null, $code));
        }

        return $form;
    }

    /**
     * Saves the titles an editor posted. Posting the default title untouched in every language keeps it
     * a translation key (so it stays right when a language is added); anything else is stored as typed.
     *
     * @param array<string, mixed> $posted language => title
     */
    public function setPostedTitles(WidgetInstance $widget, array $posted): void
    {
        $codes = $this->locales->getCodes();
        $titles = [];
        foreach ($codes as $code) {
            $text = $posted[$code] ?? '';
            $titles[$code] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags(\is_string($text) ? $text : ''))), 0, 255);
        }

        $key = trim($widget->getTitle());
        if ($key !== '' && $widget->getTitles() === [] && $titles === $this->titlesForForm($widget)) {
            return;
        }

        $widget->setTitles($titles);
        // The key stays only while nothing was typed; typing (or clearing everything) replaces it.
        $widget->setTitle('');
    }

    /**
     * Adds a widget of a known type to a known area, at the end. Settings not given take the type's
     * defaults; the title defaults to the type's name.
     *
     * @param array<string, mixed> $settings overrides, validated like a form post
     */
    public function create(string $area, string $typeId, ?string $title = null, array $settings = []): ?WidgetInstance
    {
        $type = $this->registry->type($typeId);
        if ($type === null || $this->registry->area($area) === null) {
            return null;
        }

        $posted = $settings + $this->registry->defaults($type);
        $widget = new WidgetInstance($area, $typeId);
        // A translation key, not its text: the title is translated per visitor language when drawn, so one
        // widget reads right on every language of the site until an admin types a title of their own.
        $widget->setTitle($title ?? $type->label());
        $widget->setSettings($this->sanitizer->sanitize($type->fields(), $posted, $this->locales->getCodes()));
        $widget->setPosition($this->widgets->nextPosition($area));
        $this->entityManager->persist($widget);
        // Flushed here so the next create() in the same batch sees this one when it asks for the next position.
        $this->entityManager->flush();

        return $widget;
    }

    /**
     * Replaces everything in an area with a ready-made arrangement.
     */
    public function applyPreset(string $area, string $presetId): bool
    {
        $preset = $this->registry->preset($area, $presetId);
        if ($preset === null) {
            return false;
        }

        foreach ($this->widgets->findForArea($area) as $existing) {
            $this->entityManager->remove($existing);
        }
        // Remove first, then add: positions restart at 0 for the new arrangement.
        $this->entityManager->flush();

        foreach ($preset->widgets as $row) {
            $this->create($area, $row['type'], $row['title'] ?? null, $row['settings'] ?? []);
        }
        $this->entityManager->flush();
        $this->purger->purgeAll();

        return true;
    }

    public function duplicate(WidgetInstance $source): WidgetInstance
    {
        $copy = new WidgetInstance($source->getArea(), $source->getType());
        $copy->setTitle($source->getTitle());
        $copy->setSettings($source->getSettings());
        $copy->setVisibility($source->getPages(), $source->getAudience(), $source->getCssClass());
        $copy->setPlacement($source->getExtraAreas(), $source->getThemes(), $source->getShowFrom(), $source->getShowUntil());
        $copy->setLocale($source->getLocale());
        $copy->setActive($source->isActive());
        $copy->setPosition($this->widgets->nextPosition($source->getArea()));
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }

    /**
     * Puts an area's widgets in the given id order; ids from another area (or unknown) are ignored, and
     * widgets the list forgot keep their relative order after the listed ones.
     *
     * @param list<int> $orderedIds
     */
    /**
     * The same widget set up again in another area (a different module's), free to be changed there on its own.
     * Refused when the area is unknown or the type is written for other modules only.
     */
    public function copyToArea(WidgetInstance $source, string $areaId): ?WidgetInstance
    {
        $type = $this->registry->type($source->getType());
        if ($type === null || $this->registry->area($areaId) === null) {
            return null;
        }

        $allowed = [];
        foreach ($this->registry->typesByGroupFor($areaId) as $types) {
            foreach ($types as $candidate) {
                $allowed[$candidate->id()] = true;
            }
        }
        if (!isset($allowed[$source->getType()])) {
            return null;
        }

        $copy = new WidgetInstance($areaId, $source->getType());
        $copy->setTitle($source->getTitle());
        $copy->setSettings($source->getSettings());
        $copy->setVisibility($source->getPages(), $source->getAudience(), $source->getCssClass());
        $copy->setPlacement([], $source->getThemes(), $source->getShowFrom(), $source->getShowUntil());
        $copy->setLocale($source->getLocale());
        $copy->setActive($source->isActive());
        $copy->setPosition($this->widgets->nextPosition($areaId));
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }

    public function reorder(string $area, array $orderedIds): void
    {
        $byId = [];
        foreach ($this->widgets->findForArea($area) as $widget) {
            $byId[(int) $widget->getId()] = $widget;
        }

        $position = 0;
        foreach ($orderedIds as $id) {
            if (isset($byId[$id])) {
                $byId[$id]->setPosition($position++);
                unset($byId[$id]);
            }
        }
        foreach ($byId as $leftover) {
            $leftover->setPosition($position++);
        }
    }

    /**
     * Gives every declared area that has never had widgets its default arrangement.
     *
     * @return int widgets created
     */
    public function seedMissing(): int
    {
        $created = 0;
        foreach (array_keys($this->registry->areas()) as $areaId) {
            $created += $this->seedArea($areaId, false);
        }

        if ($created > 0) {
            $this->purger->purgeAll();
        }

        return $created;
    }

    /**
     * Seeds one area with its default arrangement, once. Idempotent: a marker is stored, so an admin who
     * deletes every widget is not given them back. Called when an area is first drawn or first opened in
     * Studio, which is how a site that had the old fixed blog sidebar keeps a sidebar after updating.
     *
     * @return int widgets created
     */
    public function seedArea(string $areaId, bool $purge = true): int
    {
        $area = $this->registry->area($areaId);
        if ($area === null || $this->isSeeded($areaId)) {
            return 0;
        }

        $created = 0;
        if ($this->widgets->countForArea($areaId) === 0) {
            $preset = $this->registry->preset($areaId, $area->defaultPreset);
            foreach ($preset?->widgets ?? [] as $row) {
                if ($this->create($areaId, $row['type'], $row['title'] ?? null, $row['settings'] ?? []) !== null) {
                    ++$created;
                }
            }
        }
        $this->markSeeded($areaId);

        if ($created > 0 && $purge) {
            $this->purger->purgeAll();
        }

        return $created;
    }

    public function save(): void
    {
        $this->entityManager->flush();
        $this->purger->purgeAll();
    }

    public function delete(WidgetInstance $widget): void
    {
        $this->entityManager->remove($widget);
        $this->save();
    }

    private function isSeeded(string $area): bool
    {
        return $this->settingRepository->findOneBy(['settingKey' => self::SEEDED_KEY.$area]) instanceof Setting;
    }

    private function markSeeded(string $area): void
    {
        $setting = new Setting(self::SEEDED_KEY.$area, 'widget');
        $setting->setSettingValue('1');
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }
}

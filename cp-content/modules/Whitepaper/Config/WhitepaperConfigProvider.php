<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Config;

use App\Core\Config\ConfigProviderInterface;
use Modules\Whitepaper\Entity\WhitepaperDocument;
use Modules\Whitepaper\Entity\WhitepaperSection;
use Modules\Whitepaper\Repository\WhitepaperDocumentRepository;
use Modules\Whitepaper\Repository\WhitepaperSectionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One document per locale: whitepaper.{locale}.
 *
 * This provider is what makes an editable whitepaper deployable. The chapters
 * live in the database so an operator can edit them from AACP without touching
 * a PHP file; they are exported to cp-content/config/sync/ so they still
 * travel in git, review as a diff, and reach production through the
 * `cp:config import` step that `cp:update` already runs. Without it, the
 * document would be written on somebody's laptop and production would serve an
 * empty page.
 *
 * Unlike TaxonomyConfigProvider, import DOES delete chapters that are missing
 * from the incoming file. The two cases are not alike: a vocabulary's terms are
 * separate content that a cascade would destroy, whereas the chapters ARE the
 * document and one file carries all of them. Removing a chapter from the file
 * is therefore an instruction, not an omission.
 *
 * The hazard that buys is real and worth stating: a chapter added in AACP and
 * never exported is removed by the next import. diffDocument() prints every
 * such deletion with a leading "-" so `cp:config import` shows it before
 * anything is applied.
 */
final class WhitepaperConfigProvider implements ConfigProviderInterface
{
    private const PREFIX = 'whitepaper.';

    /** Matches the locale segment only; anything else is not ours. */
    private const LOCALE_PATTERN = '/^[a-z]{2}(_[A-Za-z0-9]{2,8})?$/';

    public function __construct(
        private readonly WhitepaperDocumentRepository $documents,
        private readonly WhitepaperSectionRepository $sections,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function documents(): array
    {
        // A locale with chapters but no header (or the reverse) is still a
        // document worth exporting; exporting only the intersection would
        // silently drop half-finished work.
        $locales = array_unique([...$this->documents->locales(), ...$this->sections->locales()]);
        sort($locales);

        return array_map(static fn (string $locale): string => self::PREFIX.$locale, $locales);
    }

    public function ownsDocument(string $name): bool
    {
        return $this->localeOf($name) !== '';
    }

    public function exportDocument(string $name): array
    {
        $locale = $this->localeOf($name);
        if ($locale === '') {
            return [];
        }

        $document = $this->documents->findOneByLocale($locale);

        $export = [
            'title' => $document?->getTitle() ?? '',
            'version' => $document?->getVersion() ?? '',
            'intro_html' => $document?->getIntroHtml() ?? '',
            'sections' => [],
        ];

        foreach ($this->sections->orderedFor($locale) as $section) {
            $export['sections'][] = [
                'slug' => $section->getSlug(),
                'title' => $section->getTitle(),
                'weight' => $section->getWeight(),
                'body_html' => $section->getBodyHtml(),
            ];
        }

        return $export;
    }

    public function diffDocument(string $name, array $incoming): array
    {
        $locale = $this->localeOf($name);
        if ($locale === '') {
            return [];
        }

        $live = $this->exportDocument($name);
        $normalized = $this->normalizeIncoming($incoming);

        $changes = [];

        foreach (['title', 'version', 'intro_html'] as $field) {
            if (($live[$field] ?? '') !== $normalized[$field]) {
                $changes[] = sprintf('~ whitepaper.%s %s', $locale, $field);
            }
        }

        $liveSections = $this->bySlug($live['sections']);
        $incomingSections = $this->bySlug($normalized['sections']);

        foreach ($incomingSections as $slug => $section) {
            if (!isset($liveSections[$slug])) {
                $changes[] = sprintf('+ whitepaper.%s/%s', $locale, $slug);
            } elseif ($liveSections[$slug] !== $section) {
                $changes[] = sprintf('~ whitepaper.%s/%s', $locale, $slug);
            }
        }

        foreach ($liveSections as $slug => $_) {
            if (!isset($incomingSections[$slug])) {
                $changes[] = sprintf('- whitepaper.%s/%s', $locale, $slug);
            }
        }

        return $changes;
    }

    public function importDocument(string $name, array $incoming): array
    {
        $locale = $this->localeOf($name);
        if ($locale === '') {
            return [];
        }

        $normalized = $this->normalizeIncoming($incoming);
        $applied = [];

        $document = $this->documents->findOneByLocale($locale);
        if ($document === null) {
            $document = new WhitepaperDocument($locale, $normalized['title']);
            $this->entityManager->persist($document);
            $applied[] = sprintf('created whitepaper.%s', $locale);
        } else {
            $applied[] = sprintf('updated whitepaper.%s', $locale);
        }

        $document
            ->setTitle($normalized['title'])
            ->setVersion($normalized['version'])
            ->setIntroHtml($normalized['intro_html']);

        $live = [];
        foreach ($this->sections->orderedFor($locale) as $section) {
            $live[$section->getSlug()] = $section;
        }

        foreach ($normalized['sections'] as $row) {
            $slug = $row['slug'];
            if ($slug === '') {
                continue;
            }

            $section = $live[$slug] ?? null;
            if ($section === null) {
                $section = new WhitepaperSection($locale, $slug, $row['title']);
                $this->entityManager->persist($section);
                $applied[] = sprintf('created whitepaper.%s/%s', $locale, $slug);
            } else {
                unset($live[$slug]);
                $applied[] = sprintf('updated whitepaper.%s/%s', $locale, $slug);
            }

            $section
                ->setTitle($row['title'])
                ->setWeight($row['weight'])
                ->setBodyHtml($row['body_html']);
        }

        // Whatever is still in $live was not in the file: the file is the
        // document, so these chapters were deleted deliberately.
        foreach ($live as $slug => $section) {
            $this->entityManager->remove($section);
            $applied[] = sprintf('removed whitepaper.%s/%s', $locale, $slug);
        }

        return $applied;
    }

    public function afterImport(): void
    {
        // Nothing cached: WhitepaperContent reads the repositories per request.
    }

    private function localeOf(string $document): string
    {
        if (!str_starts_with($document, self::PREFIX)) {
            return '';
        }

        $locale = substr($document, \strlen(self::PREFIX));

        return preg_match(self::LOCALE_PATTERN, $locale) === 1 ? $locale : '';
    }

    /**
     * @param list<array{slug: string, title: string, weight: int, body_html: string}> $sections
     *
     * @return array<string, array{slug: string, title: string, weight: int, body_html: string}>
     */
    private function bySlug(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            $out[$section['slug']] = $section;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $incoming
     *
     * @return array{title: string, version: string, intro_html: string, sections: list<array{slug: string, title: string, weight: int, body_html: string}>}
     */
    private function normalizeIncoming(array $incoming): array
    {
        $sections = [];
        $raw = $incoming['sections'] ?? [];

        if (\is_array($raw)) {
            foreach ($raw as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $sections[] = [
                    'slug' => (string) ($row['slug'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                    'weight' => (int) ($row['weight'] ?? 0),
                    'body_html' => (string) ($row['body_html'] ?? ''),
                ];
            }
        }

        // Exported in render order, so a reordering shows up as a real diff
        // rather than as noise from however the file happened to be written.
        usort($sections, static fn (array $a, array $b): int => [$a['weight'], $a['slug']] <=> [$b['weight'], $b['slug']]);

        return [
            'title' => (string) ($incoming['title'] ?? ''),
            'version' => (string) ($incoming['version'] ?? ''),
            'intro_html' => (string) ($incoming['intro_html'] ?? ''),
            'sections' => $sections,
        ];
    }
}

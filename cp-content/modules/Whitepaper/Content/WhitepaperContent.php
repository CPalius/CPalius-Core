<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Content;

use Modules\Whitepaper\Repository\WhitepaperDocumentRepository;
use Modules\Whitepaper\Repository\WhitepaperSectionRepository;

/**
 * Reads the public whitepaper for one locale.
 *
 * This class used to BE the whitepaper: 842 lines of PHP heredocs holding two
 * languages of HTML, editable only in an IDE and only by someone who could
 * deploy. The body now lives in WhitepaperDocument / WhitepaperSection, which
 * #[CpResource] turns into AACP screens, and ships in git through
 * WhitepaperConfigProvider.
 *
 * The returned shape is deliberately unchanged, so ThemeController and the
 * theme template did not have to be touched when the storage moved.
 */
final class WhitepaperContent
{
    public function __construct(
        private readonly WhitepaperDocumentRepository $documents,
        private readonly WhitepaperSectionRepository $sections,
    ) {
    }

    /**
     * @return array{title: string, intro_html: string, version: string, sections: list<array{id: string, title: string, html: string}>}
     */
    public function forLocale(string $locale): array
    {
        $resolved = $this->resolveLocale($locale);

        if ($resolved === null) {
            // Nothing installed yet (a fresh database before `cp:config
            // import`). An empty document renders an empty page, which is
            // honest; inventing placeholder prose would be worse.
            return ['title' => '', 'intro_html' => '', 'version' => '', 'sections' => []];
        }

        $document = $this->documents->findOneByLocale($resolved);

        $sections = [];
        foreach ($this->sections->orderedFor($resolved) as $section) {
            $sections[] = [
                'id' => $section->getSlug(),
                'title' => $section->getTitle(),
                'html' => $section->getBodyHtml(),
            ];
        }

        return [
            'title' => $document?->getTitle() ?? '',
            'intro_html' => $document?->getIntroHtml() ?? '',
            'version' => $document?->getVersion() ?? '',
            'sections' => $sections,
        ];
    }

    /**
     * The requested locale when it has chapters, otherwise the first locale
     * that does.
     *
     * A reader who opens /tr/whitepaper on an installation that only carries
     * the English original is better served the English text than a blank
     * page: this is one versioned technical document, not a localised site
     * where the wrong language would mislead. Returns null when no locale has
     * anything at all.
     */
    private function resolveLocale(string $requested): ?string
    {
        $available = $this->sections->locales();

        if ($available === []) {
            return null;
        }

        return \in_array($requested, $available, true) ? $requested : $available[0];
    }
}

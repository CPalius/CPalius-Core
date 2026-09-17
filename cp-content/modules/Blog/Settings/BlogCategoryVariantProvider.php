<?php

declare(strict_types=1);

namespace Modules\Blog\Settings;

use App\Core\Settings\SettingVariantProviderInterface;
use App\Core\Taxonomy\Entity\Term;
use App\Repository\CategoryRepository;

/**
 * Fills the category dropdown of blog.related_category.
 *
 * The category list lives in the database, so it cannot be a compiled
 * #[CpSetting] variant. Terms are listed across every locale with the locale
 * shown next to the name, matching what findAllSorted() is already used for on
 * the category admin screen — a site with a Turkish and an English "Mimari"
 * would otherwise offer two identical-looking options.
 */
final class BlogCategoryVariantProvider implements SettingVariantProviderInterface
{
    private const KEY = 'blog.related_category';

    public function __construct(
        private readonly CategoryRepository $categoryRepository,
    ) {
    }

    public function supports(string $key): bool
    {
        return $key === self::KEY;
    }

    public function variants(string $key): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        // The empty option has to exist: "fixed category" with nothing chosen
        // yet must be a state the form can show, not an invisible first entry
        // that silently picks a category nobody selected.
        $variants = ['' => 'blog.settings.related_category.none'];

        foreach ($this->categoryRepository->findAllSorted() as $term) {
            $id = $term->getId();

            if ($id === null) {
                continue;
            }

            $variants[(string) $id] = $this->label($term);
        }

        return $variants;
    }

    private function label(Term $term): string
    {
        // Braces are stripped because the settings screen runs option labels
        // through the translator, whose catalogue is messages+intl-icu: an
        // unbalanced "{" in a category name would throw an ICU syntax error and
        // take the whole screen down rather than mislabel one dropdown entry.
        $name = str_replace(['{', '}'], '', $term->getName());
        $locale = trim((string) $term->getLocale());

        return $locale !== '' ? sprintf('%s (%s)', $name, strtoupper($locale)) : $name;
    }
}

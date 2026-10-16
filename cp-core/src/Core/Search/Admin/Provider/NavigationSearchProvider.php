<?php

declare(strict_types=1);

namespace App\Core\Search\Admin\Provider;

use App\Core\Menu\Twig\AdminMenuRuntime;
use App\Core\Search\Admin\AdminSearchProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Finds sidebar screens by their label, so any page of the panel is two keystrokes away.
 * Uses the rendered menu tree, so module and capability filtering already applied.
 */
final class NavigationSearchProvider implements AdminSearchProviderInterface
{
    public function __construct(
        private readonly AdminMenuRuntime $menu,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function search(string $term, string $panel, int $limit): ?array
    {
        $needle = mb_strtolower($term);
        $hits = [];

        foreach ($this->menu->render($panel) as $item) {
            $parentLabel = $this->translator->trans($item['label']);
            $entries = [['label' => $parentLabel, 'route' => $item['routeName'], 'subtitle' => null]];

            foreach ($item['children'] as $child) {
                $entries[] = ['label' => $this->translator->trans($child['label']), 'route' => $child['routeName'], 'subtitle' => $parentLabel];
            }

            foreach ($entries as $entry) {
                if (!str_contains(mb_strtolower($entry['label']), $needle)) {
                    continue;
                }

                try {
                    $url = $this->urls->generate($entry['route']);
                } catch (\Throwable) {
                    // Routes that need parameters cannot be linked from a search hit.
                    continue;
                }

                $hits[] = ['title' => $entry['label'], 'url' => $url, 'subtitle' => $entry['subtitle']];

                if (\count($hits) >= $limit) {
                    break 2;
                }
            }
        }

        return ['key' => 'navigation', 'label' => $this->translator->trans('admin.search.group.navigation'), 'icon' => 'tabler:compass', 'hits' => $hits];
    }
}

<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Localization\LocaleProvider;
use Modules\DnsTools\Catalog\PresentedTool;
use Modules\DnsTools\Catalog\ToolCatalog;
use Modules\DnsTools\Catalog\ToolDefinition;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ToolRegistry
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly ToolConfigStore $config,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $locales,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<PresentedTool>
     */
    public function all(bool $onlyEnabled = true): array
    {
        $out = [];
        foreach ($this->catalog->all() as $tool) {
            $presented = $this->present($tool);
            if ($onlyEnabled && !$presented->enabled) {
                continue;
            }
            $out[] = $presented;
        }

        return $out;
    }

    public function get(string $slug, bool $onlyEnabled = true): ?PresentedTool
    {
        $tool = $this->catalog->get($slug);
        if (!$tool instanceof ToolDefinition) {
            return null;
        }
        $presented = $this->present($tool);
        if ($onlyEnabled && !$presented->enabled) {
            return null;
        }

        return $presented;
    }

    /**
     * @return list<PresentedTool>
     */
    public function featured(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (PresentedTool $tool): bool => $tool->featured,
        ));
    }

    /**
     * @return list<PresentedTool>
     */
    public function byCategory(string $category): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (PresentedTool $tool): bool => $tool->category === $category,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function categories(): array
    {
        return $this->catalog->categories();
    }

    public function present(ToolDefinition $tool, ?string $locale = null): PresentedTool
    {
        $locale ??= $this->currentLocale();
        $row = $this->config->tool($tool->slug, $tool);
        $copy = \is_array($row['locales'][$locale] ?? null) ? $row['locales'][$locale] : [];

        return PresentedTool::fromDefinition(
            $tool,
            $row['enabled'],
            $row['featured'],
            $row['is_new'],
            $this->field($copy, 'title', $tool->titleKey(), $locale),
            $this->field($copy, 'subtitle', $tool->subtitleKey(), $locale),
            $this->field($copy, 'description', $tool->descriptionKey(), $locale),
            $this->field($copy, 'keywords', $tool->keywordsKey(), $locale),
        );
    }

    public function pageCopy(string $page, string $fallbackPrefix, ?string $locale = null): array
    {
        $locale ??= $this->currentLocale();
        $override = $this->config->page($page, $locale);

        return [
            'title' => $override['title'] !== '' ? $override['title'] : $this->translator->trans($fallbackPrefix.'.title', [], null, $locale),
            'description' => $override['description'] !== '' ? $override['description'] : $this->translator->trans($fallbackPrefix.'.description', [], null, $locale),
            'keywords' => $override['keywords'] !== '' ? $override['keywords'] : $this->translator->trans($fallbackPrefix.'.keywords', [], null, $locale),
        ];
    }

    private function field(array $copy, string $key, string $fallbackKey, string $locale): string
    {
        $value = trim((string) ($copy[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }

        return $this->translator->trans($fallbackKey, [], null, $locale);
    }

    private function currentLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->getLocale() !== '') {
            return $request->getLocale();
        }

        return $this->locales->getDefaultCode();
    }
}

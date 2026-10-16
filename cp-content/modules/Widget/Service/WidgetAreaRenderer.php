<?php

declare(strict_types=1);

namespace Modules\Widget\Service;

use App\Core\Localization\LocaleProvider;
use App\Core\Theme\ThemeRegistry;
use App\Core\Widget\WidgetAreaRendererInterface;
use App\Core\Widget\WidgetRenderContext;
use Modules\Widget\Entity\WidgetInstance;
use Modules\Widget\Repository\WidgetInstanceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Draws an area for the public site: the active widgets in order, minus the ones whose language, page or
 * audience rules say not here, each wrapped in the same markup.
 *
 * One broken widget (a type that throws, a type whose module was switched off) is skipped and logged;
 * the rest of the sidebar still renders.
 */
final class WidgetAreaRenderer implements WidgetAreaRendererInterface
{
    public function __construct(
        private readonly WidgetInstanceRepository $widgets,
        private readonly WidgetRegistry $registry,
        private readonly WidgetFieldSanitizer $fields,
        private readonly WidgetManager $manager,
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly LocaleProvider $locales,
        private readonly ThemeRegistry $themes,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context page (string), subject (object|null), locale (string|null)
     */
    public function render(string $area, array $context = []): string
    {
        $locale = \is_string($context['locale'] ?? null) && $context['locale'] !== ''
            ? $context['locale']
            : (string) $this->requests->getCurrentRequest()?->getLocale();
        $page = \is_string($context['page'] ?? null) ? $context['page'] : 'other';
        $subject = \is_object($context['subject'] ?? null) ? $context['subject'] : null;
        $theme = $this->themes->activeDirName();
        $renderContext = new WidgetRenderContext($locale, $page, $subject, $this->registry->area($area)->module ?? '', $theme);
        $isMember = $this->security->getUser() !== null;

        $rows = $this->drawnIn($area);
        if ($rows === [] && $this->manager->seedArea($area, false) > 0) {
            // First time this area is drawn: it just received its default widgets.
            $rows = $this->drawnIn($area);
        }

        $html = '';
        foreach ($rows as $widget) {
            if (!$this->visible($widget, $locale, $page, $isMember, $theme)) {
                continue;
            }

            $type = $this->registry->type($widget->getType());
            if ($type === null) {
                // Its module is off or gone; the row is kept so switching the module back on restores it.
                continue;
            }

            try {
                $body = $type->render($this->fields->localize($type->fields(), $this->fields->withDefaults($type->fields(), $widget->getSettings()), $locale, $this->locales->getDefaultCode()), $renderContext);
            } catch (\Throwable $e) {
                $this->logger->warning('Widget skipped: render failed.', ['id' => $widget->getId(), 'type' => $widget->getType(), 'error' => $e->getMessage()]);
                continue;
            }

            if (trim($body) !== '') {
                $html .= $this->wrap($widget, $body, $locale);
            }
        }

        return $html;
    }

    /**
     * @return list<WidgetInstance> the active widgets that belong to this area or were also placed in it
     */
    private function drawnIn(string $area): array
    {
        return array_values(array_filter($this->widgets->findAllActive(), static fn (WidgetInstance $w): bool => $w->drawnIn($area)));
    }

    private function visible(WidgetInstance $widget, string $locale, string $page, bool $isMember, string $theme): bool
    {
        if ($widget->getThemes() !== [] && !\in_array($theme, $widget->getThemes(), true)) {
            return false;
        }

        // Schedule in the server's time zone; both ends are optional.
        $now = date('Y-m-d\TH:i');
        if (($widget->getShowFrom() !== '' && $now < $widget->getShowFrom()) || ($widget->getShowUntil() !== '' && $now > $widget->getShowUntil())) {
            return false;
        }

        if ($widget->getLocale() !== null && $widget->getLocale() !== $locale) {
            return false;
        }

        $pages = $widget->getPages();
        if ($pages !== [] && !\in_array($page, $pages, true)) {
            return false;
        }

        return match ($widget->getAudience()) {
            WidgetInstance::AUDIENCE_GUESTS => !$isMember,
            WidgetInstance::AUDIENCE_MEMBERS => $isMember,
            default => true,
        };
    }

    /**
     * Same box for every widget. "blog-sidebar-widget" is the class the blog theme already styles, so
     * existing sites look the same; "cp-widget--<type>" is there for a theme or a custom CSS class.
     */
    private function wrap(WidgetInstance $widget, string $body, string $locale): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($widget->getType())) ?? 'widget';
        $classes = trim('cp-widget cp-widget--'.$slug.' blog-sidebar-widget '.$widget->getCssClass());
        // Typed per language with fallbacks, or the default title (a translation key) in the visitor's language.
        $title = $this->manager->titleFor($widget, $locale);

        return '<section class="'.htmlspecialchars($classes, \ENT_QUOTES).'" id="cp-widget-'.(int) $widget->getId().'">'
            .($title !== '' ? '<h5 class="cp-widget__title">'.htmlspecialchars($title, \ENT_QUOTES).'</h5>' : '')
            .$body
            .'</section>'."\n";
    }
}

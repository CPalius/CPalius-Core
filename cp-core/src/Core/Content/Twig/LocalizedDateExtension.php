<?php

declare(strict_types=1);

namespace App\Core\Content\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `|cp_date` — a date in the reader's language ("22 Eyl 2026" / "Sep 22, 2026").
 * Twig's own `|date('d M Y')` always prints English month names.
 */
final class LocalizedDateExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('cp_date', $this->format(...)),
        ];
    }

    public function format(?\DateTimeInterface $date, bool $withTime = false): string
    {
        if ($date === null) {
            return '';
        }

        // ponytail: without ext-intl the numeric format is still unambiguous.
        if (!class_exists(\IntlDateFormatter::class)) {
            return $date->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? \Locale::getDefault();
        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::MEDIUM,
            $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE,
            $date->getTimezone(),
        );

        return (string) $formatter->format($date);
    }
}

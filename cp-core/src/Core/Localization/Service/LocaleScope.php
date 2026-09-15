<?php

declare(strict_types=1);

namespace App\Core\Localization\Service;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Runs a callable with the translator temporarily switched to another language.
 *
 * Needed because Twig's `|trans` and every `trans()` without an explicit locale
 * argument read the translator's current locale, and mail is rendered outside
 * the request that would have set it — in a Messenger worker or a cron task
 * there is no request locale at all, and in an admin action it is the admin's.
 * Passing a locale down through every template variable would be the
 * alternative, and it only works until one template forgets.
 *
 * The restore is in `finally`: a template that throws must not leave the rest of
 * the worker's run rendering in the last recipient's language.
 */
final class LocaleScope
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(?string $locale, callable $callback): mixed
    {
        if ($locale === null || $locale === '' || !$this->translator instanceof LocaleAwareInterface) {
            return $callback();
        }

        $previous = $this->translator->getLocale();

        if ($previous === $locale) {
            return $callback();
        }

        $this->translator->setLocale($locale);

        try {
            return $callback();
        } finally {
            $this->translator->setLocale($previous);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Homepage;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the portal partials ask the homepage document for.
 *
 *   hp('hero.desc')                       the operator's text, else the shipped one
 *   hp_url('hero.cta_download@url', url)  the operator's link, else the default; '' hides the button
 *   hp_on('hero.code')                    false once the operator has hidden the element
 *   hp_has('hero.title')                  true when the operator replaced the shipped markup
 *
 * Partials never carry their own copy: a sentence or a link that is not read
 * through here cannot be edited, which is the whole bug this replaces.
 */
final class HomepageTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly HomepageStore $store,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hp', $this->text(...)),
            new TwigFunction('hp_url', $this->url(...)),
            new TwigFunction('hp_on', fn (string $key): bool => !$this->store->hidden($key)),
            new TwigFunction('hp_has', fn (string $key): bool => $this->store->override($key, $this->locale()) !== null),
        ];
    }

    public function text(string $key): string
    {
        $locale = $this->locale();

        return $this->store->override($key, $locale) ?? $this->translator->trans($key, [], 'portal', $locale);
    }

    public function url(string $key, ?string $default): string
    {
        $override = $this->store->override($key, $this->locale());

        return $override ?? (string) $default;
    }

    private function locale(): string
    {
        return $this->requests->getCurrentRequest()?->getLocale() ?? 'tr';
    }
}

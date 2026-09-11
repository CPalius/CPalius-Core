<?php

declare(strict_types=1);

namespace App\Core\Token\Provider;

use App\Core\Settings\SettingsRegistry;
use App\Core\Token\TokenValueProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The only provider that needs no $subject — site tokens read straight from
 * SettingsRegistry (and the current request for [site:url]), so they resolve
 * even with an empty $context.
 */
final class SiteTokenProvider implements TokenValueProviderInterface
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'site';
    }

    public function resolve(string $name, mixed $subject, ?string $arg): ?string
    {
        return match ($name) {
            'name' => (string) $this->settings->get('core.site_name', 'CPalius CMF'),
            'slogan' => (string) $this->settings->get('core.site_description', ''),
            'url' => $this->currentSiteUrl(),
            default => null,
        };
    }

    private function currentSiteUrl(): string
    {
        $request = $this->requestStack?->getCurrentRequest();
        if ($request !== null) {
            return $request->getSchemeAndHttpHost();
        }

        return (string) $this->settings->get('core.site_url', '');
    }
}

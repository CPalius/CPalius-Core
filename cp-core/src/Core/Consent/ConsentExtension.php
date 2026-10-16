<?php

declare(strict_types=1);

namespace App\Core\Consent;

use App\Core\Settings\SettingsRegistry;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ cp_consent_mode() }} (head) and {{ cp_cookie_consent() }} (end of body): the consent banner.
 *
 * Both outputs are identical for every visitor (the choice lives in a client-side cookie), so they
 * are safe in the shared page cache; nothing here reads the visitor's cookie on the server.
 * Fails quiet: a broken banner must never take a page down.
 */
final class ConsentExtension extends AbstractExtension
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_cookie_consent', $this->render(...), ['is_safe' => ['html']]),
            new TwigFunction('cp_consent_mode', $this->renderMode(...), ['is_safe' => ['html']]),
        ];
    }

    /** Google Consent Mode v2 defaults (everything denied until the visitor chooses); put it first in <head>. */
    public function renderMode(): string
    {
        try {
            if (!(bool) $this->settings->get('consent.enabled') || !(bool) $this->settings->get('consent.mode_enabled')) {
                return '';
            }

            return $this->twig->render('consent/mode.html.twig');
        } catch (\Throwable $e) {
            $this->logger->warning('Cookie consent mode skipped.', ['error' => $e->getMessage()]);

            return '';
        }
    }

    public function render(): string
    {
        try {
            if (!(bool) $this->settings->get('consent.enabled')) {
                return '';
            }

            return $this->twig->render('consent/banner.html.twig', [
                'message' => $this->text('consent.message'),
                'accept' => $this->text('consent.accept_label'),
                'reject' => $this->text('consent.reject_label'),
                'preferences' => $this->text('consent.preferences_label'),
                'save' => $this->text('consent.save_label'),
                'privacyLabel' => $this->text('consent.privacy_label'),
                'privacyUrl' => $this->safeUrl($this->text('consent.privacy_url')),
                'categories' => $this->categories(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Cookie consent banner skipped.', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * @return list<array{id: string, locked: bool, label: string, desc: string}>
     */
    private function categories(): array
    {
        $categories = [['id' => 'necessary', 'locked' => true]];
        if ((bool) $this->settings->get('consent.analytics_enabled')) {
            $categories[] = ['id' => 'analytics', 'locked' => false];
        }
        if ((bool) $this->settings->get('consent.marketing_enabled')) {
            $categories[] = ['id' => 'marketing', 'locked' => false];
        }

        return array_map(fn (array $c): array => $c + [
            'label' => $this->text('consent.'.$c['id'].'_label'),
            'desc' => $this->text('consent.'.$c['id'].'_desc'),
        ], $categories);
    }

    private function text(string $key): string
    {
        return trim((string) $this->settings->get($key));
    }

    /** Site-relative paths and http(s) only; anything else (javascript:, data:, //host) is dropped. */
    private function safeUrl(string $url): string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        return preg_match('~^https?://[^\s<>"\']+$~i', $url) === 1 ? $url : '';
    }
}

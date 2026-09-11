<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Google reCAPTCHA (v2/v3), Cloudflare Turnstile, and hCaptcha (security.captcha_*).
 * Comments and other callers use resolveReadyProvider(): selected provider if keys exist,
 * otherwise the first ready provider, otherwise none (math fallback).
 */
final class CaptchaService
{
    public const PROVIDER_NONE = 'none';
    public const PROVIDER_RECAPTCHA = 'recaptcha';
    public const PROVIDER_TURNSTILE = 'turnstile';
    public const PROVIDER_HCAPTCHA = 'hcaptcha';

    /** Detection order when the configured provider is missing keys. */
    private const AUTO_ORDER = [
        self::PROVIDER_TURNSTILE,
        self::PROVIDER_RECAPTCHA,
        self::PROVIDER_HCAPTCHA,
    ];

    private const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const HCAPTCHA_VERIFY_URL = 'https://hcaptcha.com/siteverify';

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    /**
     * Value stored in AACP (may be none even if another provider has keys).
     */
    public function getConfiguredProvider(): string
    {
        $provider = (string) ($this->settingsRegistry->get('security.captcha_provider') ?? self::PROVIDER_NONE);

        return \in_array($provider, self::AUTO_ORDER, true) ? $provider : self::PROVIDER_NONE;
    }

    /**
     * @deprecated Use getConfiguredProvider() or resolveReadyProvider()
     */
    public function getProvider(): string
    {
        return $this->getConfiguredProvider();
    }

    /**
     * Provider that can actually verify: configured if ready, else first ready, else none.
     */
    public function resolveReadyProvider(): string
    {
        $configured = $this->getConfiguredProvider();
        if ($this->isProviderReady($configured)) {
            return $configured;
        }

        foreach (self::AUTO_ORDER as $candidate) {
            if ($this->isProviderReady($candidate)) {
                return $candidate;
            }
        }

        return self::PROVIDER_NONE;
    }

    public function isProviderReady(string $provider): bool
    {
        if ($provider === self::PROVIDER_NONE) {
            return false;
        }

        $keys = $this->providerKeys($provider);

        return $keys['site'] !== '' && $keys['secret'] !== '';
    }

    public function enabledOnLogin(): bool
    {
        return (bool) ($this->settingsRegistry->get('security.captcha_on_login') ?? false)
            && $this->getConfiguredProvider() !== self::PROVIDER_NONE
            && $this->resolveReadyProvider() !== self::PROVIDER_NONE;
    }

    public function enabledOnRegister(): bool
    {
        return (bool) ($this->settingsRegistry->get('security.captcha_on_register') ?? true)
            && $this->getConfiguredProvider() !== self::PROVIDER_NONE
            && $this->resolveReadyProvider() !== self::PROVIDER_NONE;
    }

    public function getResponseFieldName(?string $provider = null): string
    {
        $provider ??= $this->resolveReadyProvider();

        return match ($provider) {
            self::PROVIDER_TURNSTILE => 'cf-turnstile-response',
            self::PROVIDER_HCAPTCHA => 'h-captcha-response',
            default => 'g-recaptcha-response',
        };
    }

    public function verify(string $token, string $remoteIp = ''): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $provider = $this->resolveReadyProvider();

        return match ($provider) {
            self::PROVIDER_RECAPTCHA => $this->verifyRecaptcha($token, $remoteIp),
            self::PROVIDER_TURNSTILE => $this->verifyTurnstile($token, $remoteIp),
            self::PROVIDER_HCAPTCHA => $this->verifyHcaptcha($token, $remoteIp),
            default => false,
        };
    }

    public function verifyRequest(Request $request): bool
    {
        return $this->verify(
            (string) $request->request->get($this->getResponseFieldName(), ''),
            $request->getClientIp() ?? '',
        );
    }

    /**
     * @return array{provider: string, site_key: string, recaptcha_version: string}
     */
    public function getWidgetConfig(): array
    {
        $provider = $this->resolveReadyProvider();
        $keys = $this->providerKeys($provider);

        return [
            'provider' => $provider,
            'site_key' => $keys['site'],
            'recaptcha_version' => $provider === self::PROVIDER_RECAPTCHA
                ? (string) ($this->settingsRegistry->get('security.recaptcha_version') ?? 'v2')
                : 'v2',
        ];
    }

    /**
     * Studio summary of which captcha comments will use.
     *
     * @return array{provider: string, ready: bool, labelKey: string}
     */
    public function describeReadyProvider(): array
    {
        $provider = $this->resolveReadyProvider();

        return [
            'provider' => $provider,
            'ready' => $provider !== self::PROVIDER_NONE,
            'labelKey' => match ($provider) {
                self::PROVIDER_TURNSTILE => 'aacp.system_settings.security.provider_turnstile',
                self::PROVIDER_RECAPTCHA => 'aacp.system_settings.security.provider_recaptcha',
                self::PROVIDER_HCAPTCHA => 'aacp.system_settings.security.provider_hcaptcha',
                default => 'studio.blog.settings.captcha.math_name',
            },
        ];
    }

    /**
     * @return array{site: string, secret: string}
     */
    private function providerKeys(string $provider): array
    {
        return match ($provider) {
            self::PROVIDER_RECAPTCHA => [
                'site' => trim((string) ($this->settingsRegistry->get('security.recaptcha_site_key') ?? '')),
                'secret' => trim((string) ($this->settingsRegistry->get('security.recaptcha_secret_key') ?? '')),
            ],
            self::PROVIDER_TURNSTILE => [
                'site' => trim((string) ($this->settingsRegistry->get('security.turnstile_site_key') ?? '')),
                'secret' => trim((string) ($this->settingsRegistry->get('security.turnstile_secret_key') ?? '')),
            ],
            self::PROVIDER_HCAPTCHA => [
                'site' => trim((string) ($this->settingsRegistry->get('security.hcaptcha_site_key') ?? '')),
                'secret' => trim((string) ($this->settingsRegistry->get('security.hcaptcha_secret_key') ?? '')),
            ],
            default => ['site' => '', 'secret' => ''],
        };
    }

    private function verifyRecaptcha(string $token, string $remoteIp): bool
    {
        $secret = $this->providerKeys(self::PROVIDER_RECAPTCHA)['secret'];
        if ($secret === '') {
            return false;
        }

        $data = $this->decodeVerifyResponse($this->httpPost(self::RECAPTCHA_VERIFY_URL, $this->verifyPayload($secret, $token, $remoteIp)));
        if ($data === null || empty($data['success'])) {
            return false;
        }

        $version = (string) ($this->settingsRegistry->get('security.recaptcha_version') ?? 'v2');
        if ($version === 'v3') {
            $score = (float) ($data['score'] ?? 0);
            $threshold = (float) ($this->settingsRegistry->get('security.recaptcha_score_threshold') ?? '0.5');

            return $score >= $threshold;
        }

        return true;
    }

    private function verifyTurnstile(string $token, string $remoteIp): bool
    {
        $secret = $this->providerKeys(self::PROVIDER_TURNSTILE)['secret'];
        if ($secret === '') {
            return false;
        }

        $data = $this->decodeVerifyResponse($this->httpPost(self::TURNSTILE_VERIFY_URL, $this->verifyPayload($secret, $token, $remoteIp)));

        return $data !== null && !empty($data['success']);
    }

    private function verifyHcaptcha(string $token, string $remoteIp): bool
    {
        $secret = $this->providerKeys(self::PROVIDER_HCAPTCHA)['secret'];
        if ($secret === '') {
            return false;
        }

        $data = $this->decodeVerifyResponse($this->httpPost(self::HCAPTCHA_VERIFY_URL, $this->verifyPayload($secret, $token, $remoteIp)));

        return $data !== null && !empty($data['success']);
    }

    /**
     * @return array<string, string>
     */
    private function verifyPayload(string $secret, string $token, string $remoteIp): array
    {
        $post = [
            'secret' => $secret,
            'response' => $token,
        ];
        if ($remoteIp !== '') {
            $post['remoteip'] = $remoteIp;
        }

        return $post;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeVerifyResponse(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);

        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<string, string> $data
     */
    private function httpPost(string $url, array $data): ?string
    {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
                'timeout' => 10,
            ],
        ];
        $ctx = stream_context_create($opts);
        $result = @file_get_contents($url, false, $ctx);

        return \is_string($result) ? $result : null;
    }
}

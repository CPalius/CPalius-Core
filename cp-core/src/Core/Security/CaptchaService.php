<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Google reCAPTCHA (v2/v3) and Cloudflare Turnstile verification (security.captcha_*).
 */
final class CaptchaService
{
    private const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    public function getProvider(): string
    {
        $provider = (string) ($this->settingsRegistry->get('security.captcha_provider') ?? 'none');

        return $provider === 'recaptcha' || $provider === 'turnstile' ? $provider : 'none';
    }

    public function enabledOnLogin(): bool
    {
        return (bool) ($this->settingsRegistry->get('security.captcha_on_login') ?? false)
            && $this->getProvider() !== 'none';
    }

    public function enabledOnRegister(): bool
    {
        return (bool) ($this->settingsRegistry->get('security.captcha_on_register') ?? true)
            && $this->getProvider() !== 'none';
    }

    public function getResponseFieldName(): string
    {
        return $this->getProvider() === 'turnstile' ? 'cf-turnstile-response' : 'g-recaptcha-response';
    }

    public function verify(string $token, string $remoteIp = ''): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $provider = $this->getProvider();
        if ($provider === 'recaptcha') {
            return $this->verifyRecaptcha($token, $remoteIp);
        }

        if ($provider === 'turnstile') {
            return $this->verifyTurnstile($token, $remoteIp);
        }

        return true;
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
        $provider = $this->getProvider();
        $config = [
            'provider' => $provider,
            'site_key' => '',
            'recaptcha_version' => 'v2',
        ];

        if ($provider === 'recaptcha') {
            $config['site_key'] = (string) ($this->settingsRegistry->get('security.recaptcha_site_key') ?? '');
            $config['recaptcha_version'] = (string) ($this->settingsRegistry->get('security.recaptcha_version') ?? 'v2');
        } elseif ($provider === 'turnstile') {
            $config['site_key'] = (string) ($this->settingsRegistry->get('security.turnstile_site_key') ?? '');
        }

        return $config;
    }

    private function verifyRecaptcha(string $token, string $remoteIp): bool
    {
        $secret = (string) ($this->settingsRegistry->get('security.recaptcha_secret_key') ?? '');
        if ($secret === '') {
            return false;
        }

        $post = [
            'secret' => $secret,
            'response' => $token,
        ];
        if ($remoteIp !== '') {
            $post['remoteip'] = $remoteIp;
        }

        $json = $this->httpPost(self::RECAPTCHA_VERIFY_URL, $post);
        if ($json === null) {
            return false;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['success'])) {
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
        $secret = (string) ($this->settingsRegistry->get('security.turnstile_secret_key') ?? '');
        if ($secret === '') {
            return false;
        }

        $post = [
            'secret' => $secret,
            'response' => $token,
        ];
        if ($remoteIp !== '') {
            $post['remoteip'] = $remoteIp;
        }

        $json = $this->httpPost(self::TURNSTILE_VERIFY_URL, $post);
        if ($json === null) {
            return false;
        }

        $data = json_decode($json, true);

        return is_array($data) && !empty($data['success']);
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

        return is_string($result) ? $result : null;
    }
}

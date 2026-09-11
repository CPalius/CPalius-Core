<?php

declare(strict_types=1);

namespace App\Core\Security\Http;

use App\Core\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the response header set from the security.* settings.
 *
 * CSP has four operator-facing modes instead of a bare on/off switch, because a
 * themable CMS cannot silently enforce a nonce policy over templates it does not
 * own: 'report' ships the strict policy as report-only so violations are visible
 * first, 'balanced' enforces origin isolation while still tolerating inline
 * script, and 'strict' enforces nonce + strict-dynamic.
 */
final class SecurityHeaderPolicy
{
    public const MODE_OFF = 'off';
    public const MODE_REPORT = 'report';
    public const MODE_BALANCED = 'balanced';
    public const MODE_STRICT = 'strict';

    public const REPORT_PATH = '/_cp/csp-report';

    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('security.headers_enabled', true);
    }

    public function cspMode(): string
    {
        $mode = (string) ($this->settings->get('security.csp_mode') ?? self::MODE_REPORT);

        return \in_array($mode, [self::MODE_OFF, self::MODE_REPORT, self::MODE_BALANCED, self::MODE_STRICT], true)
            ? $mode
            : self::MODE_REPORT;
    }

    /**
     * Only the nonce-bearing modes need a per-response nonce; emitting one in
     * 'balanced' would make browsers ignore the 'unsafe-inline' that mode relies on.
     */
    public function nonceRequired(): bool
    {
        return \in_array($this->cspMode(), [self::MODE_REPORT, self::MODE_STRICT], true);
    }

    public function hstsMaxAge(): int
    {
        return max(0, (int) $this->settings->get('security.hsts_max_age', 0));
    }

    /**
     * @return array<string, string>
     */
    public function headers(Request $request, string $nonce): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => $this->referrerPolicy(),
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        $frameOptions = $this->frameOptions();
        if ($frameOptions !== null) {
            $headers['X-Frame-Options'] = $frameOptions;
        }

        $permissions = trim((string) ($this->settings->get('security.permissions_policy') ?? ''));
        if ($permissions !== '') {
            $headers['Permissions-Policy'] = $permissions;
        }

        $maxAge = $this->hstsMaxAge();
        if ($maxAge > 0 && $request->isSecure()) {
            $value = 'max-age='.$maxAge;
            if ((bool) $this->settings->get('security.hsts_subdomains', false)) {
                $value .= '; includeSubDomains';
            }
            if ((bool) $this->settings->get('security.hsts_preload', false)) {
                $value .= '; preload';
            }
            $headers['Strict-Transport-Security'] = $value;
        }

        $mode = $this->cspMode();
        if ($mode !== self::MODE_OFF) {
            $name = $mode === self::MODE_REPORT ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $headers[$name] = $this->buildCsp($mode, $nonce, $maxAge > 0);
        }

        return $headers;
    }

    private function buildCsp(string $mode, string $nonce, bool $upgradeInsecure): string
    {
        if ($mode === self::MODE_BALANCED) {
            $scriptSrc = ["'self'", "'unsafe-inline'"];
        } elseif ($nonce === '') {
            // No nonce reached the response (a listener short-circuited the request
            // before the nonce was minted, or a cached response is being replayed).
            // Emitting "'nonce-'" would be a malformed source expression, and the
            // 'strict-dynamic' beside it would then reject every script on the page
            // — the hardening layer would have taken the site down instead of the
            // attacker. Degrade to a nonce-less origin policy instead.
            $scriptSrc = ["'self'", 'https:'];
        } else {
            $scriptSrc = ["'self'", "'nonce-".$nonce."'", "'strict-dynamic'", 'https:'];
        }

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => $this->tokens($this->frameAncestors()),
            'script-src' => array_merge($scriptSrc, $this->extra('security.csp_script_src')),
            // Inline style stays allowed in every mode: themes and Twig components
            // set style attributes the core cannot nonce, and injected CSS is a far
            // weaker primitive than injected script.
            'style-src' => array_merge(["'self'", "'unsafe-inline'"], $this->extra('security.csp_style_src')),
            'img-src' => array_merge(["'self'", 'data:', 'https:'], $this->extra('security.csp_img_src')),
            'font-src' => ["'self'", 'data:'],
            'connect-src' => array_merge(["'self'"], $this->extra('security.csp_connect_src')),
            'frame-src' => array_merge(["'self'"], $this->extra('security.csp_frame_src')),
            'media-src' => ["'self'", 'https:'],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
        ];

        $parts = [];
        foreach ($directives as $name => $sources) {
            $sources = array_values(array_unique(array_filter($sources, static fn (string $s): bool => $s !== '')));
            if ($sources !== []) {
                $parts[] = $name.' '.implode(' ', $sources);
            }
        }

        if ($upgradeInsecure) {
            $parts[] = 'upgrade-insecure-requests';
        }

        $parts[] = 'report-uri '.self::REPORT_PATH;

        return implode('; ', $parts);
    }

    private function frameAncestors(): string
    {
        $value = trim((string) ($this->settings->get('security.csp_frame_ancestors') ?? ''));

        return $value !== '' ? $value : "'self'";
    }

    /**
     * X-Frame-Options only models 'none' and 'self'; a custom ancestor list is left
     * to frame-ancestors alone rather than emitting a header that contradicts it.
     */
    private function frameOptions(): ?string
    {
        return match ($this->frameAncestors()) {
            "'none'" => 'DENY',
            "'self'" => 'SAMEORIGIN',
            default => null,
        };
    }

    private function referrerPolicy(): string
    {
        $allowed = ['no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin'];
        $value = (string) ($this->settings->get('security.referrer_policy') ?? '');

        return \in_array($value, $allowed, true) ? $value : 'strict-origin-when-cross-origin';
    }

    /**
     * @return list<string>
     */
    private function extra(string $key): array
    {
        return $this->tokens((string) ($this->settings->get($key) ?? ''));
    }

    /**
     * Directive names, so a pasted "default-src *" cannot survive as two loose
     * tokens inside whichever source list it was pasted into. Stripping the ';'
     * already stops a second directive from being created, but the leftovers
     * still widen the directive they land in — in balanced mode a stray '*' in
     * script-src is a real loosening, not just noise.
     *
     * @var list<string>
     */
    private const DIRECTIVE_NAMES = [
        'default-src', 'base-uri', 'object-src', 'form-action', 'frame-ancestors',
        'script-src', 'script-src-elem', 'script-src-attr', 'style-src',
        'style-src-elem', 'style-src-attr', 'img-src', 'font-src', 'connect-src',
        'frame-src', 'media-src', 'worker-src', 'manifest-src', 'child-src',
        'report-uri', 'report-to', 'sandbox', 'upgrade-insecure-requests',
        'require-trusted-types-for', 'trusted-types', 'block-all-mixed-content',
    ];

    /**
     * Source lists are operator-typed free text; ';' would let one directive
     * inject another, so it is stripped rather than escaped.
     *
     * @return list<string>
     */
    private function tokens(string $raw): array
    {
        $clean = str_replace([';', "\r", "\n", ','], ' ', $raw);

        return array_values(array_filter(
            preg_split('/\s+/', trim($clean)) ?: [],
            static fn (string $token): bool => $token !== ''
                && !\in_array(strtolower($token), self::DIRECTIVE_NAMES, true),
        ));
    }
}

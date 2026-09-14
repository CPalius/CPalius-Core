<?php

declare(strict_types=1);

namespace App\Core\Security\Http;

use App\Core\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds response headers from security.* settings. CSP modes: off, report, balanced, strict.
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

    /** Nonce is only emitted in report/strict; balanced relies on 'unsafe-inline'. */
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
            // Emitting "'nonce-'" would be a malformed source expression that
            // matches nothing, so degrade to a nonce-less origin policy instead
            // of shipping a header that refuses every script on the page.
            $scriptSrc = ["'self'", 'https:'];
        } else {
            // No 'strict-dynamic' here, and its removal (2026-09-13) is a
            // correctness fix rather than a relaxation.
            //
            // 'strict-dynamic' makes the browser IGNORE every other source
            // expression in script-src — 'self' and https: included — and
            // trust only nonced scripts plus whatever those scripts insert
            // into the DOM at runtime. CPalius ships its front end as an
            // AssetMapper importmap: every module, including the vendored
            // chart.js the command desk draws with, arrives as a STATIC module
            // import, which is not a runtime DOM insertion and so inherits no
            // trust. Strict mode was therefore blocking the panel's own
            // JavaScript, and the browser said so in exactly those words
            // ("'self' is ignored: 'strict-dynamic' is specified"). A
            // hardening mode that switches off the admin panel is not a
            // hardening mode: operators leave it off and the policy protects
            // nobody.
            //
            // The nonce still does the work that matters — an injected inline
            // <script> carries no nonce and is refused, which is the XSS
            // primitive this mode exists to stop. What is given up is trust
            // propagation to dynamically inserted scripts, which an importmap
            // application does not rely on. This also settles the open
            // question recorded in the roadmap: hosts added to
            // security.csp_script_src were silently inert in strict mode
            // because 'strict-dynamic' voided them. They now apply.
            $scriptSrc = ["'self'", "'nonce-".$nonce."'", 'https:'];
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

    /** X-Frame-Options only models none/self; a custom ancestor list stays on frame-ancestors. */
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
     * CSP directive names stripped from operator source lists so a pasted "default-src *" cannot widen script-src.
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
     * Tokenizes a source list. Semicolons are stripped so one directive cannot inject another.
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

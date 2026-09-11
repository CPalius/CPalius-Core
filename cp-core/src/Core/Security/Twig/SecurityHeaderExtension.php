<?php

declare(strict_types=1);

namespace App\Core\Security\Twig;

use App\Core\Security\Http\CspNonceProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * csp_nonce() for inline <script> tags, so a theme can stay compatible with the
 * strict CSP mode without the core rewriting its markup.
 */
final class SecurityHeaderExtension extends AbstractExtension
{
    public function __construct(
        private readonly CspNonceProvider $nonceProvider,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce(...)),
            new TwigFunction('csp_nonce_attr', $this->nonceAttribute(...), ['is_safe' => ['html']]),
        ];
    }

    public function nonce(): string
    {
        return $this->nonceProvider->nonce();
    }

    /**
     * Renders nothing in the modes that do not issue a nonce, so the same template
     * works under every CSP mode.
     */
    public function nonceAttribute(): string
    {
        $nonce = $this->nonceProvider->nonce();

        return $nonce === '' ? '' : ' nonce="'.htmlspecialchars($nonce, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'"';
    }
}

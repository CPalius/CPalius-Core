<?php

declare(strict_types=1);

namespace App\Core\Security\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * One CSP nonce per main request, stored on the Request so the response listener
 * and Twig both read the same value. Sub-requests inherit the parent nonce.
 */
final class CspNonceProvider
{
    public const ATTRIBUTE = '_cp_csp_nonce';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Empty string when there is no request (CLI, worker) so templates stay renderable.
     */
    public function nonce(): string
    {
        $request = $this->requestStack->getMainRequest() ?? $this->requestStack->getCurrentRequest();

        return $request instanceof Request ? $this->nonceFor($request) : '';
    }

    public function nonceFor(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);
        if (\is_string($existing) && $existing !== '') {
            return $existing;
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $request->attributes->set(self::ATTRIBUTE, $nonce);

        return $nonce;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Security\Twig;

use App\Core\Security\Http\CspNonceProvider;
use Symfony\Bridge\Twig\Extension\ImportMapRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Replaces the stock importmap() Twig function so every generated <script>
 * carries the per-request CSP nonce (report-only and strict modes).
 */
final class CpImportMapExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImportMapRuntime $importMapRuntime,
        private readonly CspNonceProvider $nonceProvider,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('importmap', $this->importmap(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param string|list<string>   $entryPoint
     * @param array<string, string> $attributes
     */
    public function importmap(string|array $entryPoint = 'app', array $attributes = []): string
    {
        $nonce = $this->nonceProvider->nonce();
        if ($nonce !== '') {
            $attributes['nonce'] ??= $nonce;
        }

        return $this->importMapRuntime->importmap($entryPoint, $attributes);
    }
}

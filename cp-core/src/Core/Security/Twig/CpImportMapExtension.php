<?php

declare(strict_types=1);

namespace App\Core\Security\Twig;

use App\Core\Security\Http\CspNonceProvider;
use Symfony\Bridge\Twig\Extension\ImportMapRuntime;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * importmap() with a per-request CSP nonce, plus cp_asset() for stylesheet URLs without a JS import.
 */
final class CpImportMapExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImportMapRuntime $importMapRuntime,
        private readonly CspNonceProvider $nonceProvider,
        private readonly AssetMapperInterface $assetMapper,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('importmap', $this->importmap(...), ['is_safe' => ['html']]),
            new TwigFunction('cp_asset', $this->asset(...)),
        ];
    }

    /**
     * Digested public URL for a mapped asset. Unknown paths return the logical path instead of throwing.
     */
    public function asset(string $logicalPath): string
    {
        return $this->assetMapper->getPublicPath($logicalPath) ?? $logicalPath;
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

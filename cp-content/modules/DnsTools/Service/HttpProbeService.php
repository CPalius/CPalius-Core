<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

final class HttpProbeService
{
    private const SECURITY_HEADERS = [
        'strict-transport-security',
        'content-security-policy',
        'x-content-type-options',
        'x-frame-options',
        'referrer-policy',
        'permissions-policy',
        'x-xss-protection',
    ];

    public function __construct(
        private readonly ProbeClient $probes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function headers(string $url): array
    {
        $result = $this->probes->httpHead($url);
        $present = [];
        $missing = [];
        foreach (self::SECURITY_HEADERS as $header) {
            if (isset($result['headers'][$header])) {
                $present[$header] = $result['headers'][$header];
            } else {
                $missing[] = $header;
            }
        }

        return $result + [
            'security_present' => $present,
            'security_missing' => $missing,
            'score' => (int) round((\count($present) / \count(self::SECURITY_HEADERS)) * 100),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function redirects(string $url): array
    {
        $result = $this->probes->httpHead($url, 8);
        $chain = $result['redirects'];
        $chain[] = ['url' => $result['url'], 'status' => $result['status']];

        return [
            'start' => $url,
            'final' => $result['url'],
            'hops' => \count($result['redirects']),
            'chain' => $chain,
        ];
    }
}

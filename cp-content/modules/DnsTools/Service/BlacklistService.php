<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Modules\DnsTools\Security\SsrfGuard;

final class BlacklistService
{
    private const LISTS = [
        'zen.spamhaus.org',
        'b.barracudacentral.org',
        'bl.spamcop.net',
        'dnsbl.sorbs.net',
        'cbl.abuseat.org',
    ];

    public function __construct(
        private readonly SsrfGuard $ssrf,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $ip): array
    {
        if (!$this->ssrf->isPublicIp($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('dnstools.error.ipv4_only');
        }

        $reverse = implode('.', array_reverse(explode('.', $ip)));
        $hits = [];
        $clean = [];

        foreach (self::LISTS as $list) {
            $listed = @checkdnsrr($reverse.'.'.$list, 'A');
            if ($listed) {
                $hits[] = $list;
            } else {
                $clean[] = $list;
            }
        }

        return [
            'ip' => $ip,
            'listed' => $hits,
            'clean' => $clean,
            'listed_count' => \count($hits),
        ];
    }
}

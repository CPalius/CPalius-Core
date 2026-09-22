<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

final class IpUtilityService
{
    private const OUI = [
        '000C29' => 'VMware',
        '00155D' => 'Microsoft Hyper-V',
        '001C42' => 'Parallels',
        '080027' => 'VirtualBox',
        '525400' => 'QEMU/KVM',
        '001A11' => 'Google',
        'F0D4E2' => 'Apple',
        '3C06A7' => 'Intel',
        '001B21' => 'Intel',
        '002590' => 'Super Micro',
        'D8EB97' => 'Cisco',
        '00E04C' => 'Realtek',
        '001E68' => 'Hewlett Packard',
        'B827EB' => 'Raspberry Pi',
        'DC4F22' => 'Huawei',
        'A4C138' => 'Telink',
        'ACDE48' => 'Private',
    ];

    /**
     * @return array<string, mixed>
     */
    public function ipv4ToIpv6(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('dnstools.error.ipv4_only');
        }

        $octets = array_map('intval', explode('.', $ip));
        $mapped = sprintf('::ffff:%02x%02x:%02x%02x', $octets[0], $octets[1], $octets[2], $octets[3]);
        $embedded = sprintf('64:ff9b::%02x%02x:%02x%02x', $octets[0], $octets[1], $octets[2], $octets[3]);

        return [
            'ipv4' => $ip,
            'mapped' => $mapped,
            'nat64' => $embedded,
            'dotted' => '::ffff:'.$ip,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function compress(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \InvalidArgumentException('dnstools.error.ipv6_only');
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            throw new \InvalidArgumentException('dnstools.error.ipv6_only');
        }

        $expanded = implode(':', str_split(bin2hex($packed), 4));

        return [
            'input' => $ip,
            'compressed' => inet_ntop($packed),
            'expanded' => $expanded,
        ];
    }

    /**
     * Local CIDR math only — private ranges are allowed because operators use them every day.
     *
     * @param array{ip: string, prefix: int} $cidr
     * @return array<string, mixed>
     */
    public function cidr(array $cidr): array
    {
        $ip = $cidr['ip'];
        $prefix = $cidr['prefix'];
        $v6 = str_contains($ip, ':');

        return $v6 ? $this->cidr6($ip, $prefix) : $this->cidr4($ip, $prefix);
    }

    /**
     * @return array<string, mixed>
     */
    private function cidr4(string $ip, int $prefix): array
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            throw new \InvalidArgumentException('dnstools.error.invalid_cidr');
        }

        $ipLong &= 0xFFFFFFFF;
        $maskLong = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;
        $networkLong = $ipLong & $maskLong;
        $broadcastLong = $networkLong | (~$maskLong & 0xFFFFFFFF);
        $size = $prefix === 0 ? 4294967296 : (1 << (32 - $prefix));

        $first = $networkLong;
        $last = $broadcastLong;
        $usable = $size;
        if ($prefix < 31) {
            $first = $networkLong + 1;
            $last = $broadcastLong - 1;
            $usable = max(0, $size - 2);
        }

        return [
            'input' => $ip.'/'.$prefix,
            'version' => 4,
            'network' => long2ip($networkLong),
            'broadcast' => long2ip($broadcastLong),
            'netmask' => long2ip($maskLong),
            'wildcard' => long2ip(~$maskLong & 0xFFFFFFFF),
            'prefix' => $prefix,
            'first_host' => long2ip($first),
            'last_host' => long2ip($last),
            'total_addresses' => $size,
            'usable_hosts' => $usable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cidr6(string $ip, int $prefix): array
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            throw new \InvalidArgumentException('dnstools.error.invalid_cidr');
        }

        $bits = unpack('C*', $packed) ?: [];
        $network = '';
        $remaining = $prefix;
        foreach ($bits as $octet) {
            $keep = max(0, min(8, $remaining));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $network .= chr($octet & $mask);
            $remaining -= 8;
        }

        $hostBits = 128 - $prefix;

        return [
            'input' => $ip.'/'.$prefix,
            'version' => 6,
            'network' => inet_ntop($network) ?: $ip,
            'broadcast' => null,
            'prefix' => $prefix,
            'first_host' => inet_ntop($network) ?: $ip,
            'total_addresses' => $this->pow2($hostBits),
            'usable_hosts' => $this->pow2($hostBits),
            'note' => 'ipv6_no_broadcast',
        ];
    }

    private function pow2(int $bits): string
    {
        if ($bits <= 53) {
            return (string) (2 ** $bits);
        }
        if (\function_exists('gmp_pow')) {
            return gmp_strval(gmp_pow('2', $bits));
        }
        if (\function_exists('bcpow')) {
            return bcpow('2', (string) $bits, 0);
        }

        return '2^'.$bits;
    }

    /**
     * @return array<string, mixed>
     */
    public function mac(string $mac): array
    {
        $oui = str_replace(':', '', substr($mac, 0, 8));

        return [
            'mac' => $mac,
            'oui' => $oui,
            'vendor' => self::OUI[$oui] ?? null,
            'unicast' => (hexdec(substr($oui, 1, 1)) & 1) === 0,
            'locally_administered' => (hexdec(substr($oui, 1, 1)) & 2) === 2,
        ];
    }
}

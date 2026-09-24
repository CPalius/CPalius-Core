<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Modules\DnsTools\Security\SsrfGuard;

final class NetworkProbeService
{
    public const COMMON_PORTS = [21, 22, 25, 53, 80, 110, 143, 443, 465, 587, 993, 995, 8080, 8443];

    public function __construct(
        private readonly ProbeClient $probes,
        private readonly SsrfGuard $ssrf,
        private readonly DnsRecordService $dns,
        private readonly WhoisService $whois,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ping(string $host): array
    {
        $ips = $this->ssrf->resolvePublic($host);
        if ($ips === null) {
            throw new \InvalidArgumentException('dnstools.error.private_target');
        }

        $samples = [];
        foreach ([80, 443] as $port) {
            try {
                $samples[] = $this->probes->tcp($host, $port, false) + ['port' => $port];
            } catch (\Throwable $e) {
                $samples[] = ['ok' => false, 'ms' => null, 'port' => $port, 'error' => $e->getMessage()];
            }
        }

        return [
            'host' => $host,
            'ips' => $ips,
            'method' => 'tcp_connect',
            'samples' => $samples,
        ];
    }

    /**
     * @param list<int> $ports
     * @return array<string, mixed>
     */
    public function ports(string $host, array $ports): array
    {
        if (!$this->probes->portChecksAllowed()) {
            throw new \RuntimeException('dnstools.error.ports_disabled');
        }

        $this->ssrf->assertPublicHost($host);
        $scan = $ports !== [] ? $ports : self::COMMON_PORTS;
        $scan = array_values(array_intersect($scan, self::COMMON_PORTS));
        $results = [];

        foreach (array_slice($scan, 0, 15) as $port) {
            try {
                $probe = $this->probes->tcp($host, $port, false);
                $results[] = ['port' => $port, 'open' => $probe['ok'], 'ms' => $probe['ms']];
            } catch (\Throwable $e) {
                $results[] = ['port' => $port, 'open' => false, 'ms' => null, 'error' => $e->getMessage()];
            }
        }

        return ['host' => $host, 'results' => $results];
    }

    /**
     * @return array<string, mixed>
     */
    public function latency(string $host): array
    {
        $samples = [];
        for ($i = 0; $i < 4; ++$i) {
            try {
                $probe = $this->probes->tcp($host, 443, false);
                $samples[] = $probe['ok'] ? $probe['ms'] : null;
            } catch (\Throwable) {
                $samples[] = null;
            }
        }
        $ok = array_values(array_filter($samples, static fn (?int $ms): bool => $ms !== null));

        return [
            'host' => $host,
            'samples_ms' => $samples,
            'avg_ms' => $ok === [] ? null : (int) round(array_sum($ok) / \count($ok)),
            'loss' => $ok === [] ? 100 : (int) round((4 - \count($ok)) / 4 * 100),
        ];
    }

    /**
     * TCP reachability summary. Full ICMP traceroute needs raw sockets and is not used.
     *
     * @return array<string, mixed>
     */
    public function traceroute(string $host): array
    {
        $ips = $this->ssrf->resolvePublic($host) ?? [];
        $ptr = [];
        foreach ($ips as $ip) {
            $rev = $this->dns->reverse($ip);
            $ptr[$ip] = $rev['ptr'];
        }

        $probe = null;
        try {
            $probe = $this->probes->tcp($host, 443, false);
        } catch (\Throwable $e) {
            $probe = ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'host' => $host,
            'ips' => $ips,
            'ptr' => $ptr,
            'destination' => $probe,
            'method' => 'tcp_443',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ipLookup(string $ip): array
    {
        $this->ssrf->assertPublicHost($ip);
        $rev = $this->dns->reverse($ip);
        $whois = $this->whois->ip($ip);

        return [
            'ip' => $ip,
            'ptr' => $rev['ptr'],
            'whois' => $whois,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function smtp(string $host, int $port = 587, string $encryption = 'starttls', string $username = '', string $password = ''): array
    {
        if (!$this->probes->networkProbesAllowed()) {
            throw new \RuntimeException('dnstools.error.probes_disabled');
        }
        $this->ssrf->assertPublicHost($host);
        $port = max(1, min(65535, $port));
        $encryption = \in_array($encryption, ['ssl', 'starttls', 'none'], true) ? $encryption : 'starttls';
        $username = mb_substr(trim($username), 0, 200);
        $password = mb_substr($password, 0, 200);

        $implicit = $encryption === 'ssl';
        $timeout = $this->probes->timeout();
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            sprintf('%s://%s:%d', $implicit ? 'ssl' : 'tcp', $host, $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $implicit ? $context : null,
        );
        if (!\is_resource($fp)) {
            return [
                'ok' => false,
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'banner' => '',
                'auth' => 'skipped',
                'error' => $errstr !== '' ? $errstr : 'connection failed',
            ];
        }

        stream_set_timeout($fp, $timeout);
        $banner = $this->smtpLine($fp);
        $auth = 'skipped';
        $error = null;
        try {
            $this->smtpExpect($fp, 'EHLO m-dns.org', '250');
            if ($encryption === 'starttls') {
                $this->smtpExpect($fp, 'STARTTLS', '220');
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $this->smtpExpect($fp, 'EHLO m-dns.org', '250');
            }
            if ($username !== '') {
                $token = base64_encode("\0".$username."\0".$password);
                $this->smtpExpect($fp, 'AUTH PLAIN '.$token, '235');
                $auth = 'pass';
            }
            fwrite($fp, "QUIT\r\n");
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if ($username !== '' && $auth !== 'pass') {
                $auth = 'fail';
            }
            @fwrite($fp, "QUIT\r\n");
        }
        fclose($fp);

        return [
            'ok' => $error === null,
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'banner' => mb_substr($banner, 0, 300),
            'auth' => $auth,
            'error' => $error,
        ];
    }

    private function smtpExpect($fp, string $command, string $code): string
    {
        fwrite($fp, $command."\r\n");
        $reply = $this->smtpRead($fp);
        if (!str_starts_with($reply, $code)) {
            throw new \RuntimeException(mb_substr(trim($reply), 0, 180));
        }

        return $reply;
    }

    private function smtpLine($fp): string
    {
        $line = (string) fgets($fp, 512);

        return trim($line);
    }

    private function smtpRead($fp): string
    {
        $text = '';
        for ($i = 0; $i < 40; ++$i) {
            $line = (string) fgets($fp, 1024);
            if ($line === '') {
                break;
            }
            $text .= $line;
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }

        return $text;
    }
}

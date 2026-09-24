<?php

declare(strict_types=1);

namespace Modules\DnsTools\Install;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DnsToolsSettingsSeeder
{
    public static function seed(Connection $connection): int
    {
        $rows = self::rows();
        $keys = array_keys($rows);
        if ($keys === []) {
            return 0;
        }

        try {
            $existing = $connection->fetchFirstColumn(
                'SELECT setting_key FROM cp_settings WHERE setting_key IN (:keys)',
                ['keys' => $keys],
                ['keys' => ArrayParameterType::STRING],
            );
        } catch (\Throwable) {
            return 0;
        }

        $have = [];
        foreach ($existing as $key) {
            if (\is_string($key) && $key !== '') {
                $have[$key] = true;
            }
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $written = 0;

        foreach ($rows as $key => $value) {
            if (isset($have[$key])) {
                continue;
            }

            try {
                $connection->executeStatement(
                    'INSERT INTO cp_settings (setting_key, setting_value, module, updated_at) VALUES (:key, :value, :module, :now)',
                    ['key' => $key, 'value' => $value, 'module' => 'dnstools', 'now' => $now],
                );
                ++$written;
            } catch (\Throwable) {
            }
        }

        return $written;
    }

    /**
     * @return array<string, string>
     */
    public static function rows(): array
    {
        return [
            'dnstools.enabled' => '1',
            'dnstools.cache_ttl' => '120',
            'dnstools.rate_limit_minute' => '20',
            'dnstools.rate_limit_hour' => '200',
            'dnstools.spam_guest_quota' => '10',
            'dnstools.spam_member_quota' => '100',
            'dnstools.probe_timeout' => '5',
            'dnstools.max_bulk' => '10',
            'dnstools.allow_network_probes' => '1',
            'dnstools.allow_port_check' => '1',
            'dnstools.mail_inbox_enabled' => '0',
            'dnstools.mail_inbox_domain' => '',
            'dnstools.mail_inbox_ttl' => '1200',
            'dnstools.mail_inbox_webhook_secret' => '',
            'dnstools.mail_imap_host' => '',
            'dnstools.mail_imap_port' => '993',
            'dnstools.mail_imap_user' => '',
            'dnstools.mail_imap_password' => '',
            'dnstools.mail_imap_encryption' => 'ssl',
        ];
    }
}

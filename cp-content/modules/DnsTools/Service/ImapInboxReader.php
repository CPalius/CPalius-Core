<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Settings\SettingsRegistry;

/**
 * Reads the catch-all mailbox at MXRoute. No PHP imap extension and no local mail server.
 */
final class ImapInboxReader
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly InboxService $inbox,
    ) {
    }

    public function drain(): int
    {
        try {
            $client = $this->connect();
        } catch (\Throwable) {
            return 0;
        }

        $accepted = 0;
        try {
            $client->selectInbox();
            $uids = array_slice(array_reverse($client->uidSearchSince(new \DateTimeImmutable('-1 day'))), 0, 20);
            foreach ($uids as $uid) {
                $raw = substr($client->uidFetch($uid), 0, 50000);
                $header = strstr($raw, "\r\n\r\n", true) ?: $raw;
                if ($this->inbox->ingest($header, $raw)['ok'] ?? false) {
                    ++$accepted;
                    $client->uidDelete($uid);
                }
            }
            if ($accepted > 0) {
                $client->expunge();
            }
        } catch (\Throwable) {
            return $accepted;
        } finally {
            $client->close();
        }

        return $accepted;
    }

    /**
     * @return array{ok: bool, error: string, messages: int, matches: list<string>}
     */
    public function probe(): array
    {
        $host = trim((string) $this->settings->get('dnstools.mail_imap_host', ''));
        $user = trim((string) $this->settings->get('dnstools.mail_imap_user', ''));
        $password = (string) $this->settings->get('dnstools.mail_imap_password', '');
        if ($host === '' || $user === '' || $password === '') {
            return ['ok' => false, 'error' => 'imap_incomplete', 'messages' => 0, 'matches' => []];
        }

        try {
            $client = $this->connect();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'messages' => 0, 'matches' => []];
        }

        try {
            $count = $client->selectInbox();
            $uids = array_slice(array_reverse($client->uidSearchSince(new \DateTimeImmutable('-2 days'))), 0, 12);
            $matches = [];
            foreach ($uids as $uid) {
                $raw = $client->uidFetch($uid);
                $header = strstr($raw, "\r\n\r\n", true) ?: $raw;
                if (preg_match_all('/t[a-f0-9]{16}@[^\s>,;]+/i', $header, $found) > 0) {
                    foreach ($found[0] as $address) {
                        $matches[] = strtolower($address);
                    }
                }
            }

            return [
                'ok' => true,
                'error' => '',
                'messages' => $count,
                'matches' => array_values(array_unique($matches)),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'messages' => 0, 'matches' => []];
        } finally {
            $client->close();
        }
    }

    private function connect(): CatchallImapClient
    {
        $host = trim((string) $this->settings->get('dnstools.mail_imap_host', ''));
        $user = trim((string) $this->settings->get('dnstools.mail_imap_user', ''));
        $password = (string) $this->settings->get('dnstools.mail_imap_password', '');
        if ($host === '' || $user === '' || $password === '') {
            throw new \RuntimeException('imap_incomplete');
        }

        $port = max(1, min(65535, (int) $this->settings->get('dnstools.mail_imap_port', 993)));
        $encryption = strtolower((string) $this->settings->get('dnstools.mail_imap_encryption', 'ssl'));
        $client = CatchallImapClient::open($host, $port, $encryption);
        try {
            $client->login($user, $password);
        } catch (\Throwable $e) {
            $client->close();
            throw $e;
        }

        return $client;
    }
}

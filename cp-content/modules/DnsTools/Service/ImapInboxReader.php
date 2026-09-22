<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Settings\SettingsRegistry;

/**
 * Optional catch-all mailbox poll. Skips when IMAP is missing or unset.
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
        if (!\function_exists('imap_open')) {
            return 0;
        }

        $host = trim((string) $this->settings->get('dnstools.mail_imap_host', ''));
        $user = trim((string) $this->settings->get('dnstools.mail_imap_user', ''));
        $password = (string) $this->settings->get('dnstools.mail_imap_password', '');
        if ($host === '' || $user === '' || $password === '') {
            return 0;
        }

        $port = max(1, min(65535, (int) $this->settings->get('dnstools.mail_imap_port', 993)));
        $enc = strtolower((string) $this->settings->get('dnstools.mail_imap_encryption', 'ssl'));
        $flags = $enc === 'tls' ? '/imap/tls/novalidate-cert' : '/imap/ssl/novalidate-cert';
        $mailbox = '{'.$host.':'.$port.$flags.'}INBOX';
        $stream = @imap_open($mailbox, $user, $password, 0, 1);
        if ($stream === false) {
            return 0;
        }

        $accepted = 0;
        try {
            $ids = imap_search($stream, 'UNSEEN', SE_UID) ?: [];
            foreach (\array_slice($ids, 0, 20) as $uid) {
                $header = imap_fetchheader($stream, (int) $uid, FT_UID) ?: '';
                $body = (string) imap_body($stream, (int) $uid, FT_UID | FT_PEEK);
                $raw = substr($header."\r\n".$body, 0, 50000);
                $to = $this->header($header, 'to').' '.$this->header($header, 'delivered-to').' '.$this->header($header, 'x-original-to');
                if ($this->inbox->ingest($to, $raw)['ok'] ?? false) {
                    ++$accepted;
                    imap_setflag_full($stream, (string) $uid, '\\Seen \\Deleted', ST_UID);
                }
            }
            if ($accepted > 0) {
                imap_expunge($stream);
            }
        } finally {
            imap_close($stream);
        }

        return $accepted;
    }

    private function header(string $raw, string $name): string
    {
        if (preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/im', $raw, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }
}

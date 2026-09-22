<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Settings\SettingsRegistry;

/**
 * Picks up dropped .eml files and, when configured, the catch-all IMAP mailbox.
 */
final class InboxPollTask
{
    public function __construct(
        private readonly InboxService $inbox,
        private readonly ImapInboxReader $imap,
        private readonly SettingsRegistry $settings,
        private readonly string $projectDir,
    ) {
    }

    #[CpCronJob(schedule: '* * * * *', name: 'dnstools.inbox.poll', description: 'Ingest dropped test mail and optional IMAP catch-all')]
    public function poll(): void
    {
        if ($this->inbox->inboxDomain() === null) {
            return;
        }

        $this->drainDropDir();
        $this->imap->drain();
    }

    private function drainDropDir(): void
    {
        $dir = $this->dropDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.eml') ?: [];
        foreach (\array_slice($files, 0, 30) as $file) {
            if (!is_file($file) || filesize($file) > 65536) {
                @unlink($file);
                continue;
            }
            $raw = (string) file_get_contents($file);
            $to = $this->header($raw, 'to').' '.$this->header($raw, 'delivered-to').' '.$this->header($raw, 'x-original-to');
            $this->inbox->ingest($to, $raw);
            @unlink($file);
        }
    }

    public function dropDir(): string
    {
        $configured = trim((string) $this->settings->get('dnstools.mail_drop_dir', ''));
        if ($configured !== '' && $this->safeDir($configured)) {
            return rtrim($configured, '/\\');
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'dnstools-inbox';
    }

    private function safeDir(string $path): bool
    {
        $real = realpath($path);
        $root = realpath($this->projectDir.DIRECTORY_SEPARATOR.'var');
        if ($real === false || $root === false) {
            return false;
        }

        return str_starts_with($real, $root);
    }

    private function header(string $raw, string $name): string
    {
        if (preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/im', $raw, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }
}

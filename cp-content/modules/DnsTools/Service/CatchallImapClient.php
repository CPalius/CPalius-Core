<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

/**
 * Small IMAP client over TLS. CloudPanel has no mail server and PHP 8.4 no longer
 * ships the imap extension; MXRoute is reached directly on port 993.
 */
final class CatchallImapClient
{
    /** @var resource */
    private $socket;

    private int $sequence = 0;

    private string $lastLiteral = '';

    /**
     * @param resource $socket
     */
    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    public static function open(string $host, int $port, string $encryption): self
    {
        if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1) {
            throw new \RuntimeException('IMAP host is not a hostname.');
        }

        $implicitTls = $encryption !== 'tls' || $port === 993;
        $remote = ($implicitTls ? 'ssl' : 'tcp').'://'.$host.':'.$port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ]]),
        );
        if (!\is_resource($socket)) {
            $detail = trim($errstr) !== '' ? $errstr : 'connect_failed';
            throw new \RuntimeException($remote.' — '.$detail.' ('.$errno.')');
        }

        stream_set_timeout($socket, 20);
        $client = new self($socket);
        $client->readUntilTagged('greeting');
        if (!$implicitTls) {
            $client->run('STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed.');
            }
        }

        return $client;
    }

    public function login(string $user, string $password): void
    {
        $this->run('LOGIN '.$this->quote($user).' '.$this->quote($password));
    }

    public function selectInbox(): int
    {
        $response = $this->run('SELECT INBOX');
        if (preg_match('/^\* (\d+) EXISTS/m', $response, $match) !== 1) {
            return 0;
        }

        return (int) $match[1];
    }

    /**
     * @return list<int>
     */
    public function uidSearchSince(\DateTimeImmutable $since): array
    {
        $response = $this->run('UID SEARCH SINCE '.$since->format('d-M-Y'));
        if (preg_match('/^\* SEARCH(.*)$/m', $response, $match) !== 1) {
            return [];
        }

        $ids = array_map('intval', array_filter(explode(' ', trim($match[1])), static fn (string $part) => $part !== ''));

        return array_values(array_filter($ids, static fn (int $id) => $id > 0));
    }

    public function uidFetch(int $uid): string
    {
        $this->lastLiteral = '';
        $this->run('UID FETCH '.$uid.' (BODY.PEEK[])');

        return $this->lastLiteral;
    }

    public function uidDelete(int $uid): void
    {
        $this->run('UID STORE '.$uid.' +FLAGS.SILENT (\Seen \Deleted)');
    }

    public function expunge(): void
    {
        $this->run('EXPUNGE');
    }

    public function close(): void
    {
        if (!\is_resource($this->socket)) {
            return;
        }

        try {
            $this->run('LOGOUT');
        } catch (\Throwable) {
        }

        fclose($this->socket);
    }

    private function run(string $command): string
    {
        ++$this->sequence;
        $tag = 'A'.$this->sequence;
        fwrite($this->socket, $tag.' '.$command."\r\n");

        return $this->readUntilTagged($tag);
    }

    private function readUntilTagged(string $tag): string
    {
        $buffer = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) {
                break;
            }
            $buffer .= $line;
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $match) === 1) {
                $literal = $this->readLength((int) $match[1]);
                $this->lastLiteral = $literal;
                $buffer .= $literal;
                continue;
            }
            if ($tag !== 'greeting' && preg_match('/^'.preg_quote($tag, '/').' (OK|NO|BAD) (.*)$/i', $line, $match) === 1) {
                if (strtoupper($match[1]) !== 'OK') {
                    throw new \RuntimeException(trim($match[2]) !== '' ? trim($match[2]) : trim($line));
                }

                return $buffer;
            }
            if ($tag === 'greeting' && str_starts_with($line, '*')) {
                return $buffer;
            }
        }

        throw new \RuntimeException('IMAP connection closed.');
    }

    private function readLength(int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($this->socket)) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}

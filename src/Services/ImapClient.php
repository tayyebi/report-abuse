<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use RuntimeException;

/**
 * Pure-PHP IMAP4rev1 client.
 *
 * PHP's imap extension was unbundled in PHP 8.4, so this project talks to the
 * server directly over a socket instead. Only the handful of commands the
 * application needs are implemented: CAPABILITY, LOGIN/AUTHENTICATE PLAIN,
 * SELECT, UID SEARCH, UID FETCH and LOGOUT.
 */
final class ImapClient
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var resource|null */
    private $socket = null;

    private int $tagCounter = 0;

    /** @var list<string> */
    private array $transcript = [];

    /** @var array<string, mixed> */
    private array $mailboxState = [];

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) Config::get('mail.imap', []);
    }

    public static function isConfigured(): bool
    {
        $host = (string) Config::get('mail.imap.host', '');

        return $host !== '';
    }

    /**
     * @return array{uidvalidity: int, uidnext: int, exists: int}
     */
    public function connect(string $mailbox = 'INBOX'): array
    {
        $host = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? 993);
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'ssl'));
        $timeout = max(3, (int) ($this->config['timeout'] ?? 20));

        if ($host === '') {
            throw new RuntimeException('IMAP host is not configured.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException(sprintf('Cannot connect to IMAP server %s:%d (%s)', $host, $port, $errstr ?: 'unknown error'));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $timeout);

        $greeting = $this->readResponse();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            $this->close();
            throw new RuntimeException('Unexpected IMAP greeting: ' . trim($greeting));
        }

        if ($encryption === 'tls' || $encryption === 'starttls') {
            $this->command('STARTTLS');
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (@stream_socket_enable_crypto($this->socket, true, $method) !== true) {
                $this->close();
                throw new RuntimeException('IMAP STARTTLS negotiation failed.');
            }
        }

        if (!preg_match('/^\* PREAUTH/i', $greeting)) {
            $this->login();
        }

        return $this->select($mailbox);
    }

    private function login(): void
    {
        $capabilities = $this->command('CAPABILITY');
        $caps = strtoupper(implode(' ', $capabilities));

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        if ($username === '') {
            throw new RuntimeException('IMAP username is not configured.');
        }

        if (str_contains($caps, 'LOGINDISABLED')) {
            // AUTHENTICATE PLAIN with a continuation response.
            $tag = $this->nextTag();
            $this->write($tag . " AUTHENTICATE PLAIN\r\n");
            $continuation = $this->readResponse();
            if (!str_starts_with(trim($continuation), '+')) {
                throw new RuntimeException('IMAP server rejected AUTHENTICATE PLAIN: ' . trim($continuation));
            }
            $this->write(base64_encode("\0" . $username . "\0" . $password) . "\r\n");
            $responses = $this->readUntilTagged($tag);
            $status = (string) array_pop($responses);
            if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/i', $status)) {
                throw new RuntimeException('IMAP authentication failed: ' . trim($status));
            }

            return;
        }

        $this->command(sprintf('LOGIN %s %s', $this->quote($username), $this->quote($password)));
    }

    /**
     * @return array{uidvalidity: int, uidnext: int, exists: int}
     */
    public function select(string $mailbox): array
    {
        $response = $this->command('SELECT ' . $this->quote($mailbox));

        $uidvalidity = 1;
        $uidnext = 1;
        $exists = 0;
        foreach ($response as $line) {
            if (preg_match('/\* OK \[UIDVALIDITY (\d+)\]/i', $line, $m)) {
                $uidvalidity = (int) $m[1];
            }
            if (preg_match('/\* (\d+) EXISTS/i', $line, $m)) {
                $exists = (int) $m[1];
            }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $m)) {
                $uidnext = (int) $m[1];
            }
        }

        $this->mailboxState = [
            'mailbox' => $mailbox,
            'uidvalidity' => $uidvalidity,
            'uidnext' => $uidnext,
            'exists' => $exists,
        ];

        return $this->mailboxState;
    }

    /**
     * Fetch messages with a UID greater than $sinceUid (or the newest ones when
     * $sinceUid is 0).
     *
     * @return list<array{uid: int, raw: string, flags: string, internal_date: ?string}>
     */
    public function fetchNewMessages(int $sinceUid = 0, ?int $limit = null): array
    {
        $limit = $limit ?? (int) ($this->config['batch_limit'] ?? 25);
        $limit = max(1, min(200, $limit));

        $uids = $sinceUid > 0
            ? $this->searchUids(sprintf('UID %d:*', $sinceUid + 1), $sinceUid)
            : $this->searchUids('ALL');

        if (count($uids) > $limit) {
            $uids = array_slice($uids, -$limit);
        }

        $messages = [];
        foreach ($uids as $uid) {
            $message = $this->fetchMessage($uid);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @return list<int>
     */
    private function searchUids(string $criteria, int $minUid = 0): array
    {
        $response = $this->command('UID SEARCH ' . $criteria);
        $uids = [];
        foreach ($response as $line) {
            if (preg_match('/^\* SEARCH(.*)$/i', $line, $m)) {
                $numbers = preg_split('/\s+/', trim($m[1])) ?: [];
                foreach ($numbers as $number) {
                    if ($number !== '' && ctype_digit($number)) {
                        $uid = (int) $number;
                        if ($uid > $minUid) {
                            $uids[] = $uid;
                        }
                    }
                }
            }
        }

        sort($uids);

        return array_values(array_unique($uids));
    }

    /**
     * @return array{uid: int, raw: string, flags: string, internal_date: ?string}|null
     */
    public function fetchMessage(int $uid): ?array
    {
        $tag = $this->nextTag();
        $this->write(sprintf("%s UID FETCH %d (BODY.PEEK[] FLAGS INTERNALDATE)\r\n", $tag, $uid));
        $raw = $this->readUntilTagged($tag);
        $joined = implode('', $raw);

        $literal = $this->extractLiteral($joined);
        if ($literal === null) {
            return null;
        }

        $flags = '';
        if (preg_match('/FLAGS \(([^)]*)\)/i', $joined, $m)) {
            $flags = trim($m[1]);
        }

        $internalDate = null;
        if (preg_match('/INTERNALDATE "([^"]+)"/i', $joined, $m)) {
            $timestamp = strtotime($m[1]);
            if ($timestamp !== false) {
                $internalDate = date('Y-m-d H:i:s', $timestamp);
            }
        }

        return [
            'uid' => $uid,
            'raw' => $literal,
            'flags' => $flags,
            'internal_date' => $internalDate,
        ];
    }

    public function markSeen(int $uid): void
    {
        $this->command(sprintf('UID STORE %d +FLAGS (\\Seen)', $uid));
    }

    public function logout(): void
    {
        if ($this->socket === null) {
            return;
        }
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // ignore
        }
        $this->close();
    }

    /**
     * @return array<string, mixed>
     */
    public function mailboxState(): array
    {
        return $this->mailboxState;
    }

    /**
     * @return list<string>
     */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /*
     * ---------------------------------------------------------------------
     * Protocol plumbing
     * ---------------------------------------------------------------------
     */

    /**
     * Execute a tagged command and return the untagged response lines.
     *
     * @return list<string>
     */
    private function command(string $command): array
    {
        $tag = $this->nextTag();
        $this->write($tag . ' ' . $command . "\r\n");
        $lines = $this->readUntilTagged($tag);

        $statusLine = (string) array_pop($lines);
        if (!preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/i', $statusLine, $m)) {
            throw new RuntimeException('Unexpected IMAP response: ' . trim($statusLine));
        }
        if (strtoupper($m[1]) !== 'OK') {
            throw new RuntimeException(sprintf('IMAP command "%s" failed: %s', strtok($command, ' '), trim($statusLine)));
        }

        return array_values($lines);
    }

    /**
     * Read responses until the tagged status line arrives.
     *
     * @return list<string> raw responses, last element is the tagged status line
     */
    private function readUntilTagged(string $tag): array
    {
        $responses = [];
        while (true) {
            $response = $this->readResponse();
            $responses[] = $response;
            if (preg_match('/^' . preg_quote($tag, '/') . ' /', $response)) {
                return $responses;
            }
            if ($this->socket === null) {
                throw new RuntimeException('IMAP connection closed unexpectedly.');
            }
            if (count($responses) > 5000) {
                throw new RuntimeException('IMAP response limit exceeded.');
            }
        }
    }

    private function readResponse(): string
    {
        $buffer = $this->readLine();
        while (true) {
            $buffer = (string) $buffer;
            if (!preg_match('/\{(\d+)\}\r?\n$/', $buffer, $m)) {
                break;
            }
            $length = (int) $m[1];
            if ($length < 0 || $length > 50_000_000) {
                throw new RuntimeException('Refusing to read an oversized IMAP literal.');
            }
            $buffer .= $this->readBytes($length);
            $buffer .= $this->readLine();
        }

        return $buffer;
    }

    private function readLine(): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('IMAP socket is not connected.');
        }
        $line = fgets($this->socket, 8192);
        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            throw new RuntimeException('IMAP connection dropped' . (!empty($meta['timed_out']) ? ' (timeout)' : '') . '.');
        }
        $this->transcript[] = '<<< ' . rtrim($line);

        return $line;
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, min(65536, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Failed to read IMAP literal.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('IMAP socket is not connected.');
        }
        $this->transcript[] = '>>> ' . str_replace('"' . ($this->config['password'] ?? '') . '"', '"***"', rtrim($data));
        $written = @fwrite($this->socket, $data);
        if ($written === false || $written < strlen($data)) {
            throw new RuntimeException('Failed to write to the IMAP socket.');
        }
    }

    private function nextTag(): string
    {
        $this->tagCounter++;

        return 'A' . str_pad((string) $this->tagCounter, 4, '0', STR_PAD_LEFT);
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function extractLiteral(string $response): ?string
    {
        if (!preg_match('/\{(\d+)\}\r?\n/', $response, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $length = (int) $matches[1][0];
        $start = (int) $matches[0][1] + strlen($matches[0][0]);

        if ($length === 0) {
            return '';
        }

        return substr($response, $start, $length);
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use RuntimeException;

/**
 * Dependency-free SMTP client (raw sockets).
 *
 * Supports implicit TLS (ssl), STARTTLS (tls), AUTH LOGIN and AUTH PLAIN, and
 * a "log" transport that writes .eml files to storage/outbox for local use.
 */
final class SmtpMailer
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, mixed> */
    private array $smtp;

    /** @var resource|null */
    private $socket = null;

    /** @var list<string> */
    private array $transcript = [];

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) Config::get('mail', []);
        $this->smtp = (array) ($this->config['smtp'] ?? []);
    }

    /**
     * @return array{ok: bool, message_id: ?string, error: ?string, transport: string, stored_path: ?string}
     */
    public function send(string $to, string $rawMime, ?string $envelopeFrom = null): array
    {
        $to = trim($to);
        $from = $envelopeFrom ?? (string) ($this->config['from'] ?? '');
        $messageId = $this->extractMessageId($rawMime);
        $transport = (string) ($this->config['transport'] ?? 'smtp');

        if ($transport === 'log' || $this->smtp['host'] === '' || $this->smtp['host'] === null) {
            $path = $this->writeToOutbox($to, $rawMime);

            return [
                'ok' => true,
                'message_id' => $messageId,
                'error' => null,
                'transport' => 'log',
                'stored_path' => $path,
            ];
        }

        try {
            $this->connect();
            $this->ehlo();

            $encryption = strtolower((string) ($this->smtp['encryption'] ?? 'none'));
            if ($encryption === 'tls' || $encryption === 'starttls') {
                $this->command('STARTTLS', 220);
                $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (@stream_socket_enable_crypto($this->socket, true, $method) !== true) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed.');
                }
                $this->ehlo();
            }

            $username = (string) ($this->smtp['username'] ?? '');
            if ($username !== '') {
                $this->authenticate($username, (string) ($this->smtp['password'] ?? ''));
            }

            $this->command('MAIL FROM:<' . $this->sanitizeAddress($from) . '>', 250);
            $this->command('RCPT TO:<' . $this->sanitizeAddress($to) . '>', [250, 251]);
            $this->command('DATA', 354);

            $payload = MimeBuilder::dotStuff($rawMime);
            $payload = rtrim(str_replace(["\r\n", "\r"], "\n", $payload), "\n");
            $payload = str_replace("\n", "\r\n", $payload) . "\r\n.\r\n";
            $this->write($payload);
            $this->expect([250]);

            $this->quit();

            return [
                'ok' => true,
                'message_id' => $messageId,
                'error' => null,
                'transport' => 'smtp',
                'stored_path' => null,
            ];
        } catch (\Throwable $e) {
            $this->close();
            $this->logFailure($to, $rawMime, $e->getMessage());

            return [
                'ok' => false,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
                'transport' => 'smtp',
                'stored_path' => null,
            ];
        }
    }

    /**
     * Convenience helper for application notifications (plain text email).
     */
    public function sendText(string $to, string $subject, string $body, ?string $replyTo = null): array
    {
        $from = (string) ($this->config['from'] ?? 'abuse@localhost');
        $fromName = (string) ($this->config['from_name'] ?? '');

        $headers = [
            'From' => MimeBuilder::address($from, $fromName),
            'To' => $to,
            'Subject' => MimeBuilder::encodeHeader($subject),
            'Date' => MimeBuilder::dateHeader(),
            'Message-ID' => MimeBuilder::messageId($this->domainOf($from), 'notify'),
            'Auto-Submitted' => 'auto-generated',
            'X-Auto-Response-Suppress' => 'OOF, AutoReply',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
        ];
        if ($replyTo !== null && $replyTo !== '') {
            $headers['Reply-To'] = $replyTo;
        }

        $mime = MimeBuilder::message($headers, MimeBuilder::quotedPrintable($body));

        return $this->send($to, $mime, $from);
    }

    /**
     * @return array<int, string> what the server said, for debugging
     */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /*
     * ---------------------------------------------------------------------
     * Socket plumbing
     * ---------------------------------------------------------------------
     */

    private function connect(): void
    {
        $host = (string) ($this->smtp['host'] ?? '');
        $port = (int) ($this->smtp['port'] ?? 25);
        $encryption = strtolower((string) ($this->smtp['encryption'] ?? 'none'));
        $timeout = max(3, (int) ($this->smtp['timeout'] ?? 15));

        if ($host === '') {
            throw new RuntimeException('SMTP host is not configured.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException(sprintf('Cannot connect to SMTP server %s:%d (%s)', $host, $port, $errstr ?: 'unknown error'));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $timeout);
        $this->expect([220]);
    }

    private function ehlo(): void
    {
        $helo = (string) ($this->smtp['helo'] ?? '');
        if ($helo === '') {
            $helo = $this->domainOf((string) ($this->config['from'] ?? 'localhost')) ?: 'localhost';
        }

        $this->write('EHLO ' . $helo . "\r\n");
        $reply = $this->expect([250]);
        if (stripos($reply, '250') !== 0) {
            throw new RuntimeException('SMTP EHLO rejected: ' . $reply);
        }
    }

    private function authenticate(string $username, string $password): void
    {
        $this->write("AUTH LOGIN\r\n");
        $reply = $this->readReply();
        if ((int) substr($reply, 0, 3) !== 334) {
            // Server does not offer AUTH LOGIN — try AUTH PLAIN instead.
            $this->write('AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password) . "\r\n");
            $this->expect([235]);

            return;
        }

        $this->write(base64_encode($username) . "\r\n");
        $this->expect([334]);
        $this->write(base64_encode($password) . "\r\n");
        $this->expect([235]);
    }

    /**
     * @param string|list<int> $expected
     */
    private function command(string $command, string|array $expected): string
    {
        $this->write($command . "\r\n");
        $reply = $this->expect($expected);
        $code = (int) substr($reply, 0, 3);
        $allowed = is_array($expected) ? $expected : [$expected];
        if (!in_array($code, $allowed, true)) {
            throw new RuntimeException(sprintf('SMTP command "%s" failed: %s', strtok($command, ' ') ?: $command, trim($reply)));
        }

        return $reply;
    }

    /**
     * @param list<int> $codes
     */
    private function expect(array $codes): string
    {
        $reply = $this->readReply();
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException(sprintf('Unexpected SMTP reply (wanted %s): %s', implode('/', $codes), trim($reply)));
        }

        return $reply;
    }

    private function readReply(): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP socket is not connected.');
        }

        $reply = '';
        while (true) {
            $line = fgets($this->socket, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException('SMTP connection dropped' . (!empty($meta['timed_out']) ? ' (timeout)' : '') . '.');
            }
            $reply .= $line;
            $this->transcript[] = '<<< ' . rtrim($line);

            if (strlen($line) < 3 || $line[3] !== '-') {
                break;
            }
        }

        return $reply;
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP socket is not connected.');
        }
        $this->transcript[] = '>>> ' . str_replace(["\r\n", "\n"], ' | ', rtrim($data));
        $written = @fwrite($this->socket, $data);
        if ($written === false || $written < strlen($data)) {
            throw new RuntimeException('Failed to write to the SMTP socket.');
        }
    }

    private function quit(): void
    {
        try {
            $this->write("QUIT\r\n");
            @fgets($this->socket, 1024);
        } catch (\Throwable) {
            // ignore
        }
        $this->close();
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /*
     * ---------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------
     */

    private function writeToOutbox(string $to, string $rawMime): string
    {
        $dir = (string) ($this->config['outbox_path'] ?? storage_path('outbox'));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create outbox directory: ' . $dir);
        }

        $name = sprintf('%s-%s.eml', date('Ymd-His'), bin2hex(random_bytes(4)));
        $path = rtrim($dir, '/') . '/' . $name;

        $header = sprintf(
            "X-Outbox-Recipient: %s\nX-Outbox-Created: %s\n",
            $to,
            date('c')
        );

        if (@file_put_contents($path, $header . $rawMime) === false) {
            throw new RuntimeException('Cannot write to outbox: ' . $path);
        }

        return $path;
    }

    private function logFailure(string $to, string $rawMime, string $error): void
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . '/mail.log',
            sprintf("[%s] FAILED to=%s error=%s\n", date('c'), $to, $error),
            FILE_APPEND
        );
        // Keep the message for manual re-sending.
        try {
            $this->writeToOutbox($to, $rawMime);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function extractMessageId(string $rawMime): ?string
    {
        if (preg_match('/^Message-ID:\s*(<[^>]+>)/mi', $rawMime, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function sanitizeAddress(string $address): string
    {
        $address = trim(str_replace(["\r", "\n", '<', '>', ' '], '', $address));
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address: ' . $address);
        }

        return $address;
    }

    private function domainOf(string $email): string
    {
        $parts = explode('@', $email);

        return (string) ($parts[1] ?? 'localhost');
    }
}

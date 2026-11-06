<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Small helpers for building RFC 5322 / MIME messages without any library.
 */
final class MimeBuilder
{
    public const CRLF = "\r\n";

    public static function boundary(string $prefix = '=_abr'): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    /**
     * Normalise all line endings to CRLF.
     */
    public static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace("\n", self::CRLF, $text);
    }

    /**
     * RFC 2047 encode a header value when it contains non-ASCII characters.
     */
    public static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/[\x80-\xFF]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * Encode a display name + address: "Name" <mail@example.com>
     */
    public static function address(string $email, ?string $name = null): string
    {
        $email = trim(str_replace(["\r", "\n", '<', '>'], '', $email));
        $name = trim((string) $name);
        if ($name === '') {
            return $email;
        }

        $encoded = preg_match('/[\x80-\xFF]/', $name) === 1
            ? self::encodeHeader($name)
            : '"' . str_replace(['"', '\\'], ['', ''], $name) . '"';

        return $encoded . ' <' . $email . '>';
    }

    /**
     * Build a single MIME part.
     *
     * @param array<string, string> $headers
     */
    public static function part(array $headers, string $body): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode(self::CRLF, $lines) . self::CRLF . self::CRLF . $body;
    }

    /**
     * A text part with the right transfer encoding applied.
     *
     * @param array<string, string> $extraHeaders
     */
    public static function textPart(string $text, string $contentType = 'text/plain; charset=utf-8', array $extraHeaders = []): string
    {
        $headers = array_merge([
            'Content-Type' => $contentType,
            'Content-Transfer-Encoding' => 'quoted-printable',
        ], $extraHeaders);

        return self::part($headers, self::quotedPrintable($text));
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    public static function binaryPart(string $data, string $contentType, string $filename = '', array $extraHeaders = []): string
    {
        $headers = [
            'Content-Type' => $contentType . ($filename !== '' ? '; name="' . self::sanitizeFilename($filename) . '"' : ''),
            'Content-Transfer-Encoding' => 'base64',
        ];
        if ($filename !== '') {
            $headers['Content-Disposition'] = 'attachment; filename="' . self::sanitizeFilename($filename) . '"';
        }
        $headers = array_merge($headers, $extraHeaders);

        return self::part($headers, chunk_split(base64_encode($data), 76, self::CRLF));
    }

    /**
     * Assemble a multipart container.
     *
     * @param list<string> $parts already-rendered MIME parts
     */
    public static function multipart(
        string $subtype,
        array $parts,
        string $boundary,
        array $params = [],
        array $headers = []
    ): string {
        $contentType = 'multipart/' . $subtype;
        foreach ($params as $name => $value) {
            $contentType .= '; ' . $name . '="' . $value . '"';
        }
        $contentType .= '; boundary="' . $boundary . '"';

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Content-Type: ' . $contentType;
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = '';

        $out = implode(self::CRLF, $lines);
        foreach ($parts as $part) {
            $out .= '--' . $boundary . self::CRLF . $part . self::CRLF;
        }
        $out .= '--' . $boundary . '--' . self::CRLF;

        return $out;
    }

    /**
     * Assemble a complete message: top headers + body.
     *
     * @param array<string, string> $headers
     */
    public static function message(array $headers, string $body): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . str_replace(["\r", "\n"], ' ', $value);
        }

        return implode(self::CRLF, $lines) . self::CRLF . self::CRLF . self::normalize($body);
    }

    public static function quotedPrintable(string $text): string
    {
        $encoded = quoted_printable_encode(self::normalize($text));

        return $encoded;
    }

    /**
     * Dot-stuffing for the SMTP DATA command (RFC 5321 §4.5.2).
     */
    public static function dotStuff(string $message): string
    {
        $message = self::normalize($message);

        return preg_replace('/(?m)^\./', '..', $message) ?? $message;
    }

    public static function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(["\r", "\n", '"'], '', $filename);

        return $filename === '' ? 'attachment.bin' : $filename;
    }

    /**
     * Generate a unique Message-ID.
     */
    public static function messageId(string $domain = 'localhost', string $prefix = 'abr'): string
    {
        $domain = preg_replace('/[^a-z0-9.\-]/i', '', $domain) ?: 'localhost';

        return sprintf('<%s.%s.%s@%s>', $prefix, date('YmdHis'), bin2hex(random_bytes(8)), $domain);
    }

    public static function dateHeader(): string
    {
        return date('D, d M Y H:i:s O');
    }
}

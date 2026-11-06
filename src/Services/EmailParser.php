<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Parses a raw RFC 5322 / MIME message into structured data.
 *
 * Handles multipart messages, nested parts, base64/quoted-printable transfer
 * encodings, RFC 2047 encoded words and charset conversion to UTF-8.
 */
final class EmailParser
{
    /** @var list<array<string, mixed>> */
    private array $attachments = [];

    private string $textBody = '';

    private string $htmlBody = '';

    /**
     * @return array{
     *   from: string, from_name: string, to: string, cc: string, subject: string,
     *   date: ?string, message_id: string, in_reply_to: string, references: list<string>,
     *   body_text: string, body_html: string, headers: array<string, string>,
     *   attachments: list<array{filename: string, content_type: string, data: string}>, raw: string
     * }
     */
    public static function parse(string $raw, ?string $fallbackDate = null): array
    {
        $parser = new self();

        return $parser->parseRaw($raw, $fallbackDate);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRaw(string $raw, ?string $fallbackDate): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headerBlock, $body] = $this->splitHeaders($raw);

        $headers = $this->parseHeaders($headerBlock);

        $date = $headers['date'] ?? null;
        if ($date !== null) {
            $timestamp = strtotime($date);
            $date = $timestamp === false ? $fallbackDate : date('Y-m-d H:i:s', $timestamp);
        }
        $date = $date ?? $fallbackDate;

        $fromRaw = $this->decodeHeaderValue($headers['from'] ?? '');
        $fromAddress = $this->firstAddress($fromRaw);
        $fromName = $this->displayName($fromRaw);

        $references = $this->parseReferences($headers['references'] ?? '');

        $this->textBody = '';
        $this->htmlBody = '';
        $this->attachments = [];

        $this->walk($headers, $body);

        $text = trim($this->textBody);
        $html = trim($this->htmlBody);
        if ($text === '' && $html !== '') {
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return [
            'from' => $fromAddress,
            'from_name' => $fromName,
            'to' => $this->firstAddress($this->decodeHeaderValue($headers['to'] ?? '')),
            'cc' => $this->decodeHeaderValue($headers['cc'] ?? ''),
            'subject' => trim($this->decodeHeaderValue($headers['subject'] ?? '')) ?: '(no subject)',
            'date' => $date,
            'message_id' => trim(trim($headers['message-id'] ?? ''), " \t<>"),
            'in_reply_to' => trim(trim($headers['in-reply-to'] ?? ''), " \t<>"),
            'references' => $references,
            'body_text' => $text,
            'body_html' => $html,
            'headers' => $headers,
            'attachments' => $this->attachments,
            'raw' => $raw,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitHeaders(string $raw): array
    {
        $position = strpos($raw, "\n\n");
        if ($position === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $position), substr($raw, $position + 2)];
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $block): array
    {
        // Unfold continuation lines.
        $block = preg_replace('/\n[ \t]+/', ' ', $block) ?? $block;
        $headers = [];

        foreach (explode("\n", $block) as $line) {
            if (trim($line) === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            if (isset($headers[$name])) {
                if ($name === 'references' || $name === 'received') {
                    $headers[$name] .= ' ' . $value;
                }
                continue;
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function walk(array $headers, string $body): void
    {
        $contentType = $headers['content-type'] ?? 'text/plain';
        [$mime, $params] = $this->parseContentType($contentType);

        if (str_starts_with($mime, 'multipart/')) {
            $boundary = $params['boundary'] ?? '';
            if ($boundary === '') {
                $this->textBody .= $body;

                return;
            }
            $depth = 0;
            foreach ($this->splitParts($body, $boundary) as $part) {
                $depth++;
                if ($depth > 40) {
                    break;
                }
                [$partHeaderBlock, $partBody] = $this->splitHeaders($part);
                $partHeaders = $this->parseHeaders($partHeaderBlock);
                $this->walk($partHeaders, $partBody);
            }

            return;
        }

        $decoded = $this->decodeBody($body, $headers['content-transfer-encoding'] ?? '');

        $disposition = strtolower($headers['content-disposition'] ?? '');
        $filename = $params['name'] ?? null;
        if (preg_match('/filename\*?=(?:"([^"]+)"|([^;]+))/i', $disposition, $m)) {
            $filename = trim($m[1] !== '' ? $m[1] : $m[2], " \t\"'");
            $filename = rawurldecode(str_replace("''", '', $filename));
        }

        $isAttachment = str_contains($disposition, 'attachment')
            || ($filename !== null && $filename !== '' && !str_starts_with($mime, 'text/'));

        if ($isAttachment) {
            $this->attachments[] = [
                'filename' => (string) ($filename ?: 'attachment.bin'),
                'content_type' => $mime,
                'data' => $decoded,
            ];

            return;
        }

        if ($mime === 'text/html') {
            $this->htmlBody .= $this->toUtf8($decoded, $params['charset'] ?? 'utf-8');

            return;
        }

        if ($mime === 'text/plain' || str_starts_with($mime, 'text/')) {
            $this->textBody .= $this->toUtf8($decoded, $params['charset'] ?? 'utf-8');
        }
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseContentType(string $value): array
    {
        $parts = explode(';', $value);
        $mime = strtolower(trim(array_shift($parts) ?: 'text/plain'));
        $params = [];
        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$key, $val] = explode('=', $part, 2);
            $params[strtolower(trim($key))] = trim(trim($val), '"');
        }

        return [$mime, $params];
    }

    /**
     * @return list<string>
     */
    private function splitParts(string $body, string $boundary): array
    {
        $parts = [];
        $segments = explode('--' . $boundary, $body);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '--' || str_starts_with($segment, '--')) {
                continue;
            }
            $segment = preg_replace('/^\n/', '', $segment) ?? $segment;
            $segment = preg_replace('/\n$/', '', $segment) ?? $segment;
            $parts[] = $segment;
        }

        return $parts;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        $encoding = strtolower(trim($encoding));

        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? $body, false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodeHeaderValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            function (array $m): string {
                $charset = $m[1];
                $encoding = strtoupper($m[2]);
                $payload = $m[3];
                $text = $encoding === 'B'
                    ? (string) base64_decode($payload, false)
                    : quoted_printable_decode(str_replace('_', ' ', $payload));

                return $this->toUtf8($text, $charset);
            },
            $value
        );

        return trim((string) $decoded);
    }

    private function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset));
        if ($charset === '' || in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }
        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT', $text);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return $text;
    }

    private function firstAddress(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m)) {
            return strtolower($m[0]);
        }

        return '';
    }

    private function displayName(string $value): string
    {
        if (preg_match('/^"?([^"<]*)"?\s*<[^>]+>/', $value, $m)) {
            $name = trim($m[1], " \t\"");

            return $name === '' ? '' : $name;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function parseReferences(string $value): array
    {
        if (preg_match_all('/<([^>]+)>/', $value, $matches)) {
            return array_values(array_unique($matches[1]));
        }

        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Uploaded evidence files (screenshots, .eml, headers, documents).
 */
final class Evidence extends Model
{
    protected static string $table = 'evidence';

    /**
     * @return list<self>
     */
    public static function forTicket(int $ticketId): array
    {
        return self::where('ticket_id', $ticketId, 'id ASC');
    }

    public static function store(
        int $ticketId,
        string $originalName,
        string $storedPath,
        ?string $mime,
        int $size,
        string $uploadedBy = 'reporter',
        ?string $filetype = null
    ): self {
        $filename = basename($originalName) ?: 'evidence.bin';
        $filetype = $filetype ?? self::guessType($filename, $mime);
        $hash = is_file($storedPath) ? (string) hash_file('sha256', $storedPath) : null;

        $id = self::db()->insert(
            'INSERT INTO evidence (ticket_id, filename, filepath, filetype, filesize, mime_type, sha256, uploaded_by)
             VALUES (:ticket_id, :filename, :filepath, :filetype, :filesize, :mime_type, :sha256, :uploaded_by)
             RETURNING id',
            [
                'ticket_id' => $ticketId,
                'filename' => str_limit($filename, 240, ''),
                'filepath' => $storedPath,
                'filetype' => $filetype,
                'filesize' => $size,
                'mime_type' => $mime,
                'sha256' => $hash,
                'uploaded_by' => $uploadedBy,
            ]
        );

        $evidence = self::find($id);
        if ($evidence === null) {
            throw new \RuntimeException('Failed to store evidence record.');
        }

        return $evidence;
    }

    private static function guessType(string $filename, ?string $mime): string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'eml' || $ext === 'msg') {
            return 'eml';
        }
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            return 'screenshot';
        }
        if (in_array($ext, ['txt', 'log', 'har'], true) || ($mime !== null && str_starts_with($mime, 'text/'))) {
            return 'header';
        }

        return 'document';
    }

    public function absolutePath(): string
    {
        $path = (string) ($this->attributes['filepath'] ?? '');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    public function exists(): bool
    {
        return is_file($this->absolutePath());
    }

    public function size(): int
    {
        return (int) ($this->attributes['filesize'] ?? 0);
    }

    public function originalName(): string
    {
        return (string) ($this->attributes['filename'] ?? 'evidence.bin');
    }

    public function isImage(): bool
    {
        $mime = (string) ($this->attributes['mime_type'] ?? '');
        if (str_starts_with($mime, 'image/')) {
            return true;
        }
        $ext = strtolower((string) pathinfo($this->originalName(), PATHINFO_EXTENSION));

        return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }
}

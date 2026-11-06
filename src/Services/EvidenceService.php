<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Evidence;
use RuntimeException;

/**
 * Validates and stores evidence files outside the web root.
 */
final class EvidenceService
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) Config::get('app.uploads', []);
    }

    /**
     * Validate an uploaded file. Returns an error message or null when valid.
     *
     * @param array{name: string, type?: string, tmp_name: string, error?: int, size: int} $file
     */
    public function validate(array $file): ?string
    {
        $maxSize = (int) ($this->config['max_size'] ?? 10485760);
        if (($file['size'] ?? 0) > $maxSize) {
            return sprintf('File "%s" is larger than %s.', $file['name'] ?? 'upload', format_bytes($maxSize));
        }
        if (($file['size'] ?? 0) === 0) {
            return sprintf('File "%s" is empty.', $file['name'] ?? 'upload');
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = (array) ($this->config['allowed_extensions'] ?? []);
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            return sprintf('File type ".%s" is not allowed.', $extension === '' ? '?' : $extension);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp !== '' && is_file($tmp) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $tmp);
                finfo_close($finfo);
                $allowedMimes = (array) ($this->config['allowed_mimes'] ?? []);
                if ($mime !== '' && $allowedMimes !== [] && !in_array($mime, $allowedMimes, true)
                    && !str_starts_with($mime, 'image/') && !str_starts_with($mime, 'text/')) {
                    return sprintf('File "%s" has an unsupported content type (%s).', $file['name'] ?? 'upload', $mime);
                }
            }
        }

        return null;
    }

    /**
     * @param array{name: string, type?: string, tmp_name: string, error?: int, size: int} $file
     */
    public function storeUploaded(int $ticketId, array $file, string $uploadedBy = 'reporter'): Evidence
    {
        $error = $this->validate($file);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $target = $this->targetPath((string) $file['name']);
        $tmp = (string) $file['tmp_name'];

        $moved = false;
        if (is_uploaded_file($tmp)) {
            $moved = move_uploaded_file($tmp, $target);
        } else {
            $moved = @copy($tmp, $target);
        }
        if (!$moved) {
            throw new RuntimeException('Could not store the uploaded file.');
        }

        @chmod($target, 0640);

        return Evidence::store(
            $ticketId,
            (string) $file['name'],
            $this->relativePath($target),
            (string) ($file['type'] ?? '') ?: null,
            (int) ($file['size'] ?? filesize($target)),
            $uploadedBy
        );
    }

    /**
     * Store generated content (e.g. the raw intake email as .eml).
     */
    public function storeRaw(
        int $ticketId,
        string $filename,
        string $content,
        string $mime = 'message/rfc822',
        string $uploadedBy = 'system',
        ?string $filetype = null
    ): Evidence {
        $target = $this->targetPath($filename);
        if (@file_put_contents($target, $content) === false) {
            throw new RuntimeException('Could not write the evidence file.');
        }
        @chmod($target, 0640);

        return Evidence::store($ticketId, $filename, $this->relativePath($target), $mime, strlen($content), $uploadedBy, $filetype);
    }

    public function uploadDirectory(): string
    {
        $dir = (string) ($this->config['path'] ?? storage_path('uploads'));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the upload directory: ' . $dir);
        }

        return $dir;
    }

    private function targetPath(string $originalName): string
    {
        $dir = $this->uploadDirectory();
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

        return rtrim($dir, '/') . '/' . $name;
    }

    private function relativePath(string $absolutePath): string
    {
        $base = base_path();
        if (str_starts_with($absolutePath, $base . '/')) {
            return substr($absolutePath, strlen($base) + 1);
        }

        return $absolutePath;
    }
}

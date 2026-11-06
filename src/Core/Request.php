<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish HTTP request abstraction.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $body;

    /** @var array<string, mixed> */
    private array $files;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        array $query = [],
        array $body = [],
        array $files = [],
        array $headers = [],
        private readonly string $ip = '0.0.0.0'
    ) {
        $this->query = $query;
        $this->body = $body;
        $this->files = $files;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public static function capture(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Normalise: strip trailing slash (except root) and collapse slashes.
        $path = '/' . trim(preg_replace('#/+#', '/', $path) ?? '/', '/');
        if ($path === '/') {
            $path = '/';
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = str_replace('_', '-', substr((string) $key, 5));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $candidate = trim(explode(',', $forwarded)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }

        return new self($method, $path, $_GET, $_POST, $_FILES, $headers, $ip);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function userAgent(): string
    {
        return $this->headers['user-agent'] ?? '';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? null;
        if (is_array($value)) {
            return $default;
        }

        return trim((string) ($value ?? $default));
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->body[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $value));
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'])) {
            return null;
        }
        if (is_array($file['tmp_name'])) {
            // Multiple uploads: take the first non-empty entry.
            foreach ($file['tmp_name'] as $index => $tmp) {
                if ($file['error'][$index] === UPLOAD_ERR_OK && $tmp !== '') {
                    return [
                        'name' => (string) $file['name'][$index],
                        'type' => (string) $file['type'][$index],
                        'tmp_name' => (string) $tmp,
                        'error' => (int) $file['error'][$index],
                        'size' => (int) $file['size'][$index],
                    ];
                }
            }

            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        return [
            'name' => (string) $file['name'],
            'type' => (string) $file['type'],
            'tmp_name' => (string) $file['tmp_name'],
            'error' => (int) $file['error'],
            'size' => (int) $file['size'],
        ];
    }

    /**
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public function files(string $key): array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'])) {
            return [];
        }

        $out = [];
        if (is_array($file['tmp_name'])) {
            foreach ($file['tmp_name'] as $index => $tmp) {
                if ((int) $file['error'][$index] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $out[] = [
                    'name' => (string) $file['name'][$index],
                    'type' => (string) $file['type'][$index],
                    'tmp_name' => (string) $tmp,
                    'error' => (int) $file['error'][$index],
                    'size' => (int) $file['size'][$index],
                ];
            }

            return $out;
        }

        if ((int) $file['error'] === UPLOAD_ERR_OK) {
            $out[] = [
                'name' => (string) $file['name'],
                'type' => (string) $file['type'],
                'tmp_name' => (string) $file['tmp_name'],
                'error' => (int) $file['error'],
                'size' => (int) $file['size'],
            ];
        }

        return $out;
    }
}

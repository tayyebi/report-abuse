<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response collector. Rendering happens through View.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private int $status = 200;

    private string $content = '';

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function html(string $html): self
    {
        $this->content = $html;
        $this->headers['Content-Type'] = 'text/html; charset=utf-8';

        return $this;
    }

    public function json(mixed $data, int $status = 200): self
    {
        $this->status = $status;
        $this->content = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';

        return $this;
    }

    public function text(string $text, int $status = 200): self
    {
        $this->status = $status;
        $this->content = $text;
        $this->headers['Content-Type'] = 'text/plain; charset=utf-8';

        return $this;
    }

    /**
     * Set the body verbatim, leaving headers (e.g. Content-Type from a
     * download) untouched. Used for binary file streaming.
     */
    public function raw(string $contents): self
    {
        $this->content = $contents;

        return $this;
    }

    public function download(string $filename, string $mime = 'application/octet-stream'): self
    {
        $this->headers['Content-Type'] = $mime;
        $this->headers['Content-Disposition'] = 'attachment; filename="' . str_replace('"', '', $filename) . '"';
        $this->headers['X-Content-Type-Options'] = 'nosniff';

        return $this;
    }

    public function redirect(string $to, int $status = 302): self
    {
        $this->status = $status;
        $this->headers['Location'] = $to;
        $this->content = '';

        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            header("X-Frame-Options: SAMEORIGIN");
        }

        echo $this->content;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function headerValue(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function statusCode(): int
    {
        return $this->status;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tiny {{placeholder}} template renderer for the files in config/templates.
 */
final class Templates
{
    /**
     * @param array<string, scalar|null> $vars
     */
    public static function render(string $name, array $vars = [], ?string $fallback = null): string
    {
        $file = self::path($name);
        if ($file === null && $fallback !== null) {
            $file = self::path($fallback);
        }
        if ($file === null) {
            return '';
        }

        $template = (string) file_get_contents($file);

        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{' . $key . '}}'] = (string) ($value ?? '');
            $replacements['{{ ' . $key . ' }}'] = (string) ($value ?? '');
        }

        $rendered = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Remove any leftover placeholders so we never leak "{{foo}}" to a recipient.
        return preg_replace('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', '', $rendered) ?? $rendered;
    }

    public static function exists(string $name): bool
    {
        return self::path($name) !== null;
    }

    private static function path(string $name): ?string
    {
        $name = str_replace(['..', "\0"], '', $name);
        $file = base_path('config/templates/' . $name . '.txt');

        return is_file($file) ? $file : null;
    }
}

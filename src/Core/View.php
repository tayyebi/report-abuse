<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP template renderer (no template engine, no dependencies).
 */
final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            return $content;
        }

        $layoutData = array_merge(self::$shared, $data, ['content' => $content]);

        return self::capture($layout, $layoutData);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function capture(string $template, array $data = []): string
    {
        $file = self::resolve($template);
        if ($file === null) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        $data = array_merge(self::$shared, $data);
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    public static function exists(string $template): bool
    {
        return self::resolve($template) !== null;
    }

    private static function resolve(string $template): ?string
    {
        $template = str_replace(['..', "\0"], '', $template);
        $candidates = [
            base_path('src/Views/' . $template . '.php'),
            base_path('src/Views/' . $template),
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}

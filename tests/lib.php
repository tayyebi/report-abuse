<?php

declare(strict_types=1);

/**
 * Tiny assertion helper shared by tests/run.php and tests/integration.php.
 */
function out(string $message): void
{
    echo $message;
}

final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var list<string> */
    public static array $failures = [];
    public static string $suite = '';

    public static function suite(string $name): void
    {
        self::$suite = $name;
        out("\n\033[1m" . $name . "\033[0m\n");
    }

    public static function ok(bool $condition, string $what): void
    {
        if ($condition) {
            self::$passed++;
            out("  \033[32m✓\033[0m " . $what . "\n");

            return;
        }
        self::$failed++;
        $label = self::$suite . ' → ' . $what;
        self::$failures[] = $label;
        out("  \033[31m✗ " . $what . "\033[0m\n");
    }

    public static function same(mixed $expected, mixed $actual, string $what): void
    {
        $ok = $expected === $actual;
        if (!$ok) {
            $what .= sprintf(' (expected %s, got %s)', var_export($expected, true), var_export($actual, true));
        }
        self::ok($ok, $what);
    }

    public static function contains(string $needle, string $haystack, string $what): void
    {
        self::ok(str_contains($haystack, $needle), $what . ' [' . $needle . ']');
    }

    public static function notContains(string $needle, string $haystack, string $what): void
    {
        self::ok(!str_contains($haystack, $needle), $what . ' [!' . $needle . ']');
    }

    public static function summary(): int
    {
        out("\n" . str_repeat('─', 62) . "\n");
        out(sprintf("%d passed, %d failed\n", self::$passed, self::$failed));
        if (self::$failures !== []) {
            out("\nFailures:\n");
            foreach (self::$failures as $failure) {
                out(' - ' . $failure . "\n");
            }
        }

        return self::$failed === 0 ? 0 : 1;
    }
}

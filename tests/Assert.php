<?php

/**
 * Minimal assertion library for the project's test suite.
 * No external dependencies. PHP 7.4+ compatible.
 */
class Assert
{
    /** @var int */
    public static $passed = 0;

    /** @var int */
    public static $failed = 0;

    public static function equal($expected, $actual, string $label = ''): void
    {
        if ($expected === $actual) {
            self::pass($label);
        } else {
            self::fail($label, 'expected ' . self::export($expected) . ', got ' . self::export($actual));
        }
    }

    public static function notEqual($expected, $actual, string $label = ''): void
    {
        if ($expected !== $actual) {
            self::pass($label);
        } else {
            self::fail($label, 'expected value other than ' . self::export($expected));
        }
    }

    public static function true($value, string $label = ''): void
    {
        if ($value === true) {
            self::pass($label);
        } else {
            self::fail($label, 'expected true, got ' . self::export($value));
        }
    }

    public static function false($value, string $label = ''): void
    {
        if ($value === false) {
            self::pass($label);
        } else {
            self::fail($label, 'expected false, got ' . self::export($value));
        }
    }

    public static function throws(callable $fn, string $exceptionClass, string $label = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                self::pass($label);
            } else {
                self::fail(
                    $label,
                    'expected ' . $exceptionClass . ', got ' . get_class($e) . ': ' . $e->getMessage()
                );
            }
            return;
        }
        self::fail($label, 'expected ' . $exceptionClass . ', nothing thrown');
    }

    public static function summary(): void
    {
        echo PHP_EOL . 'Results: ' . self::$passed . ' passed, ' . self::$failed . ' failed.' . PHP_EOL;
        exit(self::$failed > 0 ? 1 : 0);
    }

    private static function pass(string $label): void
    {
        self::$passed++;
        echo '  OK  ' . $label . PHP_EOL;
    }

    private static function fail(string $label, string $detail = ''): void
    {
        self::$failed++;
        echo 'FAIL  ' . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . PHP_EOL;
    }

    private static function export($value): string
    {
        $out = var_export($value, true);
        return str_replace(["\r\n", "\n", "\r"], ' ', $out);
    }
}

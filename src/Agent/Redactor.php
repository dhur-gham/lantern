<?php

declare(strict_types=1);

namespace Lantern\Agent;

final class Redactor
{
    private const SENSITIVE_KEY = '/pass(word)?|secret|token|api[_-]?key|authorization|credential|ssn|card/i';

    /** Replaces every literal in a SQL string with a placeholder. */
    public static function sql(string $sql): string
    {
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);
        $sql = (string) preg_replace('/(?<![\w`"\.])-?\d+(?:\.\d+)?(?![\w`"])/', '?', $sql);
        $sql = (string) preg_replace('/\?(?:\s*,\s*\?)+/', '?, ...', $sql);

        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<int|string, mixed>
     */
    public static function bindings(array $bindings, bool $reveal = false): array
    {
        $out = [];
        foreach ($bindings as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $out[$key] = '[redacted]';

                continue;
            }
            $out[$key] = self::binding($value, $reveal);
        }

        return $out;
    }

    public static function binding(mixed $value, bool $reveal = false): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_string($value) && str_starts_with($value, '[redacted')) {
            return $value;
        }
        if (! $reveal) {
            return match (true) {
                is_int($value), is_float($value) => '[redacted:number]',
                is_string($value) => '[redacted:string]',
                default => '[redacted:'.get_debug_type($value).']',
            };
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return '[redacted:'.get_debug_type($value).']';
        }

        return self::mask($value);
    }

    public static function mask(string $value): string
    {
        $value = (string) preg_replace('/[A-Za-z0-9._%+\-]+@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', '***@$1', $value);
        $value = (string) preg_replace('/\b\d(?:[ -]?\d){12,18}\b/', '[number]', $value);
        $value = (string) preg_replace('/\b[A-Za-z0-9_\-+\/=.]{24,}\b/', '[token]', $value);

        return mb_strlen($value) > 100 ? mb_substr($value, 0, 100).'...' : $value;
    }
}

<?php

declare(strict_types=1);

namespace Lantern\Agent;

use Lantern\Detection\Issue;

final class Findings
{
    public const VERSION = 1;

    public const DEFAULT_FIELDS = ['id', 'type', 'severity', 'status', 'title', 'call_site', 'occurrences', 'total_ms', 'suggestion'];

    public const ALL_FIELDS = ['id', 'type', 'severity', 'status', 'title', 'call_site', 'occurrences', 'count_total', 'total_ms', 'max_ms', 'suggestion', 'first_seen', 'last_seen', 'resolved_at', 'related_trace_ids', 'evidence'];

    /**
     * @param array<string, mixed> $row stored lantern_issues row (as returned by TraceQueries)
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        $trace = $row['last_trace_id'] ?? null;
        $resolvedAt = isset($row['resolved_at']) && $row['resolved_at'] !== '' ? (string) $row['resolved_at'] : null;

        return [
            'id' => (string) ($row['issue_key'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'severity' => (string) ($row['severity'] ?? ''),
            'status' => $resolvedAt === null ? 'open' : 'resolved',
            'title' => (string) ($row['title'] ?? ''),
            'call_site' => self::site($row['call_site'] ?? null),
            'occurrences' => (int) ($row['occurrences'] ?? 0),
            'count_total' => (int) ($row['count_total'] ?? 0),
            'total_ms' => round((float) ($row['total_ms'] ?? 0), 2),
            'max_ms' => round((float) ($row['max_ms'] ?? 0), 2),
            'suggestion' => $row['suggestion'] ?? null,
            'first_seen' => $row['first_seen'] ?? null,
            'last_seen' => $row['last_seen'] ?? null,
            'resolved_at' => $resolvedAt,
            'related_trace_ids' => is_string($trace) && $trace !== '' ? [$trace] : [],
        ];
    }

    /**
     * Compact finding from an issue embedded in a trace payload.
     *
     * @param array<string, mixed> $i
     * @return array<string, mixed>
     */
    public static function brief(array $i): array
    {
        return [
            'id' => self::keyOf($i),
            'shape' => self::shape($i),
            'type' => (string) ($i['type'] ?? ''),
            'severity' => (string) ($i['severity'] ?? ''),
            'title' => (string) ($i['title'] ?? ''),
            'call_site' => self::site($i['call_site'] ?? null),
            'count' => (int) ($i['count'] ?? $i['count_total'] ?? 0),
            'total_ms' => round((float) ($i['total_ms'] ?? 0), 2),
        ];
    }

    /**
     * Identity of a finding that survives edits to the surrounding file: the SQL fingerprint (or model and
     * relation) plus the call-site file and function, never the line. Types whose id is already line independent
     * use the id.
     *
     * @param array<string, mixed> $i
     */
    public static function shape(array $i): string
    {
        $type = (string) ($i['type'] ?? '');
        $meta = (array) ($i['meta'] ?? []);
        $site = self::site($i['call_site'] ?? null);
        $where = $site === null ? '-' : $site['file'].'#'.($site['function'] ?? '');

        $parts = match (true) {
            in_array($type, [Issue::TYPE_N_PLUS_ONE, Issue::TYPE_SLOW], true) && is_string($meta['fingerprint'] ?? null) && $meta['fingerprint'] !== '' => [$meta['fingerprint']],
            $type === Issue::TYPE_LAZY_LOAD && isset($meta['model'], $meta['relation']) => [(string) $meta['model'], (string) $meta['relation']],
            $type === Issue::TYPE_MEMORY => [(string) ($meta['span_type'] ?? ''), preg_replace('/\+[\d.]+ MB/', '', (string) ($i['title'] ?? ''))],
            default => null,
        };

        return $parts === null ? self::keyOf($i) : Issue::key($type, ...[...$parts, $where]);
    }

    /** @param array<string, mixed> $i */
    public static function keyOf(array $i): string
    {
        $key = (string) ($i['key'] ?? $i['issue_key'] ?? '');

        return $key !== '' ? $key : Issue::key((string) ($i['type'] ?? ''), (string) ($i['title'] ?? ''));
    }

    /** @return array{file: string, line: int, function?: string}|null */
    public static function site(mixed $site): ?array
    {
        if (is_string($site)) {
            $site = json_decode($site, true);
        }
        if (! is_array($site) || ! isset($site['file'])) {
            return null;
        }
        $out = ['file' => self::relative((string) $site['file']), 'line' => (int) ($site['line'] ?? 0)];
        if (isset($site['function']) && $site['function'] !== '') {
            $out['function'] = (string) $site['function'];
        }

        return $out;
    }

    /** @param array<string, mixed>|null $site */
    public static function siteString(?array $site): string
    {
        return $site === null ? '-' : $site['file'].':'.$site['line'];
    }

    public static function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $base = str_replace('\\', '/', rtrim(base_path(), '/\\')).'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    public static function rank(string $severity): int
    {
        return match (strtolower($severity)) {
            'critical', 'high', 'error' => 2,
            'warning', 'warn', 'medium' => 1,
            default => 0,
        };
    }
}

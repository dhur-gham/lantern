<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Lantern\Trace\Trace;

final class TraceFormatter
{
    public static function tree(Trace $trace): string
    {
        return self::fromArray($trace->toArray());
    }

    /** @param array<string, mixed> $data A Trace::toArray() payload (also what last-trace.json holds). */
    public static function fromArray(array $data): string
    {
        $status = $data['meta']['status'] ?? null;
        $lines = [sprintf(
            'Trace %s  %.2f ms  peak %.1f MB%s',
            $data['id'] ?? '?',
            (float) ($data['total_ms'] ?? 0),
            ((int) ($data['peak_memory'] ?? 0)) / 1048576,
            $status === null ? '' : '  status '.$status,
        )];

        if (is_array($data['root'] ?? null)) {
            self::node($data['root'], 0, $lines);
        }

        $issues = $data['meta']['issues'] ?? [];
        if (is_array($issues) && $issues !== []) {
            $lines[] = '';
            $lines[] = 'Issues ('.count($issues).')';
            foreach ($issues as $issue) {
                $line = sprintf('- [%s] %s: %s  x%d  %.2f ms', $issue['severity'] ?? '?', $issue['type'] ?? '?', $issue['title'] ?? '?', (int) ($issue['count'] ?? 0), (float) ($issue['total_ms'] ?? 0));
                $site = $issue['call_site'] ?? null;
                if (is_array($site) && isset($site['file'])) {
                    $line .= '  @ '.self::shorten((string) $site['file']).':'.($site['line'] ?? '?');
                }
                $lines[] = $line;
                if (! empty($issue['suggestion'])) {
                    $lines[] = '    fix: '.$issue['suggestion'];
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $lines
     */
    private static function node(array $node, int $depth, array &$lines): void
    {
        $line = sprintf(
            '%s- [%s] %s  %.2f ms (self %.2f ms)',
            str_repeat('  ', $depth),
            $node['type'] ?? '?',
            $node['name'] ?? '?',
            (float) ($node['duration_ms'] ?? 0),
            (float) ($node['self_ms'] ?? 0),
        );

        $site = $node['call_site'] ?? null;
        if (is_array($site) && isset($site['file'])) {
            $line .= '  @ '.self::shorten((string) $site['file']).':'.($site['line'] ?? '?');
        }

        $lines[] = $line;

        foreach ($node['children'] ?? [] as $child) {
            self::node($child, $depth + 1, $lines);
        }
    }

    private static function shorten(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', function_exists('base_path') ? base_path() : (string) getcwd()), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}

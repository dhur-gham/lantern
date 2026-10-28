<?php

declare(strict_types=1);

namespace Lantern\Agent;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

final class Output
{
    public const FORMATS = ['table', 'json', 'ndjson', 'markdown'];

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $columns columns for the items table (table/markdown); null = derive
     */
    public static function render(string $format, array $payload, ?string $items = null, ?array $columns = null, bool $pretty = false): string
    {
        return match ($format) {
            'json' => (string) json_encode($payload, self::JSON | ($pretty ? JSON_PRETTY_PRINT : 0)),
            'ndjson' => self::ndjson($payload, $items),
            'markdown' => self::markdown($payload, $items, $columns),
            default => self::table($payload, $items, $columns),
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null $fields
     * @return list<array<string, mixed>>
     */
    public static function project(array $rows, ?array $fields): array
    {
        if ($fields === null) {
            return $rows;
        }

        return array_map(function (array $row) use ($fields): array {
            $out = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $row)) {
                    $out[$f] = $row[$f];
                }
            }

            return $out;
        }, $rows);
    }

    /** @param array<string, mixed> $payload */
    private static function ndjson(array $payload, ?string $items): string
    {
        if ($items === null || ! isset($payload[$items]) || ! is_array($payload[$items])) {
            return (string) json_encode($payload, self::JSON);
        }
        $lines = [];
        foreach ($payload[$items] as $row) {
            $lines[] = (string) json_encode($row, self::JSON);
        }
        unset($payload[$items]);
        $lines[] = (string) json_encode(['_meta' => $payload], self::JSON);

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, string>, 1: array<string, list<array<string, string>>>}
     */
    private static function split(array $payload, ?string $items, ?array $columns): array
    {
        $scalars = [];
        $sections = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) && $value !== [] && array_is_list($value) && is_array($value[0]) && ! self::isSite($value[0])) {
                $sections[(string) $key] = array_map(fn ($row): array => self::flat((array) $row), $value);

                continue;
            }
            if (is_array($value) && ! array_is_list($value) && ! self::isSite($value)) {
                foreach (self::flat($value, $key.'.') as $k => $v) {
                    $scalars[$k] = $v;
                }

                continue;
            }
            $scalars[(string) $key] = self::cell($value);
        }

        return [$scalars, $sections];
    }

    /** @param array<string, mixed> $payload */
    private static function table(array $payload, ?string $items, ?array $columns): string
    {
        [$scalars, $sections] = self::split($payload, $items, $columns);
        $parts = [];

        if ($scalars !== []) {
            $parts[] = self::drawTable(['Field', 'Value'], array_map(null, array_keys($scalars), array_values($scalars)));
        }
        foreach ($sections as $name => $rows) {
            $cols = $name === $items && $columns !== null ? $columns : self::union($rows);
            $parts[] = (count($sections) > 1 || $scalars !== [] ? $name.":\n" : '').self::drawTable(
                $cols,
                array_map(fn (array $r): array => array_map(fn ($c) => $r[$c] ?? '', $cols), $rows),
            );
        }

        return rtrim(implode("\n", $parts));
    }

    /** @param array<string, mixed> $payload */
    private static function markdown(array $payload, ?string $items, ?array $columns): string
    {
        [$scalars, $sections] = self::split($payload, $items, $columns);
        $out = [];
        foreach ($scalars as $k => $v) {
            $out[] = '- **'.$k.'**: '.str_replace("\n", ' ', $v);
        }
        foreach ($sections as $name => $rows) {
            $cols = $name === $items && $columns !== null ? $columns : self::union($rows);
            $out[] = '';
            $out[] = '### '.$name;
            $out[] = '| '.implode(' | ', $cols).' |';
            $out[] = '|'.str_repeat(' --- |', count($cols));
            foreach ($rows as $r) {
                $out[] = '| '.implode(' | ', array_map(fn ($c) => str_replace(['|', "\n"], ['\\|', ' '], $r[$c] ?? ''), $cols)).' |';
            }
        }

        return trim(implode("\n", $out));
    }

    /**
     * @param list<string> $headers
     * @param list<array<int, string>> $rows
     */
    private static function drawTable(array $headers, array $rows): string
    {
        $buffer = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, false);
        $table = new Table($buffer);
        $table->setHeaders($headers);
        $table->setRows(array_map(fn (array $r): array => array_map(fn ($c): string => OutputFormatter::escape((string) $c), $r), $rows));
        $table->render();

        return rtrim($buffer->fetch());
    }

    /**
     * @param list<array<string, string>> $rows
     * @return list<string>
     */
    private static function union(array $rows): array
    {
        $cols = [];
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) {
                $cols[$k] = true;
            }
        }

        return array_map('strval', array_keys($cols));
    }

    /**
     * @param array<int|string, mixed> $a
     * @return array<string, string>
     */
    public static function flat(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v) && ! array_is_list($v) && ! self::isSite($v)) {
                $out += self::flat($v, $prefix.$k.'.');
            } else {
                $out[$prefix.$k] = self::cell($v);
            }
        }

        return $out;
    }

    /** @param array<mixed> $v */
    private static function isSite(array $v): bool
    {
        return isset($v['file'], $v['line']) && ! is_array($v['file']);
    }

    public static function cell(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'true' : 'false',
            is_float($v) => (string) round($v, 2),
            is_scalar($v) => (string) $v,
            is_array($v) && self::isSite($v) => $v['file'].':'.$v['line'],
            is_array($v) && array_is_list($v) && array_filter($v, 'is_array') === [] => implode(', ', array_map('strval', $v)),
            default => (string) json_encode($v, self::JSON),
        };
    }
}

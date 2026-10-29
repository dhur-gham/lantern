<?php

declare(strict_types=1);

namespace Lantern\Schema;

/**
 * Turns per-column index rows (SHOW INDEX output, one row per indexed column) into one definition per index.
 */
final class IndexNormalizer
{
    /**
     * @param list<array<string, mixed>> $rows keys are matched case-insensitively: table, key_name, seq_in_index, column_name, non_unique
     * @return array<string, list<array{name: string, columns: list<string>, unique: bool}>> by table; rows without a table go under ''
     */
    public static function fromShowIndexRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row);
            $name = trim((string) ($row['key_name'] ?? ''));
            $column = trim((string) ($row['column_name'] ?? ''));
            if ($name === '' || $column === '') {
                continue; // expression/functional key parts have no column name
            }
            $table = trim((string) ($row['table'] ?? ''));
            $grouped[$table][$name]['unique'] = (string) ($row['non_unique'] ?? '1') === '0';
            $grouped[$table][$name]['parts'][] = [(int) ($row['seq_in_index'] ?? 0), $column];
        }

        $out = [];
        foreach ($grouped as $table => $indexes) {
            foreach ($indexes as $name => $def) {
                usort($def['parts'], fn (array $a, array $b): int => $a[0] <=> $b[0]);
                $out[$table][] = ['name' => (string) $name, 'columns' => array_map(fn (array $p): string => $p[1], $def['parts']), 'unique' => $def['unique']];
            }
        }

        return $out;
    }

    /**
     * Parses a pasted SHOW INDEX result (CSV or tab separated, with the header row).
     *
     * @return array<string, list<array{name: string, columns: list<string>, unique: bool}>>
     */
    public static function fromCsv(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($csv)) ?: [], fn (string $l): bool => trim($l) !== ''));
        if ($lines === []) {
            return [];
        }
        $delimiter = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',';
        $header = array_map(fn (?string $h): string => strtolower(trim((string) $h)), str_getcsv(array_shift($lines), $delimiter, '"', ''));
        if (! in_array('key_name', $header, true) || ! in_array('column_name', $header, true)) {
            return [];
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $cells[$i] ?? null;
            }
            $rows[] = $row;
        }

        return self::fromShowIndexRows($rows);
    }
}

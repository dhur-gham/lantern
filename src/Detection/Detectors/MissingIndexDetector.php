<?php

declare(strict_types=1);

namespace Lantern\Detection\Detectors;

use Lantern\Detection\Detector;
use Lantern\Detection\Explain\AnalyzeSource;
use Lantern\Detection\Explain\Catalog;
use Lantern\Detection\Explain\DatabaseCatalog;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Explain\PlanSource;
use Lantern\Detection\Explain\SqlShape;
use Lantern\Detection\Issue;
use Lantern\Detection\QueryFilter;
use Lantern\Schema\ProductionSnapshot;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use Throwable;

/**
 * Flags SELECTs that EXPLAIN reports as full table scans. Opt-in (config explain=true) because it runs extra
 * queries. It runs while the analyzer finalises the trace, i.e. after the request, so it adds no latency to the
 * response but does cost a few EXPLAINs (at most 25 distinct fingerprints per trace, one EXPLAIN each) plus a few
 * catalog reads per table involved.
 *
 * A scan is reported as `index_not_chosen` when an index that starts with a filtered or joined column already
 * exists (on any table in the plan) and the plan did not use it, otherwise as `missing_index` with columns taken
 * only from the scanned table.
 */
final class MissingIndexDetector implements Detector
{
    private const MAX_FINGERPRINTS = 25;

    private const MAX_TABLES = 8;

    private const OR_MESSAGE = 'OR across tables: no single index can serve this; check the plan (EXPLAIN ANALYZE), the join order, or split it into a UNION.';

    private const SCAN_TYPES = ['ALL', 'INDEX', 'RANGE', 'INDEX_MERGE', 'FULLTEXT'];

    private int $analyzed = 0;

    /** @param array<string, mixed> $config full config('lantern') */
    public function __construct(
        private readonly array $config,
        private readonly ?PlanSource $runner = null,
        private readonly ?Catalog $catalog = null,
        private readonly ?AnalyzeSource $analyzer = null,
    ) {
    }

    public function detect(Trace $trace): array
    {
        if (! ($this->config['explain'] ?? false)) {
            return [];
        }
        $this->analyzed = 0;

        $slowMs = (float) ($this->config['thresholds']['slow_query_ms'] ?? 100);
        $explainMs = (float) ($this->config['thresholds']['explain_min_ms'] ?? 0);
        $filter = new QueryFilter($this->config);
        $minRows = (int) ($this->config['explain_min_rows'] ?? 100);

        $groups = [];
        foreach ($trace->spans() as $span) {
            $m = $span->meta;
            if ($span->type !== SpanType::Query || ($m['kind'] ?? null) === 'transaction' || ($m['failed'] ?? false)
                || ! is_string($m['sql'] ?? null) || ! ExplainRunner::isSelect($m['sql']) || $filter->ignores($span)) {
                continue;
            }
            $groups[(string) ($m['fingerprint'] ?? md5($m['sql']))][] = $span;
        }

        $candidates = [];
        foreach ($groups as $fp => $spans) {
            $max = max(array_map(fn (Span $s) => (float) ($s->meta['time_ms'] ?? 0), $spans));
            if ($max >= min($explainMs, $slowMs)) {
                $candidates[$fp] = [$max, $spans];
            }
        }
        // The slowest shapes first: the per-trace EXPLAIN and replay budgets must reach the heaviest queries, not
        // whichever ran first (a stable sort keeps the execution order between equally slow ones).
        uasort($candidates, fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $candidates = array_map(fn (array $c) => $c[1], $candidates);

        $runner = $this->runner ?? new ExplainRunner;
        $catalog = $this->catalog ?? new DatabaseCatalog;
        $issues = [];
        foreach (array_slice($candidates, 0, self::MAX_FINGERPRINTS, true) as $fp => $spans) {
            try {
                $issue = $this->analyse((string) $fp, $spans, $runner, $catalog, $minRows);
            } catch (Throwable) {
                continue; // catalog or plan trouble must never break the trace
            }
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /** @param list<Span> $spans */
    private function analyse(string $fp, array $spans, PlanSource $runner, Catalog $catalog, int $minRows): ?Issue
    {
        $first = $spans[0];
        $sql = (string) $first->meta['sql'];
        $connection = isset($first->meta['connection']) ? (string) $first->meta['connection'] : null;

        $shape = SqlShape::parse($sql);
        $names = array_values(array_unique(array_map(
            fn (array $p): string => strtolower($p['column']),
            array_filter($shape->predicates, fn (array $p): bool => $p['kind'] !== 'skip'),
        )));
        if ($names === [] || $names === ['id']) {
            return null; // primary-key lookup, or no predicate an index could serve
        }

        // With the raw values the plan is the one the query really got; stored bindings may be redacted or absent
        // (NULL parameters can turn a filter into an impossible WHERE and hide the scan).
        $replay = $this->rawReplay($spans);
        $plan = $runner->explain($connection, $replay[0] ?? $sql, $replay[1] ?? (array) ($first->meta['bindings'] ?? []));
        if ($plan === null || ! $plan['full_scan'] || $plan['table'] === null) {
            return null;
        }
        $scanned = $shape->table($plan['table']);
        $tables = $this->planTables($plan, $shape);
        $stats = [];
        foreach ($tables as $t) {
            $stats[strtolower($t['table'])] = $catalog->stats($connection, $t['table']);
        }
        $tables = $this->withTableRows($tables, $stats);
        $scanRows = $plan['rows'];
        foreach ($tables as $t) {
            if (strcasecmp($t['table'], $scanned) === 0 && $t['rows'] !== null) {
                $scanRows = max($scanRows ?? 0, $t['rows']);
            }
        }
        // sqlite has no row estimate: a scan is only suspect when a WHERE column predicate exists.
        if ($scanRows !== null && $scanRows <= $minRows) {
            return null;
        }
        $plan['rows'] = $scanRows;
        $byTable = $this->predicatesByTable($shape->usable(), $tables, $stats);
        $meta = [
            'tables' => $tables,
            'rows_read' => $this->rowsRead($tables),
            'rows_returned' => null,
            'columns_by_table' => array_map(fn (array $preds): array => array_values(array_unique(array_column($preds, 'column'))), $byTable),
        ];

        $summary = sprintf(
            'full scan on %s%s%s',
            $scanned,
            $plan['rows'] !== null ? ", ~{$plan['rows']} rows" : '',
            $plan['possible_keys'] ? ", possible keys: {$plan['possible_keys']}" : '',
        );
        $callSite = $first->callSite;
        $spanIds = array_values(array_map(fn (Span $s) => $s->id, $spans));
        $count = count($spans);
        $totalMs = array_sum(array_map(fn (Span $s) => (float) ($s->meta['time_ms'] ?? 0), $spans));

        $production = $this->production($connection, $catalog, $tables);
        if ($production['projected'] !== []) {
            $meta['projected'] = $production['projected'];
        }
        if ($production['schema_note'] !== null) {
            $meta['schema_note'] = $production['schema_note'];
        }
        $severity = $production['critical'] ? Issue::SEV_CRITICAL : Issue::SEV_WARNING;
        $projection = $production['text'] !== null ? ' '.$production['text'].'.' : '';
        if ($projection !== '') {
            $summary .= ', '.$production['text'];
        }

        $unused = $this->indexNotChosen($connection, $catalog, $tables, $byTable, strtolower($scanned));
        if ($unused !== null) {
            return new Issue(
                Issue::TYPE_INDEX_NOT_CHOSEN,
                Issue::key(Issue::TYPE_INDEX_NOT_CHOSEN, $fp),
                "Index exists but was not chosen: {$unused['table']}.{$unused['index']}",
                $severity,
                $count,
                $totalMs,
                $callSite,
                $spanIds,
                ['table' => $unused['table'], 'index' => $unused['index'], 'index_columns' => $unused['columns'], 'used_key' => $unused['used_key'], 'scanned_table' => $scanned, 'plan' => $summary] + $this->withReturned($meta, $runner, $connection, $spans),
                "The optimizer chose a different plan (full scan on {$scanned}). Check it with EXPLAIN ANALYZE, force the order with a join-order hint, or refresh statistics with ANALYZE TABLE {$unused['table']}.".$projection,
            );
        }

        if ($shape->orAcrossTables) {
            return new Issue(
                Issue::TYPE_MISSING_INDEX,
                Issue::key(Issue::TYPE_MISSING_INDEX, $fp),
                "Full scan on {$scanned}: OR across tables",
                $severity,
                $count,
                $totalMs,
                $callSite,
                $spanIds,
                ['table' => $scanned, 'columns' => [], 'or_across_tables' => true, 'plan' => $summary, 'rows' => $plan['rows']] + $this->withReturned($meta, $runner, $connection, $spans),
                self::OR_MESSAGE.$projection,
            );
        }

        $preds = $byTable[strtolower($scanned)] ?? [];
        if ($preds === []) {
            return null;
        }
        $suggestion = $this->suggest($preds, $stats[strtolower($scanned)] ?? ['rows' => null, 'columns' => []], $catalog->indexes($connection, $scanned));
        if ($suggestion === null) {
            return null;
        }

        return new Issue(
            Issue::TYPE_MISSING_INDEX,
            Issue::key(Issue::TYPE_MISSING_INDEX, $fp),
            'Possible missing index on '.$scanned,
            $severity,
            $count,
            $totalMs,
            $callSite,
            $spanIds,
            ['table' => $scanned, 'columns' => $suggestion['columns'], 'plan' => $summary, 'rows' => $plan['rows']] + $this->withReturned($meta, $runner, $connection, $spans),
            ($suggestion['columns'] === []
                ? 'The filtered columns have too few distinct values for an index on them to help ('.implode(', ', $suggestion['skipped']).'); look at the rest of the query'
                : 'Consider an index on ('.implode(', ', $suggestion['columns']).')').$projection,
        );
    }

    /**
     * Scans projected onto production table sizes, and whether local indexes differ from the production snapshot.
     * Best effort: without a snapshot or configured row counts this returns nothing, and it never throws.
     *
     * @param  list<array{table: string, rows: ?int, key: ?string, type: ?string}>  $tables
     * @return array{projected: list<array{table: string, local_rows: int, production_rows: int, factor: float}>, critical: bool, text: ?string, schema_note: ?string}
     */
    private function production(?string $connection, Catalog $catalog, array $tables): array
    {
        $none = ['projected' => [], 'critical' => false, 'text' => null, 'schema_note' => null];

        try {
            $snapshot = ProductionSnapshot::load();
            $configured = (array) ($this->config['production_row_counts'] ?? []);
            $limit = (int) ($this->config['thresholds']['production_scan_rows'] ?? 500000);

            $projected = [];
            $notes = [];
            foreach ($tables as $t) {
                $name = $t['table'];
                if (in_array(strtoupper((string) $t['type']), self::SCAN_TYPES, true) && $t['rows'] !== null && $t['rows'] > 0) {
                    $prod = $snapshot?->rowCount($name) ?? $snapshot?->rowCount(strtolower($name)) ?? $configured[$name] ?? $configured[strtolower($name)] ?? null;
                    if (is_numeric($prod) && (int) $prod > 0) {
                        $projected[] = ['table' => $name, 'local_rows' => $t['rows'], 'production_rows' => (int) $prod, 'factor' => round((int) $prod / $t['rows'], 1)];
                    }
                }
                if ($snapshot !== null && ($snapshot->indexesFor($name) !== [] || $snapshot->indexesFor(strtolower($name)) !== [])) {
                    $local = array_column($catalog->indexes($connection, $name), 'name');
                    $note = $snapshot->differsFor($name, $local) ?? $snapshot->differsFor(strtolower($name), $local);
                    if ($note !== null) {
                        $notes[] = $name.': '.$note;
                    }
                }
            }
        } catch (Throwable) {
            return $none;
        }

        $text = null;
        $critical = false;
        if ($projected !== []) {
            usort($projected, fn (array $a, array $b): int => $b['production_rows'] <=> $a['production_rows']);
            $top = $projected[0];
            $text = sprintf('reads %s rows here, about %s (%sx) in production', self::human($top['local_rows']), self::human($top['production_rows']), self::factor($top['factor']));
            $critical = $limit > 0 && $top['production_rows'] >= $limit;
        }

        return ['projected' => $projected, 'critical' => $critical, 'text' => $text, 'schema_note' => $notes === [] ? null : implode('; ', $notes)];
    }

    private static function human(int $n): string
    {
        return match (true) {
            $n >= 1_000_000 => rtrim(rtrim(number_format($n / 1_000_000, 1, '.', ''), '0'), '.').'M',
            $n >= 1_000 => (string) round($n / 1_000).'k',
            default => (string) $n,
        };
    }

    private static function factor(float $f): string
    {
        return $f >= 10 ? (string) round($f) : rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
    }

    /**
     * Real tables in the plan, EXPLAIN aliases resolved through the SQL.
     *
     * @param  array<string, mixed>  $plan
     * @return list<array{table: string, rows: ?int, key: ?string, type: ?string}>
     */
    private function planTables(array $plan, SqlShape $shape): array
    {
        $rows = $plan['tables'] ?? [];
        if ($rows === []) {
            $rows = [['table' => (string) $plan['table'], 'rows' => $plan['rows'], 'key' => $plan['key'], 'type' => 'ALL']];
        }

        $out = [];
        foreach ($rows as $r) {
            $table = ($r['alias'] ?? null) !== null ? (string) $r['table'] : $shape->table((string) $r['table']);
            $out[strtolower($table)] ??= ['table' => $table, 'rows' => $r['rows'] ?? null, 'key' => $r['key'] ?? null, 'type' => $r['type'] ?? null];
        }

        return array_slice(array_values($out), 0, self::MAX_TABLES);
    }

    /**
     * PostgreSQL reports a seq scan's row estimate after its filter, which understates what is read; a scanned
     * table is at least as big as the catalog says.
     *
     * @param  list<array{table: string, rows: ?int, key: ?string, type: ?string}>  $tables
     * @param  array<string, array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}>  $stats
     * @return list<array{table: string, rows: ?int, key: ?string, type: ?string}>
     */
    private function withTableRows(array $tables, array $stats): array
    {
        foreach ($tables as $i => $t) {
            $size = $stats[strtolower($t['table'])]['rows'] ?? null;
            if ($size !== null && $size > ($t['rows'] ?? 0) && in_array(strtoupper((string) $t['type']), self::SCAN_TYPES, true)) {
                $tables[$i]['rows'] = $size;
            }
        }

        return $tables;
    }

    /** @param list<array{table: string, rows: ?int, key: ?string, type: ?string}> $tables */
    private function rowsRead(array $tables): ?int
    {
        $scans = array_filter($tables, fn (array $t): bool => in_array(strtoupper((string) $t['type']), self::SCAN_TYPES, true));
        $use = $scans !== [] ? $scans : $tables;
        $known = array_filter(array_column($use, 'rows'), fn ($r) => $r !== null);

        return $known === [] ? null : (int) array_sum($known);
    }

    /**
     * Group predicates by their real table; unqualified columns go to the one plan table that has the column.
     *
     * @param  list<array{table: ?string, column: string, kind: string, join: bool}>  $predicates
     * @param  list<array{table: string, rows: ?int, key: ?string, type: ?string}>  $tables
     * @param  array<string, array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}>  $stats
     * @return array<string, list<array{column: string, kind: string, join: bool}>>
     */
    private function predicatesByTable(array $predicates, array $tables, array $stats): array
    {
        $out = [];
        foreach ($predicates as $p) {
            $table = $p['table'];
            if ($table === null) {
                $owners = array_filter($tables, fn (array $t): bool => isset($stats[strtolower($t['table'])]['columns'][strtolower($p['column'])]));
                if (count($owners) !== 1) {
                    continue;
                }
                $table = array_values($owners)[0]['table'];
            }
            $out[strtolower($table)][] = ['column' => $p['column'], 'kind' => $p['kind'], 'join' => $p['join']];
        }

        return $out;
    }

    /**
     * An index that begins with a filtered (or, off the scanned table, joined) column and that the plan did not
     * use. Filter-led candidates beat join-led ones; a table whose chosen key already leads with a filtered
     * column is considered served.
     *
     * @param  list<array{table: string, rows: ?int, key: ?string, type: ?string}>  $tables
     * @param  array<string, list<array{column: string, kind: string, join: bool}>>  $byTable
     * @return array{table: string, index: string, columns: list<string>, used_key: ?string}|null
     */
    private function indexNotChosen(?string $connection, Catalog $catalog, array $tables, array $byTable, string $scanned): ?array
    {
        usort($tables, fn (array $a, array $b): int => (strtolower($b['table']) === $scanned) <=> (strtolower($a['table']) === $scanned));

        $best = null;
        foreach ($tables as $position => $t) {
            $lower = strtolower($t['table']);
            $preds = $byTable[$lower] ?? [];
            $indexes = $preds === [] ? [] : $catalog->indexes($connection, $t['table']);
            if ($indexes === []) {
                continue;
            }

            $all = array_values(array_unique(array_map(fn (array $p): string => strtolower($p['column']), $preds)));
            $filters = array_values(array_unique(array_map(
                fn (array $p): string => strtolower($p['column']),
                array_filter($preds, fn (array $p): bool => ! $p['join']),
            )));

            $used = $t['key'];
            $usedLead = null;
            foreach ($indexes as $idx) {
                if ($used !== null && strcasecmp($idx['name'], $used) === 0) {
                    $usedLead = strtolower($idx['columns'][0] ?? '');
                }
            }
            if ($usedLead !== null && in_array($usedLead, $filters, true)) {
                continue;
            }

            foreach ($indexes as $idx) {
                $lead = strtolower($idx['columns'][0] ?? '');
                $isFilter = in_array($lead, $filters, true);
                $allowed = $lower === $scanned ? $isFilter : in_array($lead, $all, true);
                if ($lead === '' || ! $allowed || ($used !== null && strcasecmp($idx['name'], $used) === 0) || $lead === $usedLead) {
                    continue;
                }
                $prefix = 0;
                foreach ($idx['columns'] as $c) {
                    if (! in_array(strtolower($c), $all, true)) {
                        break;
                    }
                    $prefix++;
                }
                $rank = [$isFilter ? 1 : 0, $prefix, -$position];
                if ($best === null || $rank > $best['rank']) {
                    $best = ['rank' => $rank, 'table' => $t['table'], 'index' => $idx['name'], 'columns' => $idx['columns'], 'used_key' => $used];
                }
            }
        }

        return $best === null ? null : ['table' => $best['table'], 'index' => $best['index'], 'columns' => $best['columns'], 'used_key' => $best['used_key']];
    }

    /**
     * Order the scanned table's predicate columns: selective equality columns first, then IN, then range, and
     * columns with only a handful of distinct values (flags) last. Columns that already have their own unique
     * index are left out.
     *
     * @param  list<array{column: string, kind: string, join: bool}>  $preds
     * @param  array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}  $stats
     * @param  list<array{name: string, columns: list<string>, unique: bool}>  $indexes
     * @return array{columns: list<string>, skipped: list<string>}|null
     */
    private function suggest(array $preds, array $stats, array $indexes): ?array
    {
        $served = [];
        foreach ($indexes as $idx) {
            if ($idx['unique'] && count($idx['columns']) === 1) {
                $served[strtolower($idx['columns'][0])] = true;
            }
        }

        $lowLimit = (int) ($this->config['explain_low_cardinality'] ?? 5);
        $max = max(1, (int) ($this->config['explain_max_index_columns'] ?? 4));
        $rank = ['eq' => 0, 'in' => 1, 'range' => 2];

        if (array_filter($preds, fn (array $p): bool => ! $p['join']) !== []) {
            $preds = array_values(array_filter($preds, fn (array $p): bool => ! $p['join']));
        }

        $items = [];
        foreach ($preds as $pos => $p) {
            $key = strtolower($p['column']);
            if (isset($served[$key])) {
                continue;
            }
            $r = $p['join'] ? 0 : ($rank[$p['kind']] ?? 2);
            if (isset($items[$key])) {
                $items[$key]['rank'] = min($items[$key]['rank'], $r);

                continue;
            }
            $info = $stats['columns'][$key] ?? null;
            $items[$key] = [
                'column' => $p['column'],
                'rank' => $r,
                'low' => $this->lowCardinality($p['column'], $info, $lowLimit),
                'distinct' => $info['distinct'] ?? 0,
                'pos' => $pos,
            ];
        }
        if ($items === []) {
            return null;
        }

        usort($items, fn (array $a, array $b): int => [$a['low'], $a['rank'], -$a['distinct'], $a['pos']] <=> [$b['low'], $b['rank'], -$b['distinct'], $b['pos']]);
        $items = array_slice($items, 0, $max);

        if ($items[0]['low']) {
            return ['columns' => [], 'skipped' => array_column($items, 'column')];
        }

        return ['columns' => array_column($items, 'column'), 'skipped' => []];
    }

    /**
     * A real distinct count decides. Without one (MySQL only counts the leading column of an index, so a flag that
     * leads no index has none), fall back to the column's name and its type: a flag-like name, or a type that holds
     * only a handful of values (tinyint, bit, boolean, a short enum).
     *
     * @param array{type: ?string, distinct: ?int}|null $info
     */
    private function lowCardinality(string $column, ?array $info, int $limit): bool
    {
        $type = $info['type'] ?? null;
        if ($type !== null && preg_match('/^(tinyint\(1\)|bit(\(1\))?|bool(ean)?)$/i', trim($type)) === 1) {
            return true;
        }
        if (($info['distinct'] ?? null) !== null) {
            return $info['distinct'] <= $limit;
        }

        return preg_match('/^(is|has|can|should|was|did|needs?)_|_flag$/i', $column) === 1
            || ($type !== null && (preg_match('/^(tinyint|bit)\b/i', trim($type)) === 1 || $this->shortEnum($type, $limit)));
    }

    private function shortEnum(string $type, int $limit): bool
    {
        return preg_match('/^enum\s*\(/i', trim($type)) === 1 && preg_match_all("/'(?:[^']|'')*'/", $type) <= max($limit, 2);
    }

    /**
     * Rows the query returns, measured with a second, time-limited run. Opt-in (explain_analyze) and capped per
     * trace. The replay uses the raw bindings the span held in memory; without them it falls back to the stored
     * bindings, and only when those are complete: redacted or truncated values would measure a different query.
     *
     * @param  array<string, mixed>  $meta
     * @param  list<Span>  $spans
     * @return array<string, mixed>
     */
    private function withReturned(array $meta, PlanSource $runner, ?string $connection, array $spans): array
    {
        if (! ($this->config['explain_analyze'] ?? false)) {
            return $meta;
        }
        $analyzer = $this->analyzer ?? ($runner instanceof AnalyzeSource ? $runner : null);
        if ($analyzer === null) {
            return $meta + ['rows_returned_why' => 'unsupported'];
        }
        if ($this->analyzed >= max(0, (int) ($this->config['explain_analyze_max'] ?? 3))) {
            return $meta + ['rows_returned_why' => 'budget'];
        }

        $first = $spans[0];
        $sql = (string) $first->meta['sql'];
        $replay = $this->rawReplay($spans);
        if ($replay !== null) {
            [$sql, $bindings] = $replay;
        } else {
            $bindings = (array) ($first->meta['bindings'] ?? []);
            if (! $this->completeBindings($sql, $bindings)) {
                return $meta + ['rows_returned_why' => 'no_bindings'];
            }
        }

        $this->analyzed++;
        try {
            $meta['rows_returned'] = $analyzer->rowsReturned($connection, $sql, $bindings, (int) ($this->config['explain_analyze_timeout_ms'] ?? 2000));
        } catch (Throwable) {
            // the measurement is optional
        }
        if ($meta['rows_returned'] === null) {
            $why = method_exists($analyzer, 'failure') ? $analyzer->failure() : null;
            $meta['rows_returned_why'] = in_array($why, ['timeout', 'unsupported'], true) ? $why : 'failed';
        }

        return $meta;
    }

    /**
     * The first span of the shape that still holds its raw bindings, with the SQL those bindings belong to (the
     * shape ignores IN-list length, so spans of one shape can differ in placeholder count).
     *
     * @param  list<Span>  $spans
     * @return array{0: string, 1: array<int|string, mixed>}|null
     */
    private function rawReplay(array $spans): ?array
    {
        foreach ($spans as $span) {
            $raw = $span->rawBindings();
            $own = (string) ($span->meta['sql'] ?? '');
            if ($raw !== null && substr_count($own, '?') === count($raw)) {
                return [$own, $raw];
            }
        }

        return null;
    }

    /** @param array<int|string, mixed> $bindings */
    private function completeBindings(string $sql, array $bindings): bool
    {
        $needed = substr_count($sql, '?');
        if ($needed !== count($bindings)) {
            return false;
        }
        $max = max(1, (int) ($this->config['binding_max_length'] ?? 200));
        foreach ($bindings as $value) {
            if (is_string($value) && (preg_match('/^\[(redacted|binary \d+ bytes|[\w\\\\]+)\]$/', $value) === 1
                || (str_ends_with($value, '...') && mb_strlen($value) === $max + 3))) {
                return false;
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Lantern\Detection\PlanFlip;

use Closure;
use Illuminate\Support\Facades\DB;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Instrumentation\Query\QuerySuspend;
use Lantern\Query\InListSize;
use Throwable;

/** Re-runs EXPLAIN for one query with its largest `IN (...)` list resized, filled with real ids when the column is found. */
final class InListProbe
{
    private const NOT_AN_ALIAS = 'where|join|inner|left|right|cross|full|outer|natural|on|using|group|order|limit|union|having|set|window';

    /** @param (Closure(?string, string, array<int|string, mixed>): ?array<string, mixed>)|null $explain replaces EXPLAIN (tests) */
    public function __construct(private readonly ?ExplainRunner $runner = null, private readonly ?Closure $explain = null)
    {
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @param list<int> $sizes
     * @return array{table: ?string, column: ?string, ids_source: string, sizes: list<array<string, mixed>>, plan_changes: list<array<string, mixed>>}|null null when the query has no IN list
     */
    public function run(string $sql, array $bindings, ?string $connection, array $sizes): ?array
    {
        $lists = InListSize::lists($sql);
        if ($lists === []) {
            return null;
        }
        $list = array_reduce($lists, fn (?array $c, array $l) => $c === null || $l['count'] > $c['count'] ? $l : $c);

        [$table, $column] = $this->target($sql, $list['at'], $connection);
        $ids = $table !== null && $column !== null ? $this->ids($connection, $table, $column, max($sizes)) : [];

        $rows = [];
        $samples = [];
        foreach ($sizes as $requested) {
            $values = $ids !== [] ? array_slice($ids, 0, $requested) : range(1, $requested);
            [$probeSql, $probeBindings] = $this->resize($sql, $bindings, $list, $values);
            $plan = $this->plan($connection, $probeSql, $probeBindings);

            $size = count($values);
            $sample = ['in_size' => $size, 'avg_ms' => 0.0, 'rows' => $plan['rows'] ?? null, 'table' => $plan['table'] ?? null, 'key' => $plan['key'] ?? null, 'full_scan' => (bool) ($plan['full_scan'] ?? false), 'explained' => $plan !== null];
            $samples[] = $sample;
            $rows[] = [
                'in_size' => $size,
                'requested' => $requested,
                'plan' => $plan === null ? null : [
                    'table' => $sample['table'],
                    'key' => $sample['key'],
                    'rows' => $sample['rows'],
                    'full_scan' => $sample['full_scan'],
                    'possible_keys' => $plan['possible_keys'] ?? null,
                    'summary' => PlanFlipAnalyzer::describe($sample),
                ],
            ];
        }

        return [
            'table' => $table,
            'column' => $column,
            'ids_source' => $ids !== [] ? 'table' : 'synthetic',
            'sizes' => $rows,
            'plan_changes' => array_map(fn (array $c): array => [
                'from_size' => $c['from']['in_size'],
                'to_size' => $c['to']['in_size'],
                'from' => PlanFlipAnalyzer::describe($c['from']),
                'to' => PlanFlipAnalyzer::describe($c['to']),
            ], PlanFlipAnalyzer::changes($samples)),
        ];
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @param array{start: int, end: int} $list
     * @param list<int|string|float> $values
     * @return array{0: string, 1: list<mixed>}
     */
    private function resize(string $sql, array $bindings, array $list, array $values): array
    {
        $before = substr($sql, 0, $list['start']);
        $inside = substr($sql, $list['start'], $list['end'] - $list['start']);
        $after = substr($sql, $list['end']);

        $all = array_pad(array_values($bindings), substr_count($sql, '?'), null);
        $head = substr_count($before, '?');
        $tail = $head + substr_count($inside, '?');

        return [
            $before.implode(', ', array_fill(0, count($values), '?')).$after,
            [...array_slice($all, 0, $head), ...$values, ...array_slice($all, $tail)],
        ];
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    private function plan(?string $connection, string $sql, array $bindings): ?array
    {
        return $this->explain !== null ? ($this->explain)($connection, $sql, $bindings) : ($this->runner ?? new ExplainRunner)->explain($connection, $sql, $bindings);
    }

    /** @return array{0: ?string, 1: ?string} table and column the list filters, best effort */
    private function target(string $sql, int $at, ?string $connection): array
    {
        $word = '[`"\[]?([\w$]+)[`"\]]?';
        if (preg_match('/(?:'.$word.'\.)?'.$word.'\s+(?:not\s+)?$/i', substr($sql, 0, $at), $m) !== 1) {
            return [null, null];
        }
        [$qualifier, $column] = [$m[1], $m[2]];

        $tables = [];
        $aliases = [];
        if (preg_match_all('/\b(?:from|join)\s+'.$word.'(?:\s+(?:as\s+)?(?!(?:'.self::NOT_AN_ALIAS.')\b)'.$word.')?/i', $sql, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $f) {
                $tables[] = $f[1];
                $aliases[$f[2] ?? $f[1]] = $f[1];
                $aliases[$f[1]] = $f[1];
            }
        }

        $candidates = $qualifier !== '' ? array_filter([$aliases[$qualifier] ?? null]) : $tables;
        try {
            $schema = DB::connection($connection)->getSchemaBuilder();
            foreach ($candidates as $table) {
                if (QuerySuspend::run(fn () => $schema->hasColumn($table, $column))) {
                    return [$table, $column];
                }
            }
        } catch (Throwable) {
        }

        return [null, null];
    }

    /** @return list<int|string> */
    private function ids(?string $connection, string $table, string $column, int $limit): array
    {
        try {
            return QuerySuspend::run(fn () => DB::connection($connection)->table($table)
                ->whereNotNull($column)->distinct()->orderBy($column)->limit($limit)->pluck($column)->all());
        } catch (Throwable) {
            return [];
        }
    }
}

<?php

declare(strict_types=1);

namespace Lantern\Detection\PlanFlip;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lantern\Instrumentation\Query\QuerySuspend;
use Throwable;

/** Per query shape and IN-list size: average time and the last EXPLAIN plan. Every failure degrades to "no history". */
final class PlanSampleStore
{
    public const TABLE = 'lantern_plan_samples';

    public const MAX_PER_SHAPE = 64;

    /** A time deviation re-EXPLAINs a sample at most this often. */
    private const DEVIATION_COOLDOWN_MINUTES = 5;

    private const WINDOW = 50;

    private int $retryAt = 0;

    private ?bool $hashed = null;

    public function __construct(private readonly ?string $connection = null)
    {
    }

    /** @param array<string, mixed> $config full config('lantern') */
    public static function fromConfig(array $config): ?self
    {
        if (($config['storage']['driver'] ?? 'memory') !== 'database') {
            return null;
        }
        $connection = $config['storage']['connection'] ?? null;

        return new self(is_string($connection) && $connection !== '' ? $connection : null);
    }

    /**
     * Hash of the SQL as written: comments and optimizer hints count, parameter values and IN-list length do not.
     * The shape fingerprint ignores comments, so this is what tells a hinted query from the same query without the hint.
     */
    public static function sqlHash(string $sql): string
    {
        $parts = preg_split('~(/\*.*?\*/|--[^\r\n]*)~s', $sql, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sql];
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= ' '.trim((string) preg_replace('/\s+/', ' ', $part)).' ';

                continue;
            }
            $part = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $part);
            $part = (string) preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $part);
            $part = (string) preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'in (?)', $part);
            $out .= $part;
        }

        return hash('xxh128', trim((string) preg_replace('/\s+/', ' ', $out)));
    }

    /** @return list<array<string, mixed>> */
    public function samples(string $shape): array
    {
        return $this->guard(function (Connection $db) use ($shape): array {
            return $db->table(self::TABLE)->where('shape', $shape)->orderBy('in_size')->get()
                ->map(fn ($row): array => self::sample((array) $row))
                ->all();
        }) ?? [];
    }

    /**
     * Drops samples taken from a different SQL text than the one running now (and pre-hash rows, once), so a fixed query
     * is not judged by the plan and timings of its old text.
     *
     * @param list<string> $hashes raw-SQL hashes seen for this shape in the current trace
     */
    public function discardStale(string $shape, array $hashes): void
    {
        if ($hashes === []) {
            return;
        }

        $this->guard(function (Connection $db) use ($shape, $hashes): void {
            if (! $this->hashed($db)) {
                return;
            }
            $db->table(self::TABLE)->where('shape', $shape)
                ->where(fn ($q) => $q->whereNull('sql_hash')->orWhereNotIn('sql_hash', $hashes))
                ->delete();
        });
    }

    /** @param array{table: ?string, key: ?string, rows: ?int, full_scan: bool}|null $plan the new EXPLAIN result, null keeps the stored one */
    public function record(string $shape, int $size, float $ms, ?array $plan, ?string $sqlHash = null): void
    {
        $this->guard(function (Connection $db) use ($shape, $size, $ms, $plan, $sqlHash): void {
            $now = Carbon::now();
            $extra = $sqlHash !== null && $this->hashed($db) ? ['sql_hash' => $sqlHash] : [];
            $query = fn () => $db->table(self::TABLE)->where('shape', $shape)->where('in_size', $size);
            $row = $query()->first();

            if ($row === null) {
                $db->table(self::TABLE)->insert(['shape' => $shape, 'in_size' => $size, 'samples' => 1, 'avg_ms' => $ms, 'updated_at' => $now] + self::planColumns($plan, $now) + $extra);
                $this->trim($db, $shape);

                return;
            }

            $stale = $extra !== [] && ($row->sql_hash ?? null) !== $sqlHash;
            $fold = $stale ? self::fold(null, $ms, null) : self::fold(self::sample((array) $row), $ms, $plan);
            $columns = $stale || $plan !== null ? self::planColumns($plan, $now) : [];
            $query()->update($fold + ['updated_at' => $now] + $columns + $extra);
        });
    }

    /**
     * The running average after one more execution. A re-EXPLAIN that finds a different plan starts the average over:
     * time measured under the old plan says nothing about the new one.
     *
     * @param array<string, mixed>|null $existing the stored sample
     * @param array{table: ?string, key: ?string, rows: ?int, full_scan: bool}|null $plan
     * @return array{samples: int, avg_ms: float}
     */
    public static function fold(?array $existing, float $ms, ?array $plan): array
    {
        if ($existing === null || ($plan !== null && ($existing['explained'] ?? false) && self::planChanged($existing, $plan))) {
            return ['samples' => 1, 'avg_ms' => $ms];
        }

        $n = min((int) $existing['samples'], self::WINDOW);

        return ['samples' => $n + 1, 'avg_ms' => ((float) $existing['avg_ms'] * $n + $ms) / ($n + 1)];
    }

    /**
     * @param array<string, mixed>|null $sample the stored sample for this shape and size
     * @param float|null $ms the time measured now; with $deviation it re-EXPLAINs a sample whose time moved far from its average
     */
    public static function needsExplain(?array $sample, int $refreshMinutes, ?float $ms = null, float $deviation = 0.0, float $minMs = 0.0): bool
    {
        if ($sample === null || $sample['explained_at'] === null) {
            return true;
        }

        try {
            $explained = Carbon::parse((string) $sample['explained_at']);
            if ($explained->lt(Carbon::now()->subMinutes($refreshMinutes))) {
                return true;
            }

            if ($ms === null || $deviation <= 1 || (float) $sample['avg_ms'] <= 0 || $explained->gt(Carbon::now()->subMinutes(self::DEVIATION_COOLDOWN_MINUTES))) {
                return false;
            }
            $ratio = $ms / (float) $sample['avg_ms'];

            return max($ms, (float) $sample['avg_ms']) >= $minMs && ($ratio >= $deviation || $ratio <= 1 / $deviation);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sample(array $row): array
    {
        return [
            'in_size' => (int) $row['in_size'],
            'samples' => (int) $row['samples'],
            'avg_ms' => (float) $row['avg_ms'],
            'rows' => isset($row['plan_rows']) ? (int) $row['plan_rows'] : null,
            'table' => $row['plan_table'] ?? null,
            'key' => $row['plan_key'] ?? null,
            'full_scan' => (bool) ($row['full_scan'] ?? false),
            'explained_at' => $row['explained_at'] ?? null,
            'explained' => ($row['explained_at'] ?? null) !== null,
            'sql_hash' => $row['sql_hash'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $sample
     * @param array{table: ?string, key: ?string, rows: ?int, full_scan: bool} $plan
     */
    private static function planChanged(array $sample, array $plan): bool
    {
        return ($sample['table'] ?? null) !== ($plan['table'] ?? null)
            || ($sample['key'] ?? null) !== ($plan['key'] ?? null)
            || (bool) ($sample['full_scan'] ?? false) !== (bool) ($plan['full_scan'] ?? false);
    }

    /**
     * @param array{table: ?string, key: ?string, rows: ?int, full_scan: bool}|null $plan
     * @return array<string, mixed>
     */
    private static function planColumns(?array $plan, Carbon $now): array
    {
        if ($plan === null) {
            return ['plan_table' => null, 'plan_key' => null, 'plan_rows' => null, 'full_scan' => false, 'explained_at' => null];
        }

        return ['plan_table' => $plan['table'], 'plan_key' => $plan['key'], 'plan_rows' => $plan['rows'], 'full_scan' => $plan['full_scan'], 'explained_at' => $now];
    }

    private function trim(Connection $db, string $shape): void
    {
        $sizes = $db->table(self::TABLE)->where('shape', $shape)->orderByDesc('updated_at')->orderBy('in_size')->pluck('in_size')->all();
        if (count($sizes) > self::MAX_PER_SHAPE) {
            $db->table(self::TABLE)->where('shape', $shape)->whereIn('in_size', array_slice($sizes, self::MAX_PER_SHAPE))->delete();
        }
    }

    /** False until the sql_hash migration has run; the store then behaves as it did before the column existed. */
    private function hashed(Connection $db): bool
    {
        return $this->hashed ??= $db->getSchemaBuilder()->hasColumn(self::TABLE, 'sql_hash');
    }

    /** Runs a storage call outside query instrumentation; a failure (missing table, no connection) pauses use for a minute. */
    private function guard(callable $fn): mixed
    {
        if (time() < $this->retryAt) {
            return null;
        }

        try {
            return QuerySuspend::run(fn () => $fn(DB::connection($this->connection)));
        } catch (Throwable) {
            $this->retryAt = time() + 60;

            return null;
        }
    }
}

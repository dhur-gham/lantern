<?php

declare(strict_types=1);

namespace Lantern\Detection\PlanFlip;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lantern\Instrumentation\Query\QuerySuspend;
use Throwable;

/** Per query shape and IN-list size: average time and the last EXPLAIN plan. Every failure degrades to "no history". */
final class PlanSampleStore
{
    public const TABLE = 'lantern_plan_samples';

    public const MAX_PER_SHAPE = 64;

    private const WINDOW = 50;

    private int $retryAt = 0;

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

    /** @return list<array<string, mixed>> */
    public function samples(string $shape): array
    {
        return $this->guard(function (ConnectionInterface $db) use ($shape): array {
            return $db->table(self::TABLE)->where('shape', $shape)->orderBy('in_size')->get()
                ->map(fn ($row): array => self::sample((array) $row))
                ->all();
        }) ?? [];
    }

    /** @param array{table: ?string, key: ?string, rows: ?int, full_scan: bool}|null $plan the new EXPLAIN result, null keeps the stored one */
    public function record(string $shape, int $size, float $ms, ?array $plan): void
    {
        $this->guard(function (ConnectionInterface $db) use ($shape, $size, $ms, $plan): void {
            $now = Carbon::now();
            $query = fn () => $db->table(self::TABLE)->where('shape', $shape)->where('in_size', $size);
            $row = $query()->first();

            if ($row === null) {
                $db->table(self::TABLE)->insert(['shape' => $shape, 'in_size' => $size, 'samples' => 1, 'avg_ms' => $ms, 'updated_at' => $now] + self::planColumns($plan, $now));
                $this->trim($db, $shape);

                return;
            }

            $n = min((int) $row->samples, self::WINDOW);
            $query()->update(['samples' => $n + 1, 'avg_ms' => ((float) $row->avg_ms * $n + $ms) / ($n + 1), 'updated_at' => $now] + ($plan === null ? [] : self::planColumns($plan, $now)));
        });
    }

    /** @param array<string, mixed>|null $sample the stored sample for this shape and size */
    public static function needsExplain(?array $sample, int $refreshMinutes): bool
    {
        if ($sample === null || $sample['explained_at'] === null) {
            return true;
        }

        try {
            return Carbon::parse((string) $sample['explained_at'])->lt(Carbon::now()->subMinutes($refreshMinutes));
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
        ];
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

    private function trim(ConnectionInterface $db, string $shape): void
    {
        $sizes = $db->table(self::TABLE)->where('shape', $shape)->orderByDesc('updated_at')->orderBy('in_size')->pluck('in_size')->all();
        if (count($sizes) > self::MAX_PER_SHAPE) {
            $db->table(self::TABLE)->where('shape', $shape)->whereIn('in_size', array_slice($sizes, self::MAX_PER_SHAPE))->delete();
        }
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

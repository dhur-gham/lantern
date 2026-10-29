<?php

declare(strict_types=1);

namespace Lantern\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class Pruner
{
    public const CHUNK = 1000;

    public function __construct(private readonly int $sleepMicroseconds = 0) {}

    /**
     * @return array{traces: int, issues: int}
     */
    public function prune(?int $traceDays = null, ?int $issueDays = null, bool $dryRun = false): array
    {
        $traceDays ??= (int) config('lantern.retention.days', 7);
        $issueDays ??= (int) config('lantern.retention.issues_days', 30);
        $db = $this->connection();

        if (! $dryRun) {
            $this->purgePlanSamples($db, now()->subDays($traceDays)->toDateTimeString());
        }

        return [
            'traces' => $this->purge($db, 'lantern_traces', 'id', 'started_at', now()->subDays($traceDays)->toDateTimeString(), $dryRun),
            'issues' => $this->purge($db, 'lantern_issues', 'issue_key', 'last_seen', now()->subDays($issueDays)->toDateTimeString(), $dryRun),
        ];
    }

    /** Plan samples follow trace retention; the table is absent until its migration has run. */
    private function purgePlanSamples(ConnectionInterface $db, string $cutoff): void
    {
        try {
            $db->table('lantern_plan_samples')->where('updated_at', '<', $cutoff)->delete();
        } catch (\Throwable) {
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(config('lantern.storage.connection'));
    }

    private function purge(ConnectionInterface $db, string $table, string $key, string $column, string $cutoff, bool $dryRun): int
    {
        if ($dryRun) {
            return $db->table($table)->where($column, '<', $cutoff)->count();
        }

        $deleted = 0;

        do {
            $keys = $db->table($table)->where($column, '<', $cutoff)->orderBy($column)->limit(self::CHUNK)->pluck($key)->all();

            if ($keys === []) {
                break;
            }

            $deleted += $db->table($table)->whereIn($key, $keys)->delete();

            if ($this->sleepMicroseconds > 0) {
                usleep($this->sleepMicroseconds);
            }
        } while (count($keys) === self::CHUNK);

        return $deleted;
    }
}

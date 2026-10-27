<?php

declare(strict_types=1);

namespace Lantern\Console;

use Illuminate\Console\Command;
use Lantern\Instrumentation\Lifecycle\TraceFormatter;
use Lantern\Storage\TraceQueries;
use Throwable;

final class TraceCommand extends Command
{
    protected $signature = 'lantern:trace {id? : Trace id} {--last : Latest trace} {--slow : Slowest of the recent traces} {--json : Output JSON}';

    protected $description = 'Show a stored trace as a span tree with its issues';

    public function handle(): int
    {
        if (config('lantern.storage.driver', 'memory') !== 'database') {
            $this->warn('Lantern storage is not configured. Set LANTERN_STORAGE_DRIVER=database and run the migrations.');

            return self::FAILURE;
        }

        try {
            $queries = $this->laravel->make(TraceQueries::class);
            $id = $this->argument('id') ?: $this->pick($queries);
            if ($id === null) {
                $this->info('No traces recorded yet.');

                return self::SUCCESS;
            }

            $trace = $queries->trace((string) $id);
        } catch (Throwable $e) {
            $this->error('Could not read Lantern storage: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($trace === null) {
            $this->error('Trace not found: '.$id);

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

            return self::SUCCESS;
        }

        $data = is_array($trace['payload'] ?? null) ? $trace['payload'] : [];
        $data['id'] ??= $trace['id'] ?? '?';
        $data['total_ms'] ??= (float) ($trace['duration_ms'] ?? 0);
        $data['peak_memory'] ??= (int) ($trace['peak_memory'] ?? 0);
        $data['meta'] = ($data['meta'] ?? []) + ['status' => $trace['status'] ?? null];
        $data['meta']['issues'] = array_map(fn (array $i): array => [
            'severity' => $i['severity'] ?? '?',
            'type' => $i['type'] ?? '?',
            'title' => $i['title'] ?? '?',
            'count' => $i['count'] ?? $i['count_total'] ?? $i['occurrences'] ?? 0,
            'total_ms' => $i['total_ms'] ?? 0,
            'call_site' => is_string($i['call_site'] ?? null) ? json_decode($i['call_site'], true) : ($i['call_site'] ?? null),
            'suggestion' => $i['suggestion'] ?? null,
        ], (array) ($trace['issues'] ?? []));

        $this->line(TraceFormatter::fromArray($data));

        return self::SUCCESS;
    }

    private function pick(TraceQueries $queries): ?string
    {
        if ($this->option('slow')) {
            $rows = $queries->traces([], 100);
            usort($rows, fn (array $a, array $b): int => ($b['duration_ms'] ?? 0) <=> ($a['duration_ms'] ?? 0));
        } else {
            $rows = $queries->traces([], 1);
        }

        return isset($rows[0]['id']) ? (string) $rows[0]['id'] : null;
    }
}

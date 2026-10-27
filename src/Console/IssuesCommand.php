<?php

declare(strict_types=1);

namespace Lantern\Console;

use Illuminate\Console\Command;
use Lantern\Storage\TraceQueries;
use Throwable;

final class IssuesCommand extends Command
{
    protected $signature = 'lantern:issues {--type= : Filter by issue type} {--severity= : Filter by severity} {--sort=total_ms : Sort column} {--limit=20 : Max rows}';

    protected $description = 'Show the ranked list of detected performance issues';

    public function handle(): int
    {
        if (config('lantern.storage.driver', 'memory') !== 'database') {
            $this->warn('Lantern storage is not configured. Set LANTERN_STORAGE_DRIVER=database and run the migrations.');

            return self::FAILURE;
        }

        $filters = array_filter([
            'type' => $this->option('type'),
            'severity' => $this->option('severity'),
            'sort' => $this->option('sort'),
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $issues = $this->laravel->make(TraceQueries::class)->issues($filters, max(1, (int) $this->option('limit')));
        } catch (Throwable $e) {
            $this->error('Could not read Lantern storage: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($issues === []) {
            $this->info('No issues recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Severity', 'Type', 'Title', 'Occurrences', 'Total ms', 'Call site', 'Suggestion'],
            array_map(fn (array $i): array => [
                $i['severity'] ?? '?',
                $i['type'] ?? '?',
                $i['title'] ?? '?',
                (int) ($i['occurrences'] ?? 0),
                number_format((float) ($i['total_ms'] ?? 0), 2),
                self::site($i['call_site'] ?? null),
                $i['suggestion'] ?? '',
            ], $issues),
        );

        return self::SUCCESS;
    }

    private static function site(mixed $site): string
    {
        if (is_string($site)) {
            $site = json_decode($site, true);
        }

        return is_array($site) && isset($site['file']) ? $site['file'].':'.($site['line'] ?? '?') : '-';
    }
}

<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;
use Lantern\Agent\Output;
use Lantern\Agent\Reader;

final class IssuesCommand extends AgentCommand
{
    protected $signature = 'lantern:issues
        {--type= : Filter by issue type}
        {--severity= : Filter by severity}
        {--sort=total_ms : total_ms, occurrences or last_seen}
        {--since= : Only issues last seen within a window (30m, 24h, 7d) or after a date}
        {--path= : Only issues whose latest trace path matches (Str::is pattern, e.g. api/otp/*)}
        {--route= : Only issues whose latest trace route name matches (Str::is pattern)}
        {--status= : Only issues whose latest trace has this status (404, or 5xx)}
        {--app= : Only issues of this app (default: the current app)}
        {--all-apps : Include issues of every app}
        {--limit=10 : Max issues returned}
        {--offset=0 : Skip this many issues}
        {--fields= : Comma-separated fields to return}
        {--full : Return every field, including evidence}
        {--format=table : table, json, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'List detected performance issues (stable finding model)';

    private const TYPES = ['n_plus_one', 'lazy_load', 'duplicate_query', 'slow_query', 'missing_index', 'index_not_chosen', 'plan_flip', 'memory_hog'];

    protected array $itemFields = Findings::ALL_FIELDS;

    protected function perform(Reader $reader): int
    {
        $sort = (string) $this->option('sort');
        if (! in_array($sort, ['total_ms', 'occurrences', 'last_seen'], true)) {
            throw AgentException::usage('Invalid --sort: '.$sort, 'Use total_ms, occurrences or last_seen.');
        }
        $type = strtolower((string) $this->option('type'));
        if ($type !== '' && ! in_array($type, self::TYPES, true)) {
            throw AgentException::usage('Invalid --type: '.$type, 'Use one of: '.implode(', ', self::TYPES).'.');
        }
        $severity = strtolower((string) $this->option('severity'));
        if ($severity !== '' && ! in_array($severity, ['critical', 'high', 'error', 'warning', 'warn', 'medium', 'info', 'low'], true)) {
            throw AgentException::usage('Invalid --severity: '.$severity, 'Use critical, warning or info (aliases high, error, warn, medium, low also match).');
        }
        $limit = $this->intOption('limit', 10, 1);
        $offset = $this->intOption('offset', 0);
        $fields = $this->fields() ?? ($this->option('full') ? Findings::ALL_FIELDS : Findings::DEFAULT_FIELDS);
        $filters = array_filter([
            'type' => $type,
            'severity' => $severity,
            'sort' => $sort,
        ], fn ($v) => $v !== null && $v !== '');
        $scope = $this->scope();
        $narrowed = array_diff_key($scope, ['app' => 1]) !== [] || $this->option('since');

        [$findings, $total, $exact] = $reader->issuesPage($filters + $scope, $limit, $offset, Reader::since($this->option('since')));

        if (in_array('evidence', $fields, true)) {
            foreach ($findings as $i => $f) {
                $findings[$i] = $reader->finding($f['id']);
            }
        }

        if ($findings === [] && $total === 0 && $this->format() === 'table') {
            $this->output->writeln('No issues recorded yet.');

            return self::SUCCESS;
        }

        $truncated = $offset + count($findings) < $total;
        $payload = [
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.issues',
            'generated_at' => now()->toIso8601String(),
            'total' => $total,
            'returned' => count($findings),
            'offset' => $offset,
            'truncated' => $truncated,
        ];
        if (! $exact) {
            $payload['total_capped'] = true;
        }
        if ($findings !== []) {
            $payload['next'] = 'php artisan lantern:issue '.$findings[0]['id'].' --format=json';
        } elseif ($total === 0 && $this->format() !== 'table') {
            $payload['hint'] = match (true) {
                ($filters === [] || array_keys($filters) === ['sort']) && ! $narrowed && isset($scope['app'])
                    => 'No issues stored for app "'.$scope['app'].'". Pass --all-apps to include other apps, or run the app with LANTERN_ENABLED=true and LANTERN_STORAGE_DRIVER=database, hit the route, then retry.',
                ($filters === [] || array_keys($filters) === ['sort']) && ! $narrowed
                    => 'No issues stored. Run the app with LANTERN_ENABLED=true and LANTERN_STORAGE_DRIVER=database, hit the route, then retry.',
                default => 'No issues match these filters; drop --type, --severity, --since, --path, --route or --status.',
            };
        }
        if ($truncated) {
            $payload['next_offset'] = $offset + count($findings);
            $payload['hint'] = $this->truncationHint(count($findings), $total, $offset);
        }
        $payload['issues'] = Output::project($findings, $fields);

        $this->emit($payload, 'issues', $fields);

        return self::SUCCESS;
    }
}

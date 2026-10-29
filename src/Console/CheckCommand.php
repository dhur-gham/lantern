<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;
use Lantern\Agent\Reader;

final class CheckCommand extends AgentCommand
{
    protected $signature = 'lantern:check
        {--max-queries= : Fail when a trace runs more queries than this}
        {--max-ms= : Fail when a trace takes longer than this many ms}
        {--fail-on= : Lowest issue severity that fails: critical, warning or info (default critical)}
        {--no-new-issues : Fail on any issue missing from the baseline (or any issue when there is no baseline)}
        {--baseline= : JSON file of accepted issue ids}
        {--write-baseline= : Write the current issue ids to this file and exit 0}
        {--route= : Only check traces for this route name or path (and their issues)}
        {--path= : Only check traces whose path matches (Str::is pattern)}
        {--status= : Only check traces with this status (404, or 5xx)}
        {--app= : Only check this app (default: the current app)}
        {--all-apps : Check every app}
        {--since= : Only check traces and issues from this window (30m, 24h, 7d) or date}
        {--traces=50 : Max recent traces to inspect}
        {--limit=20 : Max violations listed}
        {--format=json : table, json, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'CI gate: exit 0 ok, 1 findings over threshold, 2 usage/config error, 3 no data';

    protected function perform(Reader $reader): int
    {
        $maxQueries = $this->threshold('max-queries', 'max_queries');
        $maxMs = $this->threshold('max-ms', 'max_ms');
        $failOn = $this->failOn();
        $noNew = (bool) $this->option('no-new-issues');
        $baseline = $this->baseline();
        $limit = $this->intOption('limit', 20, 1);
        $since = Reader::since($this->option('since'));
        $route = $this->option('route') ?: null;

        $scope = $this->scope(false);
        $traces = $reader->traces($scope, $this->intOption('traces', (int) config('lantern.check.traces', 50), 1));
        if ($route !== null) {
            $needle = ltrim((string) $route, '/');
            $traces = array_values(array_filter($traces, fn (array $t): bool => ($t['route'] ?? null) === $route || ltrim((string) ($t['path'] ?? ''), '/') === $needle));
        }
        if ($since !== null) {
            $traces = array_values(array_filter($traces, fn (array $t): bool => \Illuminate\Support\Carbon::parse($t['started_at'])->greaterThanOrEqualTo($since)));
        }

        $issues = $this->scopedIssues($reader, $traces, $route !== null || isset($scope['path_is']) || isset($scope['status']), $since, $scope);

        if ($traces === [] && $issues === []) {
            throw AgentException::noData(
                $route !== null ? 'No traces recorded for route '.$route.'.' : 'No traces or issues recorded yet.',
                'Exercise the code under test with Lantern storage enabled, then re-run.',
            );
        }

        if ($this->option('write-baseline')) {
            $path = (string) $this->option('write-baseline');
            $ids = array_values(array_unique(array_column($issues, 'id')));
            sort($ids);
            if (@file_put_contents($path, json_encode(['schema_version' => Findings::VERSION, 'issues' => $ids], JSON_PRETTY_PRINT)) === false) {
                throw AgentException::usage('Cannot write baseline: '.$path);
            }
        }

        $violations = [];
        foreach (['max_queries' => [$maxQueries, 'query_count'], 'max_ms' => [$maxMs, 'duration_ms']] as $rule => [$limitValue, $col]) {
            if ($limitValue === null) {
                continue;
            }
            $worst = null;
            foreach ($traces as $t) {
                if ((float) ($t[$col] ?? 0) > $limitValue && ($worst === null || $t[$col] > $worst[$col])) {
                    $worst = $t;
                }
            }
            if ($worst !== null) {
                $violations[] = ['rule' => $rule, 'limit' => $limitValue, 'actual' => $worst[$col], 'trace_id' => $worst['id'], 'route' => $worst['route'] ?? $worst['path'] ?? null];
            }
        }

        foreach ($issues as $f) {
            $isBaselined = in_array($f['id'], $baseline, true);
            if ($isBaselined) {
                continue;
            }
            if ($noNew) {
                $violations[] = $this->issueViolation('new_issue', $f);
            } elseif (Findings::rank($f['severity']) >= $failOn) {
                $violations[] = $this->issueViolation('issue', $f);
            }
        }

        $pass = $violations === [] || $this->option('write-baseline') !== null && $this->option('write-baseline') !== '';
        $shown = array_slice($violations, 0, $limit);
        $payload = [
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.check',
            'pass' => $pass,
            'exit_code' => $pass ? 0 : 1,
            'thresholds' => array_filter([
                'max_queries' => $maxQueries,
                'max_ms' => $maxMs,
                'fail_on' => (string) ($this->option('fail-on') ?: config('lantern.check.fail_on', 'critical')),
                'no_new_issues' => $noNew,
                'baseline' => $this->option('baseline') ?: null,
            ], fn ($v) => $v !== null),
            'scope' => array_filter(['route' => $route, 'path' => $scope['path_is'] ?? null, 'status' => $scope['status'] ?? null, 'since' => $this->option('since') ?: null, 'traces_checked' => count($traces), 'issues_checked' => count($issues)], fn ($v) => $v !== null),
            'violation_count' => count($violations),
            'truncated' => count($violations) > count($shown),
            'violations' => $shown,
        ];
        if (! $pass) {
            $payload['next'] = 'Fix the violations at their call_site, re-run the request, then run this check again.';
        }
        if ($payload['truncated']) {
            $payload['hint'] = sprintf('%d of %d violations shown; raise --limit.', count($shown), count($violations));
        }

        $this->emit($payload, 'violations');

        return $pass ? self::SUCCESS : self::FAILURE;
    }

    private function threshold(string $option, string $config): int|float|null
    {
        $raw = $this->option($option);
        if ($raw === null || $raw === '') {
            $raw = config('lantern.check.'.$config);
            if ($raw === null || $raw === '') {
                return null;
            }
        }
        if (! is_numeric($raw) || (float) $raw < 0) {
            throw AgentException::usage("--$option must be a non-negative number.");
        }

        return $raw + 0;
    }

    private function failOn(): int
    {
        $value = strtolower((string) ($this->option('fail-on') ?: config('lantern.check.fail_on', 'critical')));
        $rank = match ($value) {
            'critical', 'error' => 2,
            'warning', 'warn' => 1,
            'info', 'note' => 0,
            default => null,
        };
        if ($rank === null) {
            throw AgentException::usage('Invalid --fail-on: '.$value, 'Use critical, warning or info.');
        }

        return $rank;
    }

    /** @return list<string> */
    private function baseline(): array
    {
        $path = $this->option('baseline');
        if ($path === null || $path === '') {
            return [];
        }
        if (! is_file((string) $path)) {
            throw AgentException::usage('Baseline file not found: '.$path);
        }
        $data = json_decode((string) file_get_contents((string) $path), true);
        if (! is_array($data)) {
            throw AgentException::usage('Baseline file is not valid JSON: '.$path);
        }
        $ids = $data['issues'] ?? $data;
        if (! is_array($ids)) {
            throw AgentException::usage('Baseline file must hold a list of issue ids under "issues".');
        }

        return array_values(array_map(fn ($i): string => is_array($i) ? (string) ($i['id'] ?? '') : (string) $i, $ids));
    }

    /**
     * @param list<array<string, mixed>> $traces
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function scopedIssues(Reader $reader, array $traces, bool $byTraces, ?\Illuminate\Support\Carbon $since, array $scope = []): array
    {
        if ($byTraces) {
            $out = [];
            foreach ($traces as $t) {
                $full = $reader->traceRow((string) $t['id']);
                foreach ($full === null ? [] : $reader->traceFindings($full) as $f) {
                    $out[$f['id']] = $f;
                }
            }

            return array_values($out);
        }

        [$findings] = $reader->issuesPage(array_intersect_key($scope, ['app' => 1]), Reader::MAX_ROWS, 0, $since);

        return $findings;
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function issueViolation(string $rule, array $f): array
    {
        return ['rule' => $rule, 'issue_id' => $f['id'], 'type' => $f['type'], 'severity' => $f['severity'], 'title' => $f['title'], 'call_site' => $f['call_site']];
    }
}

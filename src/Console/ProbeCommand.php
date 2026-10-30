<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;
use Lantern\Agent\Reader;
use Lantern\Detection\PlanFlip\InListProbe;
use Lantern\Query\Fingerprinter;

final class ProbeCommand extends AgentCommand
{
    private const MAX_SIZE = 5000;

    private const MAX_SIZES = 12;

    protected $signature = 'lantern:probe
        {key : Issue id (a unique prefix of at least 6 characters works) whose query has an IN (...) list}
        {--in-sizes=1,7,20,41,100 : Comma-separated IN list lengths to EXPLAIN}
        {--format=json : table, json, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Re-run EXPLAIN for an issue\'s query with different IN list lengths to find where the plan changes';

    protected function perform(Reader $reader): int
    {
        $sizes = $this->sizes((string) $this->option('in-sizes'));
        $row = $reader->issueRow((string) $this->argument('key'));
        $finding = Findings::fromRow($row);
        $traceId = $finding['related_trace_ids'][0] ?? null;
        if (! is_string($traceId) || $traceId === '') {
            throw AgentException::notFound('No stored trace for this issue.', 'Re-run the request so a trace carrying the issue is recorded.');
        }

        $trace = $reader->trace($traceId);
        [$sql, $bindings, $connection] = $this->query($trace, (string) $row['issue_key']);

        $result = $this->laravel->make(InListProbe::class)->run($sql, $bindings, $connection, $sizes);
        if ($result === null) {
            throw AgentException::usage('This issue\'s query has no IN (...) list to resize.', 'Probe an issue whose query filters with whereIn(), for example a plan_flip, slow_query or n_plus_one finding.');
        }
        if (array_filter(array_column($result['sizes'], 'plan')) === []) {
            throw AgentException::config('EXPLAIN is not available for this query or connection.', 'Probing supports SELECT statements on MySQL, MariaDB, PostgreSQL and SQLite.');
        }

        $this->emit([
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.probe',
            'issue_id' => $finding['id'],
            'sql_shape' => (new Fingerprinter)->normalize($sql),
            'connection' => $connection,
            'table' => $result['table'],
            'column' => $result['column'],
            'ids_source' => $result['ids_source'],
            'verdict' => $result['plan_changes'] === [] ? 'stable' : 'plan_changes',
            'plan_changes' => $result['plan_changes'],
            'sizes' => $result['sizes'],
            'hint' => $result['plan_changes'] === []
                ? 'The chosen plan is the same at every probed size. Compare timings with EXPLAIN ANALYZE on the real data.'
                : 'The optimizer picks a different plan between the listed sizes. Try EXPLAIN ANALYZE, a join-order or index hint, ANALYZE TABLE, or keep the list below the change.',
        ] + ($result['ids_source'] === 'synthetic' ? [
            'ids_note' => 'The filtered column could not be resolved or has no rows, so the lists hold the numbers 1..N. Those may match no rows and give a plan a real list would not, so treat a change here with care.',
        ] : []));

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function sizes(string $raw): array
    {
        $sizes = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || ! ctype_digit($part) || (int) $part < 1 || (int) $part > self::MAX_SIZE) {
                throw AgentException::usage('--in-sizes must be comma-separated integers between 1 and '.self::MAX_SIZE.'.', 'Example: --in-sizes=1,7,20,41,100');
            }
            $sizes[(int) $part] = (int) $part;
        }
        sort($sizes);
        if ($sizes === [] || count($sizes) > self::MAX_SIZES) {
            throw AgentException::usage('--in-sizes takes 1 to '.self::MAX_SIZES.' sizes.');
        }

        return array_values($sizes);
    }

    /**
     * @param array<string, mixed> $trace
     * @return array{0: string, 1: array<int|string, mixed>, 2: ?string}
     */
    private function query(array $trace, string $issueKey): array
    {
        foreach ((array) ($trace['issues'] ?? []) as $issue) {
            if (Findings::keyOf((array) $issue) !== $issueKey) {
                continue;
            }
            $meta = (array) ($issue['meta'] ?? []);
            $ids = array_map('intval', (array) ($issue['span_ids'] ?? []));
            $node = $this->node(is_array($trace['tree'] ?? null) ? $trace['tree'] : null, $ids);
            $nodeMeta = (array) ($node['meta'] ?? []);
            $sql = $meta['sample_sql'] ?? $nodeMeta['sql'] ?? null;
            if (is_string($sql) && $sql !== '') {
                $connection = $meta['connection'] ?? $nodeMeta['connection'] ?? null;

                return [$sql, (array) ($nodeMeta['bindings'] ?? []), is_string($connection) ? $connection : null];
            }
        }

        throw AgentException::usage('This issue has no stored query to probe.', 'Probe a query issue such as plan_flip, slow_query, n_plus_one or missing_index.');
    }

    /**
     * @param array<string, mixed>|null $node
     * @param list<int> $ids
     * @return array<string, mixed>|null
     */
    private function node(?array $node, array $ids): ?array
    {
        if ($node === null) {
            return null;
        }
        if (isset($node['id']) && in_array((int) $node['id'], $ids, true) && isset($node['meta']['sql'])) {
            return $node;
        }
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (($found = $this->node((array) $child, $ids)) !== null) {
                return $found;
            }
        }

        return null;
    }
}

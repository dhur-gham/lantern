<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;
use Lantern\Agent\Reader;
use Lantern\Instrumentation\Lifecycle\TraceFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class TraceCommand extends AgentCommand
{
    protected $signature = 'lantern:trace
        {id? : Trace id (defaults to the latest)}
        {--last : Latest trace}
        {--slow : Slowest of the recent traces}
        {--summary : Totals, issues and the slowest query groups}
        {--tree : Span tree with repeated sibling queries collapsed}
        {--spans : Flat span list (slowest spans, recorded order)}
        {--min-ms= : Only spans at least this slow (spans)}
        {--depth=6 : Max tree depth (tree)}
        {--limit=10 : Max rows (summary groups default 10, spans default 50)}
        {--fields= : Comma-separated span fields to return (spans)}
        {--json : Legacy full trace dump}
        {--format=table : table, json, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Show a stored trace as a summary, a collapsed tree or a flat span list';

    protected array $itemFields = ['id', 'parent_id', 'type', 'name', 'start_ms', 'duration_ms', 'self_ms', 'call_site'];

    protected function perform(Reader $reader): int
    {
        $modes = array_filter([
            'summary' => $this->option('summary'),
            'tree' => $this->option('tree'),
            'spans' => $this->option('spans'),
        ]);
        if (count($modes) > 1) {
            throw AgentException::usage('Use only one of --summary, --tree, --spans.');
        }
        $mode = array_key_first($modes);
        $format = $this->format();
        $legacy = $mode === null && $format === 'table';

        if ($this->option('last') && $this->argument('id')) {
            throw AgentException::usage('Pass either a trace id or --last, not both.');
        }

        try {
            $trace = $reader->trace($this->argument('id') ? (string) $this->argument('id') : null, (bool) $this->option('slow'));
        } catch (AgentException $e) {
            if ($legacy && $e->errorCode === AgentException::NO_DATA) {
                $this->output->writeln('No traces recorded yet.');

                return self::SUCCESS;
            }
            throw $e;
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if ($legacy) {
            return $this->legacy($trace);
        }

        return match ($mode ?? 'summary') {
            'tree' => $this->tree($reader, $trace, $format),
            'spans' => $this->spans($reader, $trace),
            default => $this->summary($reader, $trace),
        };
    }

    /** @param array<string, mixed> $trace */
    private function summary(Reader $reader, array $trace): int
    {
        $this->emit($reader->summary($trace, $this->intOption('limit', 10, 1)), 'query_groups');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $trace */
    private function spans(Reader $reader, array $trace): int
    {
        $limit = $this->intOption('limit', 50, 1);
        $fields = $this->fields();
        [$spans, $total] = $reader->spans($trace, $this->floatOption('min-ms') ?? 0.0, $limit);

        $payload = [
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.trace.spans',
            'trace_id' => (string) $trace['id'],
            'total' => $total,
            'returned' => count($spans),
            'truncated' => $total > count($spans),
        ];
        if ($total > count($spans)) {
            $payload['hint'] = sprintf('%d of %d spans shown (slowest first); raise --limit or set --min-ms.', count($spans), $total);
        }
        $payload['spans'] = \Lantern\Agent\Output::project($spans, $fields);

        $this->emit($payload, 'spans', $fields ?? ['id', 'type', 'name', 'duration_ms', 'self_ms', 'call_site']);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $trace */
    private function tree(Reader $reader, array $trace, string $format): int
    {
        $tree = $reader->tree($trace['tree'] ?? null, $this->intOption('depth', 6, 0, 50));

        if ($format === 'table' || $format === 'markdown') {
            $lines = $tree === null ? [] : $this->lines($tree, 0);
            $this->output->writeln(($format === 'markdown' ? '# Trace '.$trace['id']."\n\n" : '')
                .implode("\n", $format === 'markdown' ? array_map(fn (string $l): string => (string) preg_replace('/^( *)/', '$1- ', $l), $lines) : $lines), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->emit([
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.trace.tree',
            'trace_id' => (string) $trace['id'],
            'tree' => $tree,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function lines(array $node, int $depth): array
    {
        $site = isset($node['call_site']) ? '  '.Findings::siteString($node['call_site']) : '';
        $label = ($node['type'] ?? '') === 'query_group'
            ? sprintf('query x%d %s', $node['count'], $node['sql_shape'] ?? '')
            : trim(($node['type'] ?? '').' '.($node['name'] ?? ''));
        $ms = isset($node['total_ms']) ? $node['total_ms'] : ($node['duration_ms'] ?? 0);
        $out = [str_repeat('  ', $depth).$label.'  '.$ms.'ms'.$site];
        foreach ($node['children'] ?? [] as $child) {
            array_push($out, ...$this->lines($child, $depth + 1));
        }
        if (isset($node['children_omitted'])) {
            $out[] = str_repeat('  ', $depth + 1).'... '.$node['children_omitted'].' children omitted (raise --depth)';
        }

        return $out;
    }

    /** @param array<string, mixed> $trace */
    private function legacy(array $trace): int
    {
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
}

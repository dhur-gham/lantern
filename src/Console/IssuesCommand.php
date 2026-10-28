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
        {--limit=10 : Max issues returned}
        {--offset=0 : Skip this many issues}
        {--fields= : Comma-separated fields to return}
        {--full : Return every field, including evidence}
        {--format=table : table, json, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'List detected performance issues (stable finding model)';

    protected array $itemFields = Findings::ALL_FIELDS;

    protected function perform(Reader $reader): int
    {
        $sort = (string) $this->option('sort');
        if (! in_array($sort, ['total_ms', 'occurrences', 'last_seen'], true)) {
            throw AgentException::usage('Invalid --sort: '.$sort, 'Use total_ms, occurrences or last_seen.');
        }
        $limit = $this->intOption('limit', 10, 1);
        $offset = $this->intOption('offset', 0);
        $fields = $this->fields() ?? ($this->option('full') ? Findings::ALL_FIELDS : Findings::DEFAULT_FIELDS);
        $filters = array_filter([
            'type' => $this->option('type'),
            'severity' => $this->option('severity'),
            'sort' => $sort,
        ], fn ($v) => $v !== null && $v !== '');

        [$findings, $total, $exact] = $reader->issuesPage($filters, $limit, $offset, Reader::since($this->option('since')));

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
        if ($truncated) {
            $payload['next_offset'] = $offset + count($findings);
            $payload['hint'] = $this->truncationHint(count($findings), $total, $offset);
        }
        $payload['issues'] = Output::project($findings, $fields);

        $this->emit($payload, 'issues', $fields);

        return self::SUCCESS;
    }
}

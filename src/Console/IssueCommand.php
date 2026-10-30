<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\Findings;
use Lantern\Agent\Output;
use Lantern\Agent\Reader;

final class IssueCommand extends AgentCommand
{
    protected $signature = 'lantern:issue
        {key : Issue id (a unique prefix of at least 6 characters works)}
        {--with-bindings : Show binding values (masked) instead of type placeholders}
        {--fields= : Comma-separated fields to return}
        {--format=table : table, json, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Show one finding with its redacted evidence';

    protected array $itemFields = Findings::ALL_FIELDS;

    protected function perform(Reader $reader): int
    {
        $fields = $this->fields();
        $finding = $reader->finding((string) $this->argument('key'), (bool) $this->option('with-bindings'));

        $payload = ['schema_version' => Findings::VERSION, 'schema' => 'lantern.issue'];
        $payload['issue'] = Output::project([$finding], $fields)[0];
        $trace = $finding['evidence']['trace_id'] ?? null;
        if (($finding['status'] ?? 'open') === 'resolved') {
            $payload['hint'] = 'Resolved: the query ran again without this finding. It reopens if the finding fires again.';
        } elseif (is_string($trace) && $trace !== '') {
            $payload['next'] = 'Fix call_site, re-run the request, then: php artisan lantern:diff '.$trace.' last';
        }

        $this->emit($payload, null);

        return self::SUCCESS;
    }
}

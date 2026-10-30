<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Reader;
use Lantern\Agent\SqlMatcher;
use Lantern\Query\SqlInput;
use Symfony\Component\Console\Input\StreamableInputInterface;

final class MatchCommand extends AgentCommand
{
    private const MAX_SQL_BYTES = 100000;

    protected $signature = 'lantern:match
        {--sql= : SQL to find in the code (an APM span description or a log line); read from stdin when omitted and piped}
        {--traces=200 : Recent traces searched (1-500)}
        {--since= : Only traces started within a window (30m, 24h, 7d) or after a date}
        {--path= : Only traces whose path matches (Str::is pattern, e.g. api/otp/*)}
        {--route= : Only traces whose route name matches (Str::is pattern)}
        {--status= : Only traces with this status (404, or 5xx)}
        {--app= : Only traces of this app (default: the current app)}
        {--all-apps : Search the traces of every app}
        {--include-resolved : Also report issues that later runs showed are gone}
        {--limit=5 : Max matches returned (1-50)}
        {--format=json : table, json, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Find the code, issues and plan behind a SQL statement seen in production';

    protected function perform(Reader $reader): int
    {
        $sql = $this->sql();
        $traces = $this->intOption('traces', 200, 1);
        $limit = $this->intOption('limit', 5, 1, 50);

        $filters = $this->scope();
        $since = Reader::since($this->option('since'));
        if ($since !== null) {
            $filters['since'] = $since;
        }

        $this->emit((new SqlMatcher($reader))->match($sql, $filters, $traces, (bool) $this->option('include-resolved'), $limit));

        return self::SUCCESS;
    }

    private function sql(): string
    {
        $sql = trim((string) $this->option('sql'));
        if ($sql === '') {
            $sql = trim($this->stdin());
        }
        if ($sql === '') {
            throw AgentException::usage('No SQL given.', 'Pass it with --sql="select ..." or pipe it: echo "select ..." | php artisan lantern:match');
        }
        if (strlen($sql) > self::MAX_SQL_BYTES) {
            throw AgentException::usage('The SQL is longer than '.self::MAX_SQL_BYTES.' bytes.', 'Pass a single statement.');
        }
        if (! SqlInput::looksLikeSql($sql)) {
            throw AgentException::usage('That does not look like a SQL statement.', 'It should start with select, insert, update, delete or with.');
        }

        return $sql;
    }

    /** Piped input only: an interactive terminal, or a programmatic call without a stream, reads nothing. */
    private function stdin(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        if ($stream === null) {
            if (! $this->input instanceof \Symfony\Component\Console\Input\ArgvInput || ! defined('STDIN') || (function_exists('stream_isatty') && stream_isatty(STDIN))) {
                return '';
            }
            $stream = STDIN;
        }

        stream_set_blocking($stream, false);
        $buffer = '';
        $deadline = microtime(true) + 0.3;
        while (microtime(true) < $deadline && strlen($buffer) <= self::MAX_SQL_BYTES) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    break;
                }
                usleep(20000);

                continue;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}

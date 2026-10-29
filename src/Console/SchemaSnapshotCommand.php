<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentException;
use Lantern\Schema\IndexNormalizer;
use Lantern\Schema\SchemaIntrospector;
use Lantern\Schema\SchemaSnapshot;

final class SchemaSnapshotCommand extends SchemaCommand
{
    protected $signature = 'lantern:schema-snapshot
        {--connection= : Database connection (default connection when omitted)}
        {--tables= : Comma-separated tables to include (default: all)}
        {--from-csv= : Build the snapshot from a saved SHOW INDEX result (CSV with header) instead of a connection}
        {--row-counts= : Production row counts as table:count,table:count}
        {--format=json : json, table, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Capture the index layout of a database as JSON, to compare with lantern:schema-diff';

    protected function execute_(): int
    {
        $tables = $this->tables();
        $counts = $this->rowCounts();

        if (is_string($csv = $this->option('from-csv')) && $csv !== '') {
            if (! is_file($csv) || ! is_readable($csv)) {
                throw AgentException::notFound('CSV file not found: '.$csv);
            }
            $captured = IndexNormalizer::fromCsv((string) file_get_contents($csv));
            unset($captured['']);
            if ($tables !== null) {
                $captured = array_intersect_key($captured, array_flip($tables));
            }
            ksort($captured);
            $snapshot = new SchemaSnapshot('mysql', null, now()->toIso8601String(), $captured, $counts);
        } else {
            $connection = $this->connection();
            $captured = (new SchemaIntrospector)->capture($connection, $tables);
            $snapshot = new SchemaSnapshot($connection->getDriverName(), $connection->getDatabaseName() ?: null, now()->toIso8601String(), $captured, $counts);
        }

        if ($snapshot->tables === []) {
            throw AgentException::noData('No indexes found for the requested tables.', 'Check --tables, --connection or the CSV header (Table, Key_name, Seq_in_index, Column_name, Non_unique).');
        }

        $this->emit($snapshot->toArray(), 'tables');

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function tables(): ?array
    {
        $raw = $this->option('tables');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $tables = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn (string $t): bool => $t !== '')));
        foreach ($tables as $table) {
            if (! SchemaIntrospector::isIdentifier($table)) {
                throw AgentException::usage('Invalid table name in --tables: '.$table);
            }
        }

        return $tables;
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $raw = $this->option('row-counts');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $counts = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode(':', trim($pair));
            if (count($parts) !== 2 || ! SchemaIntrospector::isIdentifier(trim($parts[0])) || ! ctype_digit(trim($parts[1]))) {
                throw AgentException::usage('Invalid --row-counts entry: '.$pair, 'Use table:count, for example orders:838776,order_items:2686282.');
            }
            $counts[trim($parts[0])] = (int) trim($parts[1]);
        }

        return $counts;
    }
}

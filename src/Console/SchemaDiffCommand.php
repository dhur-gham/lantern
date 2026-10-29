<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;
use Lantern\Schema\SchemaDiff;
use Lantern\Schema\SchemaIntrospector;
use Lantern\Schema\SchemaSnapshot;

final class SchemaDiffCommand extends SchemaCommand
{
    protected $signature = 'lantern:schema-diff
        {snapshot : Snapshot file from lantern:schema-snapshot}
        {--connection= : Database connection (default connection when omitted)}
        {--format=json : json, table, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'List indexes production has that the local database lacks, and the reverse';

    protected function execute_(): int
    {
        $snapshot = SchemaSnapshot::fromFile((string) $this->argument('snapshot'));
        if ($snapshot->tables === []) {
            throw AgentException::noData('The snapshot contains no tables.');
        }

        $connection = $this->connection();
        $local = (new SchemaIntrospector)->capture($connection, array_keys($snapshot->tables));
        $diff = SchemaDiff::compare($snapshot->tables, $local);
        $inSync = $diff['tables_missing_locally'] === [] && $diff['missing_locally'] === [] && $diff['extra_locally'] === [];

        $this->emit([
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.schema-diff',
            'snapshot' => ['driver' => $snapshot->driver, 'database' => $snapshot->database, 'captured_at' => $snapshot->capturedAt, 'tables' => count($snapshot->tables)],
            'local' => ['driver' => $connection->getDriverName(), 'database' => $connection->getDatabaseName() ?: null],
            'in_sync' => $inSync,
            'tables_missing_locally' => $diff['tables_missing_locally'],
            'missing_locally' => $diff['missing_locally'],
            'extra_locally' => $diff['extra_locally'],
            'hint' => $inSync ? null : 'Local EXPLAIN plans and timings do not reflect production until these indexes match.',
        ], 'missing_locally');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Lantern\Schema;

use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;

/**
 * Index layout (and optional row counts) of a database, captured where production is reachable and compared
 * elsewhere. Serialised as the `lantern.schema-snapshot` JSON document.
 */
final class SchemaSnapshot
{
    public const SCHEMA = 'lantern.schema-snapshot';

    /**
     * @param array<string, list<array{name: string, columns: list<string>, unique: bool}>> $tables
     * @param array<string, int> $rowCounts
     */
    public function __construct(
        public readonly string $driver,
        public readonly ?string $database,
        public readonly ?string $capturedAt,
        public readonly array $tables,
        public readonly array $rowCounts = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => Findings::VERSION,
            'schema' => self::SCHEMA,
            'driver' => $this->driver,
            'database' => $this->database,
            'captured_at' => $this->capturedAt,
            'table_count' => count($this->tables),
            'tables' => $this->tables,
            'row_counts' => $this->rowCounts === [] ? (object) [] : $this->rowCounts,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $tables = [];
        foreach ((array) ($data['tables'] ?? []) as $table => $indexes) {
            foreach ((array) $indexes as $index) {
                $index = (array) $index;
                $columns = array_values(array_map('strval', (array) ($index['columns'] ?? [])));
                if (! isset($index['name']) || $columns === []) {
                    continue;
                }
                $tables[(string) $table][] = ['name' => (string) $index['name'], 'columns' => $columns, 'unique' => (bool) ($index['unique'] ?? false)];
            }
            $tables[(string) $table] ??= [];
        }

        $counts = [];
        foreach ((array) ($data['row_counts'] ?? []) as $table => $count) {
            if (is_numeric($count) && (int) $count >= 0) {
                $counts[(string) $table] = (int) $count;
            }
        }

        return new self(
            (string) ($data['driver'] ?? 'unknown'),
            isset($data['database']) ? (string) $data['database'] : null,
            isset($data['captured_at']) ? (string) $data['captured_at'] : null,
            $tables,
            $counts,
        );
    }

    /** @throws AgentException */
    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw AgentException::notFound('Snapshot file not found: '.$path, 'Create one with php artisan lantern:schema-snapshot > prod-schema.json');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['tables']) || ($data['schema'] ?? self::SCHEMA) !== self::SCHEMA) {
            throw AgentException::config('Not a Lantern schema snapshot: '.$path, 'Create one with php artisan lantern:schema-snapshot > prod-schema.json');
        }

        return self::fromArray($data);
    }
}

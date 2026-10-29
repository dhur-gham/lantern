<?php

declare(strict_types=1);

namespace Lantern\Schema;

use Throwable;

/**
 * Read-only view of the configured production snapshot (config `schema_snapshot`) and row counts
 * (`production_row_counts`), used to annotate findings. Never throws: a missing or broken snapshot is just absent.
 */
final class ProductionSnapshot
{
    /** @var array<string, array{0: int, 1: self|null}> */
    private static array $cache = [];

    /** @param array<string, int> $rowCounts */
    private function __construct(private readonly ?SchemaSnapshot $snapshot, private readonly array $rowCounts)
    {
    }

    public static function load(): ?self
    {
        try {
            $path = config('lantern.schema_snapshot');
            $counts = [];
            foreach ((array) config('lantern.production_row_counts', []) as $table => $count) {
                if (is_numeric($count) && (int) $count >= 0) {
                    $counts[(string) $table] = (int) $count;
                }
            }

            $snapshot = null;
            if (is_string($path) && $path !== '') {
                $mtime = (int) @filemtime($path);
                $key = $path.'|'.md5(serialize($counts));
                if (isset(self::$cache[$key]) && self::$cache[$key][0] === $mtime) {
                    return self::$cache[$key][1];
                }
                $snapshot = is_file($path) ? SchemaSnapshot::fromFile($path) : null;
                $loaded = $snapshot === null && $counts === [] ? null : new self($snapshot, $counts);
                self::$cache[$key] = [$mtime, $loaded];

                return $loaded;
            }

            return $counts === [] ? null : new self(null, $counts);
        } catch (Throwable) {
            return null;
        }
    }

    public function rowCount(string $table): ?int
    {
        return $this->rowCounts[$table] ?? $this->snapshot?->rowCounts[$table] ?? null;
    }

    /** @return list<array{name: string, columns: list<string>, unique: bool}> */
    public function indexesFor(string $table): array
    {
        return $this->snapshot->tables[$table] ?? [];
    }

    /** @param list<string> $localIndexNames */
    public function differsFor(string $table, array $localIndexNames): ?string
    {
        $mine = array_map('strtolower', $localIndexNames);
        $missing = [];
        foreach ($this->indexesFor($table) as $index) {
            if (! in_array(strtolower($index['name']), $mine, true)) {
                $missing[] = $index['name'];
            }
        }

        return $missing === [] ? null : 'local schema differs from production: missing '.implode(', ', $missing);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}

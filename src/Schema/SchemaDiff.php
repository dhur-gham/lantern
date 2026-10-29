<?php

declare(strict_types=1);

namespace Lantern\Schema;

/**
 * Compares index layouts by table and ordered column list; an index is the same when production and the local
 * database index the same columns, whatever each calls it.
 */
final class SchemaDiff
{
    /**
     * @param array<string, list<array{name: string, columns: list<string>, unique: bool}>> $production
     * @param array<string, list<array{name: string, columns: list<string>, unique: bool}>> $local
     * @return array{tables_missing_locally: list<string>, missing_locally: list<array<string, mixed>>, extra_locally: list<array<string, mixed>>}
     */
    public static function compare(array $production, array $local): array
    {
        $missing = [];
        $extra = [];
        $tablesMissing = [];

        foreach ($production as $table => $indexes) {
            if (! array_key_exists($table, $local)) {
                $tablesMissing[] = (string) $table;

                continue;
            }
            $have = self::signatures($local[$table]);
            foreach ($indexes as $index) {
                if (! isset($have[self::signature($index)])) {
                    $missing[] = ['table' => (string) $table] + $index;
                }
            }
            $want = self::signatures($indexes);
            foreach ($local[$table] as $index) {
                if (! isset($want[self::signature($index)])) {
                    $extra[] = ['table' => (string) $table] + $index;
                }
            }
        }

        return ['tables_missing_locally' => $tablesMissing, 'missing_locally' => $missing, 'extra_locally' => $extra];
    }

    /**
     * @param list<array{name: string, columns: list<string>, unique: bool}> $indexes
     * @return array<string, true>
     */
    private static function signatures(array $indexes): array
    {
        $out = [];
        foreach ($indexes as $index) {
            $out[self::signature($index)] = true;
        }

        return $out;
    }

    /** @param array{name: string, columns: list<string>, unique: bool} $index */
    private static function signature(array $index): string
    {
        return strtolower(implode(',', $index['columns']));
    }
}

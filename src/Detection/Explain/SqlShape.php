<?php

declare(strict_types=1);

namespace Lantern\Detection\Explain;

/**
 * Best-effort reading of a SELECT: which tables it uses (resolving aliases), which columns of which table are
 * filtered or joined, and whether the WHERE is an OR whose branches touch different tables.
 */
final class SqlShape
{
    public const EQ = 'eq';

    public const IN = 'in';

    public const RANGE = 'range';

    private const RESERVED = [
        'where', 'on', 'inner', 'left', 'right', 'join', 'cross', 'natural', 'group', 'order', 'limit', 'using', 'set',
        'union', 'straight_join', 'full', 'outer', 'having', 'for', 'window', 'lateral', 'use', 'force', 'ignore', 'partition',
    ];

    private const NOT_COLUMNS = ['and', 'or', 'not', 'null', 'true', 'false', 'exists', 'select', 'where', 'on', 'when', 'then', 'else', 'case', 'end', 'interval', 'is', 'in', 'like', 'between'];

    /**
     * @param  array<string, string>  $aliases  lower-case alias or table name => table
     * @param  list<array{table: ?string, column: string, kind: string, join: bool}>  $predicates
     * @param  list<array{table: ?string, column: string, kind: string, join: bool}>  $common  predicates every OR branch shares
     */
    private function __construct(
        public readonly array $aliases,
        public readonly array $predicates,
        public readonly array $common,
        public readonly bool $orAcrossTables,
    ) {
    }

    public static function parse(string $sql): self
    {
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql) ?? $sql;
        $s = str_replace(['`', '"', '[', ']'], '', $s);

        $aliases = self::aliases($s);
        $tables = array_values(array_unique(array_values($aliases)));
        $single = count($tables) === 1 ? $tables[0] : null;

        $predicates = [];
        if (preg_match_all('/\bon\b(.*?)(?=\bjoin\b|\binner\b|\bleft\b|\bright\b|\bcross\b|\bwhere\b|\bgroup\s+by\b|\border\s+by\b|\blimit\b|\bhaving\b|$)/is', $s, $ons) > 0) {
            foreach ($ons[1] as $on) {
                array_push($predicates, ...self::extract($on, $aliases, $single, true));
            }
        }
        $joinPredicates = $predicates;

        $where = '';
        if (preg_match('/\bwhere\b(.*?)(?=\bgroup\s+by\b|\border\s+by\b|\blimit\b|\bhaving\b|\bunion\b|$)/is', $s, $m) === 1) {
            $where = $m[1];
        }

        $perBranch = [];
        foreach (self::branches($where) as $branch) {
            $perBranch[] = self::extract($branch, $aliases, $single, false);
        }
        foreach ($perBranch as $branch) {
            array_push($predicates, ...$branch);
        }

        $common = $joinPredicates;
        $cross = false;
        if (count($perBranch) > 1) {
            $keys = null;
            $tableSets = [];
            foreach ($perBranch as $branch) {
                $set = array_map(fn (array $p): string => strtolower($p['table'].'.'.$p['column']), $branch);
                $keys = $keys === null ? $set : array_values(array_intersect($keys, $set));
                $used = array_values(array_unique(array_filter(array_map(fn (array $p): ?string => $p['table'] === null ? null : strtolower($p['table']), $branch))));
                sort($used);
                if ($used !== []) {
                    $tableSets[] = $used;
                }
            }
            $cross = count(array_unique(array_map('json_encode', $tableSets))) > 1;
            foreach ($perBranch[0] as $p) {
                if (in_array(strtolower($p['table'].'.'.$p['column']), $keys ?? [], true)) {
                    $common[] = $p;
                }
            }
        } elseif ($perBranch !== []) {
            array_push($common, ...$perBranch[0]);
        }

        return new self($aliases, $predicates, $common, $cross);
    }

    /** Predicates an index could serve: for an OR across tables only those every branch shares. */
    public function usable(): array
    {
        return array_values(array_filter($this->orAcrossTables ? $this->common : $this->predicates, fn (array $p): bool => $p['kind'] !== 'skip'));
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_values(array_unique(array_values($this->aliases)));
    }

    public function table(string $nameOrAlias): string
    {
        return $this->aliases[strtolower($nameOrAlias)] ?? $nameOrAlias;
    }

    /** @return array<string, string> */
    private static function aliases(string $s): array
    {
        $ref = '[A-Za-z_][\w$]*(?:\.[A-Za-z_][\w$]*)?';
        $name = '(?!(?:'.implode('|', self::RESERVED).')\b)[A-Za-z_]\w*';
        $alias = '(?:\s+(?:as\s+)?('.$name.'))?';
        $out = [];
        $add = function (string $name, ?string $as) use (&$out): void {
            $table = str_contains($name, '.') ? substr($name, (int) strrpos($name, '.') + 1) : $name;
            $out[strtolower($table)] = $table;
            if ($as !== null) {
                $out[strtolower($as)] = $table;
            }
        };

        if (preg_match_all('/\b(?:from|join)\s+('.$ref.')'.$alias.'((?:\s*,\s*'.$ref.'(?:\s+(?:as\s+)?'.$name.')?)*)/i', $s, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $add($m[1], $m[2] !== '' ? $m[2] : null);
                if (($m[3] ?? '') !== '' && preg_match_all('/,\s*('.$ref.')'.$alias.'/i', $m[3], $tail, PREG_SET_ORDER) > 0) {
                    foreach ($tail as $t) {
                        $add($t[1], ($t[2] ?? '') !== '' ? $t[2] : null);
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<string> top-level OR branches of a WHERE body */
    private static function branches(string $where): array
    {
        $where = trim($where);
        while (self::wrapped($where)) {
            $where = trim(substr($where, 1, -1));
        }

        $parts = [];
        $depth = 0;
        $start = 0;
        $len = strlen($where);
        for ($i = 0; $i < $len; $i++) {
            $c = $where[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($depth === 0 && ($c === 'o' || $c === 'O')
                && ($i === 0 || preg_match('/[\w$]/', $where[$i - 1]) !== 1)
                && preg_match('/\Gor\b/i', $where, $m, 0, $i) === 1) {
                $parts[] = substr($where, $start, $i - $start);
                $start = $i + 2;
                $i++;
            }
        }
        $parts[] = substr($where, $start);

        return array_values(array_filter(array_map('trim', $parts), fn (string $p): bool => $p !== ''));
    }

    private static function wrapped(string $s): bool
    {
        if ($s === '' || $s[0] !== '(' || substr($s, -1) !== ')') {
            return false;
        }
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')' && --$depth === 0 && $i < $len - 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $aliases
     * @return list<array{table: ?string, column: string, kind: string, join: bool}>
     */
    private static function extract(string $text, array $aliases, ?string $single, bool $onClause): array
    {
        $re = '/(?:\b([A-Za-z_]\w*)\.)?\b([A-Za-z_]\w*)\s*(<=>|<>|!=|<=|>=|=|<|>|\bnot\s+(?:like|in|between)\b|\bis\s+not\b|\blike\b|\bin\b|\bis\b|\bbetween\b)/i';
        if (preg_match_all($re, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $out = [];
        foreach ($matches as $m) {
            $column = $m[2][0];
            if (in_array(strtolower($column), self::NOT_COLUMNS, true)) {
                continue;
            }
            $qualifier = $m[1][1] >= 0 && $m[1][0] !== '' ? strtolower($m[1][0]) : null;
            if ($qualifier !== null && ! isset($aliases[$qualifier])) {
                continue;
            }
            $table = $qualifier !== null ? $aliases[$qualifier] : $single;
            $op = strtolower((string) preg_replace('/\s+/', ' ', $m[3][0]));
            $kind = match ($op) {
                '=', '<=>', 'is' => self::EQ,
                'in' => self::IN,
                '<', '>', '<=', '>=', 'between', 'like' => self::RANGE,
                default => 'skip',
            };

            $join = $onClause;
            if (in_array($op, ['=', '<=>'], true)) {
                $after = (int) $m[0][1] + strlen($m[0][0]);
                if (preg_match('/\G\s*([A-Za-z_]\w*)\.([A-Za-z_]\w*)\b(?!\s*\()/', $text, $r, 0, $after) === 1 && isset($aliases[strtolower($r[1])])) {
                    $join = true;
                    $out[] = ['table' => $aliases[strtolower($r[1])], 'column' => $r[2], 'kind' => self::EQ, 'join' => true];
                }
            }
            $out[] = ['table' => $table, 'column' => $column, 'kind' => $kind, 'join' => $join];
        }

        return $out;
    }
}

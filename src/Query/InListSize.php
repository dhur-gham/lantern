<?php

declare(strict_types=1);

namespace Lantern\Query;

/** Finds the literal `IN (...)` lists of a SELECT (placeholders or inlined values) and counts their members. */
final class InListSize
{
    private const MAX_SQL_LENGTH = 200_000;

    /** Largest list length in a SELECT, or null when there is none (subqueries do not count). */
    public static function of(string $sql): ?int
    {
        $max = null;
        foreach (self::lists($sql) as $list) {
            $max = max($max ?? 0, $list['count']);
        }

        return $max;
    }

    /**
     * `at` is the offset of the `in` keyword, `start` the first byte inside the parentheses, `end` the closing parenthesis.
     *
     * @return list<array{at: int, start: int, end: int, count: int}>
     */
    public static function lists(string $sql): array
    {
        if (strlen($sql) > self::MAX_SQL_LENGTH || stripos(ltrim($sql, " \t\r\n("), 'select') !== 0
            || preg_match_all('/\bin\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $out = [];
        foreach ($m[0] as [$text, $offset]) {
            $parsed = self::parse($sql, $offset + strlen($text));
            if ($parsed !== null) {
                $out[] = ['at' => $offset] + $parsed;
            }
        }

        return $out;
    }

    /** @return array{start: int, end: int, count: int}|null */
    private static function parse(string $sql, int $start): ?array
    {
        if (preg_match('/\G\s*select\b/i', $sql, $unused, 0, $start) === 1) {
            return null;
        }

        $len = strlen($sql);
        $depth = 0;
        $commas = 0;
        $content = false;

        for ($i = $start; $i < $len; $i++) {
            $c = $sql[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $content = true;
                for ($i++; $i < $len; $i++) {
                    if ($c === "'" && $sql[$i] === '\\') {
                        $i++;

                        continue;
                    }
                    if ($sql[$i] === $c) {
                        if (($sql[$i + 1] ?? '') === $c) {
                            $i++;

                            continue;
                        }

                        break;
                    }
                }

                continue;
            }

            if ($c === '(') {
                $depth++;
                $content = true;
            } elseif ($c === ')') {
                if ($depth === 0) {
                    return $content ? ['start' => $start, 'end' => $i, 'count' => $commas + 1] : null;
                }
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $commas++;
            } elseif (! ctype_space($c)) {
                $content = true;
            }
        }

        return null;
    }
}

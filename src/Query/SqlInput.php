<?php

declare(strict_types=1);

namespace Lantern\Query;

/**
 * Prepares SQL copied from outside the app (an APM span description, a database log line) so the Fingerprinter
 * gives it the same shape as the app's own queries. Placeholder styles other than `?` and `$1` are rewritten to
 * `?` first; the Fingerprinter itself is unchanged, so stored fingerprints stay valid.
 */
final class SqlInput
{
    private const LEADING_KEYWORD = '/^(?:select|insert|update|delete|with|replace|merge|call|values|explain|show)\b/i';

    public static function looksLikeSql(string $sql): bool
    {
        $rest = ltrim(self::withoutLeadingNoise($sql), " \t\r\n(");

        return preg_match(self::LEADING_KEYWORD, $rest) === 1;
    }

    /** Rewrites `%s`, `%d`, `%(name)s` and `:name` placeholders to `?`, leaving quoted text, comments and `::` casts alone. */
    public static function prepare(string $sql): string
    {
        $len = strlen($sql);
        $out = '';
        $i = 0;

        while ($i < $len) {
            $c = $sql[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $end = self::skipQuoted($sql, $i, $c);
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $len : $end;
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '%' && preg_match('/\G%(?:\([A-Za-z_][A-Za-z0-9_]*\))?[sd]/', $sql, $m, 0, $i) === 1) {
                $out .= '?';
                $i += strlen($m[0]);

                continue;
            }

            if ($c === ':' && ($sql[$i + 1] ?? '') !== ':' && ! self::endsWithColonOrWord($out)
                && preg_match('/\G:[A-Za-z_][A-Za-z0-9_]*/', $sql, $m, 0, $i) === 1) {
                $out .= '?';
                $i += strlen($m[0]);

                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    /** Shape with identifier quoting and case removed, so `users` and `` `users` `` compare equal. */
    public static function loose(string $normalized): string
    {
        $plain = (string) preg_replace('/`([^`]*)`|"([^"]*)"|\[([A-Za-z_][^\]\[]*)\]/', '$1$2$3', $normalized);

        return strtolower(trim((string) preg_replace('/\s+/', ' ', $plain)));
    }

    /**
     * Word-token overlap (Jaccard) of two loose shapes, 0 to 1. Placeholders are not tokens.
     */
    public static function similarity(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }
        $union = count($ta + $tb);

        return $union === 0 ? 0.0 : round(count(array_intersect_key($ta, $tb)) / $union, 2);
    }

    /** @return array<string, true> */
    private static function tokens(string $loose): array
    {
        preg_match_all('/[a-z_][a-z0-9_.]*/', $loose, $m);

        return array_fill_keys($m[0], true);
    }

    private static function withoutLeadingNoise(string $sql): string
    {
        $sql = ltrim($sql);
        while ($sql !== '') {
            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/');
                $sql = $end === false ? '' : ltrim(substr($sql, $end + 2));
            } elseif (str_starts_with($sql, '--')) {
                $end = strpos($sql, "\n");
                $sql = $end === false ? '' : ltrim(substr($sql, $end + 1));
            } else {
                break;
            }
        }

        return $sql;
    }

    private static function endsWithColonOrWord(string $out): bool
    {
        return $out !== '' && preg_match('/[A-Za-z0-9_:]$/', $out) === 1;
    }

    private static function skipQuoted(string $sql, int $start, string $quote): int
    {
        $len = strlen($sql);
        $i = $start + 1;

        while ($i < $len) {
            if ($quote === "'" && $sql[$i] === '\\') {
                $i += 2;

                continue;
            }
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }
            $i++;
        }

        return $len;
    }
}

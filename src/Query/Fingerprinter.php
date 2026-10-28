<?php

declare(strict_types=1);

namespace Lantern\Query;

/**
 * Turns SQL into a stable "shape" so queries that differ only in literals,
 * bindings, IN-list length or insert row count share one fingerprint.
 *
 * normalize() lowercases everything outside quoted identifiers, so output is
 * case-stable; quoted identifiers ("x", `x`, [x]) are preserved verbatim.
 */
final class Fingerprinter
{
    private const CACHE_LIMIT = 512;

    /** @var array<string, string> normalized SQL by raw SQL; the char loop is the hot spot, and apps repeat the same statements. */
    private array $normalized = [];

    public function normalize(string $sql): string
    {
        if (isset($this->normalized[$sql])) {
            return $this->normalized[$sql];
        }

        $result = $this->compute($sql);

        if (count($this->normalized) >= self::CACHE_LIMIT) {
            $this->normalized = [];
        }

        return $this->normalized[$sql] = $result;
    }

    private function compute(string $sql): string
    {
        $len = strlen($sql);
        $out = '';
        $i = 0;

        while ($i < $len) {
            $c = $sql[$i];

            if (ctype_space($c)) {
                $out .= ' ';
                $i++;
                while ($i < $len && ctype_space($sql[$i])) {
                    $i++;
                }

                continue;
            }

            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $out .= ' ';

                continue;
            }

            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $out .= ' ';

                continue;
            }

            if ($c === "'") {
                $i = $this->skipQuoted($sql, $i, "'");
                $out .= '?';

                continue;
            }

            if ($c === '"' || $c === '`') {
                $end = $this->skipQuoted($sql, $i, $c);
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '[' && preg_match('/\G\[[A-Za-z_][^\]\[]*\]/', $sql, $m, 0, $i) === 1) {
                $out .= $m[0];
                $i += strlen($m[0]);

                continue;
            }

            if ($c === '$' && preg_match('/\G\$\d+/', $sql, $m, 0, $i) === 1) {
                $out .= '?';
                $i += strlen($m[0]);

                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $m, 0, $i);
                $out .= strtolower($m[0]);
                $i += strlen($m[0]);

                continue;
            }

            $numberAt = $i;
            if ($c === '-' && ctype_digit($sql[$i + 1] ?? '') && $this->signIsUnary($out)) {
                $numberAt = $i + 1;
            }

            if (preg_match('/\G(?:0x[0-9a-fA-F]+|\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|\.\d+)/', $sql, $m, 0, $numberAt) === 1
                && ! ($c === '.' && $out !== '' && preg_match('/[A-Za-z0-9_`"\]]$/', $out) === 1)) {
                $out .= '?';
                $i = $numberAt + strlen($m[0]);

                continue;
            }

            $out .= $c;
            $i++;
        }

        $out = trim((string) preg_replace('/ {2,}/', ' ', $out));
        $out = (string) preg_replace('/ ?([(),]) ?/', '$1', $out);
        $out = (string) preg_replace('/ ?([=<>!]+) ?/', ' $1 ', $out);

        return $this->collapseValues($this->collapseIn($out));
    }

    private function placeholderRun(string $s, int $at): int
    {
        $n = strspn($s, '?,', $at);
        if ($n === 0 || $s[$at] !== '?' || $s[$at + $n - 1] !== '?') {
            return 0;
        }

        return str_replace('?,', '', substr($s, $at, $n)) === '?' ? $n : 0;
    }

    private function wordBoundaryBefore(string $s, int $pos): bool
    {
        return $pos === 0 || ! (ctype_alnum($s[$pos - 1]) || $s[$pos - 1] === '_');
    }

    private function collapseIn(string $s): string
    {
        $out = '';
        $pos = 0;

        while (($at = strpos($s, 'in(', $pos)) !== false) {
            $start = $at + 3;
            $n = $this->wordBoundaryBefore($s, $at) ? $this->placeholderRun($s, $start) : 0;

            if ($n > 0 && ($s[$start + $n] ?? '') === ')') {
                $out .= substr($s, $pos, $at - $pos).'in (?)';
                $pos = $start + $n + 1;

                continue;
            }

            $out .= substr($s, $pos, $start - $pos);
            $pos = $start;
        }

        return $out.substr($s, $pos);
    }

    private function collapseValues(string $s): string
    {
        $out = '';
        $pos = 0;

        while (($at = strpos($s, 'values(', $pos)) !== false) {
            $start = $at + 7;
            $n = $this->wordBoundaryBefore($s, $at) ? $this->placeholderRun($s, $start) : 0;

            if ($n === 0 || ($s[$start + $n] ?? '') !== ')') {
                $out .= substr($s, $pos, $start - $pos);
                $pos = $start;

                continue;
            }

            $first = $start + $n + 1;
            $end = $first;
            while (substr($s, $end, 2) === ',(' && ($m = $this->placeholderRun($s, $end + 2)) > 0 && ($s[$end + 2 + $m] ?? '') === ')') {
                $end += $m + 3;
            }

            $out .= substr($s, $pos, $first - $pos);
            $pos = $end;
        }

        return $out.substr($s, $pos);
    }

    public function fingerprint(string $sql): string
    {
        return $this->hash($this->normalize($sql));
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    public function bindingsKey(string $sql, array $bindings): string
    {
        try {
            $serialized = serialize($bindings);
        } catch (\Throwable) {
            $serialized = (string) json_encode($bindings, JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return $this->hash($sql."\0".$serialized);
    }

    private function hash(string $value): string
    {
        static $algo = null;
        $algo ??= in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'md5';

        return hash($algo, $value);
    }

    private function skipQuoted(string $sql, int $start, string $quote): int
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

    private function signIsUnary(string $out): bool
    {
        $prev = rtrim($out);

        return $prev === '' || str_contains('=<>(,!', substr($prev, -1));
    }
}

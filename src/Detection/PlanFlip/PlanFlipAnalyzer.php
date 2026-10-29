<?php

declare(strict_types=1);

namespace Lantern\Detection\PlanFlip;

/**
 * Compares per-size samples of one query shape.
 *
 * A sample is ['in_size' => int, 'avg_ms' => float, 'rows' => ?int, 'table' => ?string, 'key' => ?string, 'full_scan' => bool, 'explained' => bool].
 * Samples that were never explained take part in time comparisons only.
 */
final class PlanFlipAnalyzer
{
    /** Quantizes a list length so nearby sizes share one sample (exact up to 64, then about 19% steps). */
    public static function bucket(int $size): int
    {
        if ($size <= 64) {
            return max(1, $size);
        }

        return (int) round(2 ** (round(log($size, 2) * 4) / 4));
    }

    /**
     * The first finding: a plan change that makes the query costlier than the list growth explains, else cost that
     * grows more than $factor times faster than the list.
     *
     * @param list<array<string, mixed>> $samples
     * @return array{kind: string, from: array<string, mixed>, to: array<string, mixed>, size_ratio: float, time_ratio: ?float, rows_ratio: ?float}|null
     */
    public static function find(array $samples, float $factor = 3.0, float $minMs = 10.0, int $minRows = 1000): ?array
    {
        $samples = self::sorted($samples);
        if (count($samples) < 2) {
            return null;
        }

        $planned = array_values(array_filter($samples, fn (array $s): bool => (bool) ($s['explained'] ?? false)));

        foreach (self::pairs($planned) as [$a, $b]) {
            if (self::signature($a) === self::signature($b)) {
                continue;
            }
            [$size, $time, $rows] = self::ratios($a, $b);
            $costlier = ($b['full_scan'] && ! $a['full_scan']) || ($rows !== null && $rows > $size) || ($time !== null && $time > $size);
            $significant = ($b['rows'] !== null && $b['rows'] >= $minRows) || $b['avg_ms'] >= $minMs;
            if ($costlier && $significant) {
                return ['kind' => 'plan', 'from' => $a, 'to' => $b, 'size_ratio' => $size, 'time_ratio' => $time, 'rows_ratio' => $rows];
            }
        }

        foreach (self::pairs($samples) as [$a, $b]) {
            [$size, $time, $rows] = self::ratios($a, $b);
            $slow = $time !== null && $b['avg_ms'] >= $minMs && $time > $factor * $size;
            $scan = $rows !== null && $b['rows'] >= $minRows && $rows > $factor * $size;
            if ($slow || $scan) {
                return ['kind' => 'growth', 'from' => $a, 'to' => $b, 'size_ratio' => $size, 'time_ratio' => $time, 'rows_ratio' => $rows];
            }
        }

        return null;
    }

    /**
     * Every point where the chosen plan differs between neighbouring sizes.
     *
     * @param list<array<string, mixed>> $samples
     * @return list<array{from: array<string, mixed>, to: array<string, mixed>}>
     */
    public static function changes(array $samples): array
    {
        $planned = array_values(array_filter(self::sorted($samples), fn (array $s): bool => (bool) ($s['explained'] ?? false)));
        $out = [];
        for ($i = 1; $i < count($planned); $i++) {
            if (self::signature($planned[$i - 1]) !== self::signature($planned[$i])) {
                $out[] = ['from' => $planned[$i - 1], 'to' => $planned[$i]];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $sample */
    public static function describe(array $sample): string
    {
        return match (true) {
            $sample['full_scan'] && $sample['table'] !== null => 'full scan on '.$sample['table'],
            $sample['key'] !== null => 'index '.$sample['key'].($sample['table'] !== null ? ' on '.$sample['table'] : ''),
            $sample['table'] !== null => 'table '.$sample['table'],
            default => 'unknown plan',
        };
    }

    /**
     * @param list<array<string, mixed>> $samples
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $samples): array
    {
        usort($samples, fn (array $a, array $b): int => $a['in_size'] <=> $b['in_size']);

        return $samples;
    }

    /**
     * Neighbouring samples plus the smallest against the largest, so slow drift over several steps is seen too.
     *
     * @param list<array<string, mixed>> $samples
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private static function pairs(array $samples): array
    {
        $pairs = [];
        for ($i = 1; $i < count($samples); $i++) {
            $pairs[] = [$samples[$i - 1], $samples[$i]];
        }
        if (count($samples) > 2) {
            $pairs[] = [$samples[0], $samples[count($samples) - 1]];
        }

        return array_values(array_filter($pairs, fn (array $p): bool => $p[1]['in_size'] > $p[0]['in_size']));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array{0: float, 1: ?float, 2: ?float} size, time and rows growth from $a to $b
     */
    private static function ratios(array $a, array $b): array
    {
        return [
            $b['in_size'] / $a['in_size'],
            $a['avg_ms'] > 0 && $b['avg_ms'] > 0 ? $b['avg_ms'] / $a['avg_ms'] : null,
            ($a['rows'] ?? 0) > 0 && $b['rows'] !== null ? $b['rows'] / $a['rows'] : null,
        ];
    }

    /** @param array<string, mixed> $sample */
    private static function signature(array $sample): string
    {
        return ($sample['table'] ?? '').'|'.($sample['key'] ?? '').'|'.($sample['full_scan'] ? 'scan' : 'seek');
    }
}

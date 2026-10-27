<?php

declare(strict_types=1);

namespace Lantern\Detection;

use Lantern\Trace\Trace;
use Throwable;

final class Analyzer
{
    /** @var list<Detector> */
    private array $detectors = [];

    /**
     * @param array<string, mixed> $config
     * @param list<Detector>|null $detectors
     */
    public function __construct(private readonly array $config, ?array $detectors = null)
    {
        if ($detectors !== null) {
            $this->detectors = array_values($detectors);

            return;
        }

        foreach ((array) ($config['detectors'] ?? []) as $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, Detector::class)) {
                $this->detectors[] = new $class($config);
            }
        }
    }

    /** @return list<Issue> */
    public function analyze(Trace $trace): array
    {
        $byKey = [];
        foreach ($this->detectors as $detector) {
            try {
                $found = $detector->detect($trace);
            } catch (Throwable) {
                continue;
            }

            foreach ($found as $issue) {
                if (! $issue instanceof Issue) {
                    continue;
                }
                if (! isset($byKey[$issue->key]) || $issue->count > $byKey[$issue->key]->count) {
                    $byKey[$issue->key] = $issue;
                }
            }
        }

        $issues = $this->mergeLazyLoads(array_values($byKey), $trace);
        $issues = $this->dropCoveredDuplicates($issues);
        usort($issues, fn (Issue $a, Issue $b) => [Issue::severityRank($b->severity), $b->totalMs] <=> [Issue::severityRank($a->severity), $a->totalMs]);

        $max = (int) ($this->config['thresholds']['max_issues_per_trace'] ?? 50);

        return $max > 0 ? array_slice($issues, 0, $max) : $issues;
    }

    /**
     * A lazy_load issue and an n_plus_one issue describing the same loop (same call site, and the lazy-load events are
     * immediately followed by the N+1 query spans) become one issue: the n_plus_one numbers plus the model/relation.
     *
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    private function mergeLazyLoads(array $issues, Trace $trace): array
    {
        $lazy = array_filter($issues, fn (Issue $i) => $i->type === Issue::TYPE_LAZY_LOAD);
        $nPlusOne = array_filter($issues, fn (Issue $i) => $i->type === Issue::TYPE_N_PLUS_ONE);
        if ($lazy === [] || $nPlusOne === []) {
            return $issues;
        }

        $ordered = array_values($trace->spans());
        $position = [];
        foreach ($ordered as $i => $span) {
            $position[$span->id] = $i;
        }

        $drop = [];
        $replace = [];
        foreach ($lazy as $lk => $l) {
            $next = [];
            foreach ($l->spanIds as $id) {
                for ($j = ($position[$id] ?? count($ordered)) + 1, $n = count($ordered); $j < $n; $j++) {
                    $s = $ordered[$j];
                    if ($s->type === \Lantern\Trace\SpanType::Query && ($s->meta['kind'] ?? null) !== 'transaction') {
                        $next[$s->id] = true;
                        break;
                    }
                }
            }

            $best = null;
            $bestOverlap = 0;
            foreach ($nPlusOne as $nk => $n) {
                if (isset($replace[$nk]) || ! $this->sameSite($l->callSite, $n->callSite)) {
                    continue;
                }
                $overlap = count(array_intersect_key($next, array_flip($n->spanIds)));
                if ($overlap > $bestOverlap) {
                    $best = $nk;
                    $bestOverlap = $overlap;
                }
            }

            if ($best === null || $bestOverlap * 2 < count($l->spanIds)) {
                continue;
            }

            $n = $nPlusOne[$best];
            $model = (string) ($l->meta['model'] ?? '');
            $relation = (string) ($l->meta['relation'] ?? '');
            $short = ($pos = strrpos($model, '\\')) === false ? $model : substr($model, $pos + 1);
            $site = $n->callSite === null ? 'unknown' : $n->callSite['file'].':'.$n->callSite['line'];
            $count = max($n->count, $l->count);

            $replace[$best] = new Issue(
                Issue::TYPE_N_PLUS_ONE,
                $n->key,
                sprintf('N+1: %s::%s lazy-loaded x%d at %s', $short, $relation, $count, $site),
                Issue::severityRank($l->severity) > Issue::severityRank($n->severity) ? $l->severity : $n->severity,
                $count,
                $n->totalMs,
                $n->callSite,
                array_values(array_unique(array_merge($n->spanIds, $l->spanIds))),
                $n->meta + ['model' => $model, 'relation' => $relation, 'lazy_load' => true],
                $l->suggestion ?? $n->suggestion,
            );
            $drop[$lk] = true;
        }

        $out = [];
        foreach ($issues as $k => $issue) {
            if (isset($replace[$k])) {
                $out[] = $replace[$k];
            } elseif (! isset($drop[$k])) {
                $out[] = $issue;
            }
        }

        return $out;
    }

    /**
     * A duplicate_query whose spans all belong to an n_plus_one issue is the same problem seen twice (the loop
     * happened to repeat a parameter), so only the N+1 is kept.
     *
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    private function dropCoveredDuplicates(array $issues): array
    {
        $covered = [];
        foreach ($issues as $i) {
            if ($i->type === Issue::TYPE_N_PLUS_ONE) {
                foreach ($i->spanIds as $id) {
                    $covered[$id] = true;
                }
            }
        }

        return array_values(array_filter($issues, function (Issue $i) use ($covered) {
            if ($i->type !== Issue::TYPE_DUPLICATE || $i->spanIds === []) {
                return true;
            }
            foreach ($i->spanIds as $id) {
                if (! isset($covered[$id])) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param array{file: string, line: int}|null $a
     * @param array{file: string, line: int}|null $b
     */
    private function sameSite(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null && $a['file'] === $b['file'] && $a['line'] === $b['line'];
    }
}

<?php

declare(strict_types=1);

namespace Lantern\Detection;

use Lantern\Trace\Span;

/**
 * Decides whether a query span is framework plumbing (sessions, cache, queue and migration tables, or anything
 * matching ignore_sql_patterns) that the N+1, duplicate, slow-query and missing-index detectors must not report.
 */
final class QueryFilter
{
    public const DEFAULT_TABLES = [
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens',
    ];

    /** @var list<string> */
    private array $tables;

    /** @var list<string> */
    private array $patterns;

    /** @param array<string, mixed> $config full config('lantern') */
    public function __construct(array $config = [])
    {
        $tables = array_key_exists('ignore_tables', $config) ? (array) $config['ignore_tables'] : self::DEFAULT_TABLES;
        $this->tables = array_values(array_map(fn ($t) => strtolower((string) $t), $tables));
        $this->patterns = array_values(array_map('strval', (array) ($config['ignore_sql_patterns'] ?? [])));
    }

    public function ignores(Span $span): bool
    {
        $sql = $span->meta['sql'] ?? null;

        return is_string($sql) && $this->ignoresSql($sql);
    }

    public function ignoresSql(string $sql): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (strlen($pattern) > 2 && $pattern[0] === '/') {
                $hit = @preg_match($pattern, $sql);
                if ($hit === 1) {
                    return true;
                }
                if ($hit === 0) {
                    continue;
                }
                // not a valid regex: treat it as a plain substring
            }
            if (stripos($sql, $pattern) !== false) {
                return true;
            }
        }

        if ($this->tables === []) {
            return false;
        }

        // The primary table is the first one named after from / into / update (a schema prefix and quotes are stripped).
        if (preg_match('/\b(?:from|into|update)\s+[`"\[]?(?:\w+[`"\]]?\.[`"\[]?)?(\w+)/i', $sql, $m) === 1) {
            return in_array(strtolower($m[1]), $this->tables, true);
        }

        return false;
    }
}

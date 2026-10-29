<?php

declare(strict_types=1);

namespace Lantern\Detection;

final class Issue
{
    public const TYPE_N_PLUS_ONE = 'n_plus_one';

    public const TYPE_DUPLICATE = 'duplicate_query';

    public const TYPE_SLOW = 'slow_query';

    public const TYPE_MISSING_INDEX = 'missing_index';

    public const TYPE_INDEX_NOT_CHOSEN = 'index_not_chosen';

    public const TYPE_PLAN_FLIP = 'plan_flip';

    public const TYPE_MEMORY = 'memory_hog';

    public const TYPE_LAZY_LOAD = 'lazy_load';

    public const SEV_INFO = 'info';

    public const SEV_WARNING = 'warning';

    public const SEV_CRITICAL = 'critical';

    /**
     * @param array{file: string, line: int, function?: string}|null $callSite
     * @param list<int> $spanIds
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $type,
        public string $key,
        public string $title,
        public string $severity,
        public int $count,
        public float $totalMs,
        public ?array $callSite,
        public array $spanIds,
        public array $meta = [],
        public ?string $suggestion = null,
    ) {
    }

    public static function key(string $type, string ...$parts): string
    {
        return hash('xxh128', $type.'|'.implode('|', $parts));
    }

    public static function severityRank(string $severity): int
    {
        return match ($severity) {
            self::SEV_CRITICAL => 2,
            self::SEV_WARNING => 1,
            default => 0,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
            'title' => $this->title,
            'severity' => $this->severity,
            'count' => $this->count,
            'total_ms' => $this->totalMs,
            'call_site' => $this->callSite,
            'span_ids' => $this->spanIds,
            'meta' => $this->meta,
            'suggestion' => $this->suggestion,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['type'] ?? ''),
            (string) ($data['key'] ?? ''),
            (string) ($data['title'] ?? ''),
            (string) ($data['severity'] ?? self::SEV_INFO),
            (int) ($data['count'] ?? 0),
            (float) ($data['total_ms'] ?? 0),
            $data['call_site'] ?? null,
            array_values(array_map('intval', $data['span_ids'] ?? [])),
            $data['meta'] ?? [],
            $data['suggestion'] ?? null,
        );
    }
}

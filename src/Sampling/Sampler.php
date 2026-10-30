<?php

declare(strict_types=1);

namespace Lantern\Sampling;

use Closure;
use Lantern\Trace\Trace;

final class Sampler
{
    /** @var Closure(): float */
    private readonly Closure $random;

    /** @param array<string, mixed> $config the full lantern config */
    public function __construct(private readonly array $config, ?Closure $random = null)
    {
        $this->random = $random ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    private bool $granted = false;

    /** The provider already rolled for this single-execution process; the first head decision reuses that roll. */
    public function grantFirst(): void
    {
        $this->granted = true;
    }

    /** Decided once per execution. Pass a key (e.g. a request id) for a deterministic decision. */
    public function headDecision(?string $key = null): bool
    {
        if ($this->granted) {
            $this->granted = false;

            return true;
        }

        $rate = $this->rate($this->config['sampling_rate'] ?? 1.0);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        if ($key !== null) {
            return (crc32($key) % 10000) / 10000 < $rate;
        }

        return ($this->random)() < $rate;
    }

    /** @param list<array<string, mixed>>|list<object> $issues */
    public function keepTrace(Trace $trace, array $issues): bool
    {
        if (($trace->meta['keep'] ?? false) === true) {
            return true;
        }

        if ($issues !== []) {
            return true;
        }

        if ($trace->totalMs() >= (float) ($this->config['keep_slow_ms'] ?? 1000)) {
            return true;
        }

        $status = $trace->meta['status'] ?? $trace->root()?->meta['status'] ?? null;
        if ((is_numeric($status) && (int) $status >= 500) || $status === 'failed') {
            return true;
        }

        $keep = $this->rate($this->config['keep_rate'] ?? self::defaultKeepRate());

        return $keep >= 1.0 || ($keep > 0.0 && ($this->random)() < $keep);
    }

    /** Clean traces are all kept locally, so a before/after diff always has both sides. */
    public static function defaultKeepRate(): float
    {
        try {
            return function_exists('app') && app()->isLocal() ? 1.0 : 0.1;
        } catch (\Throwable) {
            return 0.1;
        }
    }

    private function rate(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }
}

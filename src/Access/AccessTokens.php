<?php

declare(strict_types=1);

namespace Lantern\Access;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * One-time sign-in tokens, the registry of what Lantern issued, and a short access log, all in the cache store so
 * nothing needs a table. Tokens are stored hashed and consumed on first use.
 */
final class AccessTokens
{
    private const REGISTRY = 'lantern:access:registry';

    private const LOG = 'lantern:access:log';

    private const LOG_LIMIT = 100;

    public function __construct(private readonly Repository $cache) {}

    /** @param array<string, mixed> $payload @return string the token, shown once */
    public function issue(array $payload, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32));
        $this->cache->put($this->key($token), $payload + ['expires_at' => now()->getTimestamp() + $ttlSeconds], $ttlSeconds);

        return $token;
    }

    /** @return array<string, mixed>|null the payload, once; null when unknown, used or expired */
    public function pull(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $payload = $this->cache->pull($this->key($token));

        return is_array($payload) && ($payload['expires_at'] ?? 0) >= now()->getTimestamp() ? $payload : null;
    }

    public function forget(string $token): void
    {
        $this->cache->forget($this->key($token));
    }

    /** @param array<string, mixed> $entry @return string registry id */
    public function register(array $entry): string
    {
        $id = (string) Str::uuid();
        $entries = $this->registry();
        $entries[$id] = $entry + ['id' => $id, 'created_at' => now()->getTimestamp()];
        $this->cache->forever(self::REGISTRY, $entries);

        return $id;
    }

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void
    {
        $entries = $this->registry();
        if (isset($entries[$id])) {
            $entries[$id] = array_merge($entries[$id], $changes);
            $this->cache->forever(self::REGISTRY, $entries);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function registry(): array
    {
        $entries = $this->cache->get(self::REGISTRY, []);

        return is_array($entries) ? $entries : [];
    }

    public function remove(string $id): void
    {
        $entries = $this->registry();
        unset($entries[$id]);
        $this->cache->forever(self::REGISTRY, $entries);
    }

    /** @param array<string, mixed> $entry */
    public function log(array $entry): void
    {
        $log = $this->cache->get(self::LOG, []);
        $log = is_array($log) ? $log : [];
        $log[] = $entry + ['at' => date('c')];
        $this->cache->forever(self::LOG, array_slice($log, -self::LOG_LIMIT));
    }

    /** @return list<array<string, mixed>> */
    public function accessLog(): array
    {
        $log = $this->cache->get(self::LOG, []);

        return is_array($log) ? array_values($log) : [];
    }

    public function storeName(): string
    {
        return method_exists($this->cache, 'getStore') ? class_basename($this->cache->getStore()) : 'unknown';
    }

    private function key(string $token): string
    {
        return 'lantern:access:token:'.hash('sha256', $token);
    }
}

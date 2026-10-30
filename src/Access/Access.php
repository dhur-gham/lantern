<?php

declare(strict_types=1);

namespace Lantern\Access;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Lantern\Agent\AgentException;
use Throwable;

/**
 * Ways into the app for an agent or a developer, all local only: a one-time sign-in link for session guards, a short
 * lived token for Sanctum, app-minted credentials for anything else, and in-process sign-in for recorded code. The
 * login screen is never driven; the end state of a login is created directly.
 */
final class Access
{
    public const TTL_SECONDS = 300;

    public const PATH = '/_lantern/access';

    public function __construct(private readonly Application $app, private readonly AccessTokens $tokens) {}

    /** The route and the commands exist only locally, with Lantern and access on. */
    public static function available(Application $app): bool
    {
        $config = $app['config'];

        return $app->environment('local')
            && (bool) $config->get('lantern.enabled')
            && ! $config->get('lantern.kill_switch')
            && (bool) $config->get('lantern.access.enabled', true);
    }

    /** @return array<string, array<string, mixed>> */
    public function surfaces(): array
    {
        return array_filter((array) config('lantern.access.surfaces', []), 'is_array');
    }

    /** @return array<string, mixed> */
    public function surface(string $name): array
    {
        $surfaces = $this->surfaces();
        if (! isset($surfaces[$name])) {
            throw AgentException::usage(
                'Unknown surface: '.$name,
                $surfaces === [] ? 'No surfaces are configured. Add them under lantern.access.surfaces (php artisan lantern:init proposes them).' : 'Available: '.implode(', ', array_keys($surfaces)).'.',
            );
        }

        return $surfaces[$name] + ['type' => 'session'];
    }

    public function requireAvailable(): void
    {
        if (! self::available($this->app)) {
            throw AgentException::config(
                'Access is only available with APP_ENV=local, LANTERN_ENABLED=true and lantern.access.enabled on.',
                'Run this on a local copy with Lantern enabled; the sign-in route is not registered otherwise.',
            );
        }
    }

    /**
     * A one-time sign-in link.
     *
     * @return array{url: string, expires_at: string, user: array{id: mixed, label: string}, registry_id: string}
     */
    public function sessionLink(string $name, ?string $userOverride, ?string $to, string $baseUrl): array
    {
        $surface = $this->surface($name);
        $this->requireType($name, $surface, 'session');
        $user = $this->user($surface, $userOverride);
        $to = $this->relativePath($to ?? (string) ($surface['home'] ?? '/'));

        $token = $this->tokens->issue(['surface' => $name, 'user_id' => $user->getAuthIdentifier(), 'to' => $to], self::TTL_SECONDS);
        $expires = now()->getTimestamp() + self::TTL_SECONDS;
        $id = $this->tokens->register(['surface' => $name, 'type' => 'session', 'user' => $this->describe($user), 'expires_at' => $expires, 'token' => $token]);

        return [
            'url' => rtrim($baseUrl, '/').self::PATH.'/'.$token,
            'expires_at' => date('c', $expires),
            'user' => $this->describe($user),
            'registry_id' => $id,
        ];
    }

    /**
     * Completes a sign-in link: signs the user in, starts a fresh session, applies the surface's session values.
     *
     * @return string|null the relative path to redirect to, null when the token is not valid
     */
    public function consume(string $token, Request $request): ?string
    {
        $payload = $this->tokens->pull($token);
        if ($payload === null) {
            return null;
        }

        $surface = $this->surface((string) $payload['surface']);
        $guard = $this->guard($surface);
        $user = $guard->getProvider()->retrieveById($payload['user_id']);
        if ($user === null || ! $guard instanceof SessionGuard) {
            return null;
        }

        $this->signIn($guard, $user, (array) ($surface['session'] ?? []), $request);

        foreach ($this->tokens->registry() as $id => $entry) {
            if (($entry['token'] ?? null) === $token) {
                $this->tokens->update($id, ['sessions' => [...(array) ($entry['sessions'] ?? []), $request->session()->getId()], 'used' => true]);
            }
        }
        $this->tokens->log(['surface' => $payload['surface'], 'type' => 'session', 'user_id' => $payload['user_id'], 'ip' => $request->ip(), 'app' => config('app.name')]);

        return $this->relativePath((string) ($payload['to'] ?? '/'));
    }

    /**
     * A Sanctum personal access token for a short while.
     *
     * @return array{headers: array<string, string>, expires_at: string, user: array{id: mixed, label: string}, registry_id: string}
     */
    public function sanctumToken(string $name, ?string $userOverride): array
    {
        $surface = $this->surface($name);
        $this->requireType($name, $surface, 'sanctum');
        $user = $this->user($surface, $userOverride, 'web');
        if (! method_exists($user, 'createToken')) {
            throw AgentException::config('The user model of surface "'.$name.'" has no createToken(); add Laravel\Sanctum\HasApiTokens.');
        }

        $minutes = max(1, (int) ($surface['ttl_minutes'] ?? 30));
        $expires = now()->addMinutes($minutes);
        $new = $user->createToken('lantern-access', (array) ($surface['abilities'] ?? ['*']), $expires);
        $id = $this->tokens->register(['surface' => $name, 'type' => 'sanctum', 'user' => $this->describe($user), 'expires_at' => $expires->getTimestamp(), 'token_id' => $new->accessToken->getKey()]);
        $this->tokens->log(['surface' => $name, 'type' => 'sanctum', 'user_id' => $user->getAuthIdentifier(), 'app' => config('app.name')]);

        return [
            'headers' => ['Authorization' => 'Bearer '.$new->plainTextToken] + array_map('strval', (array) ($surface['headers'] ?? [])),
            'expires_at' => $expires->toIso8601String(),
            'user' => $this->describe($user),
            'registry_id' => $id,
        ];
    }

    /**
     * Credentials the app mints for a custom guard.
     *
     * @return array{headers: array<string, string>, query?: array<string, string>, expires_at: string|null, subject: array<string, mixed>, registry_id: string}
     */
    public function customCredentials(string $name): array
    {
        $surface = $this->surface($name);
        $this->requireType($name, $surface, 'custom');
        $provider = $this->provider($name, $surface);
        $subject = (array) ($surface['subject'] ?? []);
        $issued = $provider->issue($subject);
        $expires = isset($issued['expires_at']) && $issued['expires_at'] instanceof \DateTimeInterface ? $issued['expires_at']->getTimestamp() : null;
        $stored = $issued;
        unset($stored['expires_at']);
        $id = $this->tokens->register(['surface' => $name, 'type' => 'custom', 'subject' => $subject, 'expires_at' => $expires, 'issued' => $stored + ['expires_at' => $expires]]);
        $this->tokens->log(['surface' => $name, 'type' => 'custom', 'subject' => $subject, 'app' => config('app.name')]);

        return [
            'headers' => array_map('strval', (array) ($issued['headers'] ?? [])),
            'query' => array_map('strval', (array) ($issued['query'] ?? [])),
            'expires_at' => $expires === null ? null : date('c', $expires),
            'subject' => $subject,
            'registry_id' => $id,
        ];
    }

    /** Signs a surface's user in for code running in this process (probes, Livewire::test). */
    public function actingAs(string $name, ?string $userOverride = null): Authenticatable
    {
        $surface = $this->surface($name);
        $type = (string) $surface['type'];

        if ($type === 'custom') {
            $provider = $this->provider($name, $surface);
            if (! $provider instanceof ProvidesUser) {
                throw AgentException::config('Surface "'.$name.'" cannot sign a user in: its credentials class does not implement '.ProvidesUser::class.'.');
            }
            $user = $provider->user((array) ($surface['subject'] ?? []));
            $guard = $this->app['auth']->guard((string) ($surface['guard'] ?? $this->app['config']->get('auth.defaults.guard')));
            $this->app['auth']->shouldUse((string) ($surface['guard'] ?? $this->app['config']->get('auth.defaults.guard')));
            if (method_exists($guard, 'setUser')) {
                $guard->setUser($user);
            }

            return $user;
        }

        $user = $this->user($surface, $userOverride, $type === 'sanctum' ? 'web' : null);
        $guardName = (string) ($surface['guard'] ?? 'web');
        $this->app['auth']->shouldUse($guardName);
        $guard = $this->app['auth']->guard($guardName);
        if (method_exists($guard, 'setUser')) {
            $guard->setUser($user);
        }
        if ($type === 'session' && $this->app->bound('session')) {
            foreach ((array) ($surface['session'] ?? []) as $key => $value) {
                $this->app['session']->put((string) $key, $value);
            }
        }
        if ($type === 'sanctum' && class_exists(\Laravel\Sanctum\Sanctum::class)) {
            \Laravel\Sanctum\Sanctum::actingAs($user, (array) ($surface['abilities'] ?? ['*']), $guardName);
        }

        return $user;
    }

    /** @return list<array<string, mixed>> what is outstanding, newest first */
    public function issued(): array
    {
        $now = now()->getTimestamp();
        $out = [];
        foreach ($this->tokens->registry() as $entry) {
            $expires = $entry['expires_at'] ?? null;
            $out[] = [
                'id' => $entry['id'],
                'surface' => $entry['surface'],
                'type' => $entry['type'],
                'user' => $entry['user'] ?? ($entry['subject'] ?? null),
                'expires_at' => $expires === null ? null : date('c', (int) $expires),
                'expired' => $expires !== null && (int) $expires < $now,
                'used' => (bool) ($entry['used'] ?? false),
            ];
        }

        return array_reverse($out);
    }

    /** Revokes what Lantern issued: unused links, Sanctum tokens, app credentials, and sessions started by a link. */
    public function revoke(?string $surface, bool $all): int
    {
        $count = 0;
        foreach ($this->tokens->registry() as $id => $entry) {
            if (! $all && ($entry['surface'] ?? null) !== $surface) {
                continue;
            }
            try {
                match ($entry['type'] ?? '') {
                    'session' => $this->revokeSession($entry),
                    'sanctum' => $this->revokeSanctum($entry),
                    'custom' => $this->provider((string) $entry['surface'], $this->surface((string) $entry['surface']))->revoke((array) ($entry['issued'] ?? [])),
                    default => null,
                };
            } catch (Throwable) {
                // an entry that cannot be revoked (surface removed from config) is dropped from the registry anyway
            }
            $this->tokens->remove($id);
            $count++;
        }

        return $count;
    }

    /** @return array{id: mixed, label: string} */
    public function describe(Authenticatable $user): array
    {
        $label = (string) ($user->email ?? $user->name ?? $user->getAuthIdentifier());

        return ['id' => $user->getAuthIdentifier(), 'label' => $label];
    }

    /** Only a path on this host: no scheme, no host, no protocol-relative or backslash tricks. */
    public function relativePath(string $to): string
    {
        $to = trim($to);
        if ($to === '') {
            return '/';
        }
        if ($to[0] !== '/' || str_starts_with($to, '//') || str_contains($to, '\\') || preg_match('/[\x00-\x1f]/', $to) === 1) {
            throw AgentException::usage('--to must be a path on this app, like /admin/items/1/edit.', 'Absolute URLs are refused.');
        }

        return $to;
    }

    /** @param array<string, mixed> $surface */
    public function user(array $surface, ?string $override = null, ?string $defaultGuard = null): Authenticatable
    {
        $guard = $this->guard($surface, $defaultGuard);
        $provider = $guard->getProvider();
        $spec = $override ?? ($surface['user'] ?? null);
        $user = null;

        if (is_array($spec)) {
            $user = $provider->retrieveByCredentials($spec);
        } elseif (is_string($spec) && str_contains($spec, '@')) {
            $user = $provider->retrieveByCredentials(['email' => $spec]);
        } elseif ($spec !== null && $spec !== '') {
            $user = $provider->retrieveById($spec);
        }

        if ($user === null) {
            throw AgentException::config(
                $spec === null ? 'Surface has no default user.' : 'No user found for '.(is_array($spec) ? json_encode($spec) : (string) $spec).'.',
                'Set "user" on the surface (an id, an email, or [\'email\' => ...]) or pass --user=.',
            );
        }

        return $user;
    }

    /** @param array<string, mixed> $surface */
    private function guard(array $surface, ?string $default = null): \Illuminate\Contracts\Auth\Guard
    {
        $name = (string) ($surface['guard'] ?? $default ?? $this->app['config']->get('auth.defaults.guard', 'web'));
        if ($this->app['config']->get('auth.guards.'.$name) === null) {
            throw AgentException::config('Auth guard "'.$name.'" is not defined in config/auth.php.');
        }

        return $this->app['auth']->guard($name);
    }

    /** @param array<string, mixed> $values */
    private function signIn(SessionGuard $guard, Authenticatable $user, array $values, Request $request): void
    {
        if ((bool) config('lantern.access.fire_login_event', false)) {
            $guard->login($user);
        } else {
            // the same end state as login(), without the Login event: app listeners may send mail or write audit rows
            $request->session()->put($guard->getName(), $user->getAuthIdentifier());
            $guard->setUser($user);
        }
        $request->session()->regenerate();
        foreach ($values as $key => $value) {
            $request->session()->put((string) $key, $value);
        }
    }

    /** @param array<string, mixed> $entry */
    private function revokeSession(array $entry): void
    {
        if (isset($entry['token'])) {
            $this->tokens->forget((string) $entry['token']);
        }
        $handler = $this->app['session']->driver()->getHandler();
        foreach ((array) ($entry['sessions'] ?? []) as $sessionId) {
            $handler->destroy((string) $sessionId);
        }
    }

    /** @param array<string, mixed> $entry */
    private function revokeSanctum(array $entry): void
    {
        $model = \Laravel\Sanctum\Sanctum::$personalAccessTokenModel ?? null;
        if (is_string($model) && isset($entry['token_id'])) {
            $model::query()->whereKey($entry['token_id'])->delete();
        }
    }

    /** @param array<string, mixed> $surface */
    private function provider(string $name, array $surface): ProvidesCredentials
    {
        $class = $surface['credentials'] ?? null;
        $instance = is_string($class) && class_exists($class) ? $this->app->make($class) : null;
        if (! $instance instanceof ProvidesCredentials) {
            throw AgentException::config('Surface "'.$name.'" needs a "credentials" class implementing '.ProvidesCredentials::class.'.');
        }

        return $instance;
    }

    /** @param array<string, mixed> $surface */
    private function requireType(string $name, array $surface, string $type): void
    {
        if (($surface['type'] ?? 'session') !== $type) {
            throw AgentException::usage('Surface "'.$name.'" is of type '.($surface['type'] ?? 'session').', not '.$type.'.');
        }
    }
}

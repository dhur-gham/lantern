<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Access\Access;
use Lantern\Access\AccessTokens;
use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;
use Lantern\Agent\Reader;
use Throwable;

final class LoginCommand extends AgentCommand
{
    protected $signature = 'lantern:login
        {surface? : Surface from lantern.access.surfaces}
        {--user= : User id or email instead of the surface default}
        {--to= : Path to open after signing in (session surfaces)}
        {--list : List surfaces and what is outstanding}
        {--revoke : Revoke what was issued for the surface}
        {--all : With --revoke, revoke everything}
        {--format=json : json, ndjson, table or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Local-only sign-in for an agent: a one-time link (session), a short-lived token (Sanctum) or app credentials';

    /** The command needs no Lantern storage, so it skips the base class check; storage is only read for the host check. */
    public function handle(): int
    {
        try {
            $this->format();

            return $this->perform(new Reader($this->laravel->make(\Lantern\Storage\TraceQueries::class)));
        } catch (AgentException $e) {
            return $this->failWith($e);
        } catch (Throwable $e) {
            return $this->failWith(AgentException::config($e->getMessage()));
        }
    }

    protected function perform(Reader $reader): int
    {
        /** @var Access $access */
        $access = $this->laravel->make(Access::class);
        $access->requireAvailable();

        if ($this->option('list')) {
            return $this->list($access);
        }
        if ($this->option('revoke')) {
            return $this->revoke($access);
        }

        $name = (string) $this->argument('surface');
        if ($name === '') {
            throw AgentException::usage('Missing surface.', 'Run: php artisan lantern:login --list');
        }

        $surface = $access->surface($name);
        $user = $this->option('user') !== null && $this->option('user') !== '' ? (string) $this->option('user') : null;

        $payload = match ((string) $surface['type']) {
            'session' => $this->session($access, $reader, $name, $user),
            'sanctum' => $this->sanctum($access, $name, $user),
            'custom' => $this->custom($access, $name),
            default => throw AgentException::config('Surface "'.$name.'" has an unknown type: '.$surface['type'].'.', 'Use session, sanctum or custom.'),
        };

        $this->emit(['schema_version' => Findings::VERSION, 'schema' => 'lantern.login', 'surface' => $name, 'type' => $surface['type']] + $payload);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function session(Access $access, Reader $reader, string $name, ?string $user): array
    {
        [$base, $warning] = $this->baseUrl($reader);
        $link = $access->sessionLink($name, $user, $this->option('to') !== null && $this->option('to') !== '' ? (string) $this->option('to') : null, $base);

        return array_filter([
            'url' => $link['url'],
            'expires_at' => $link['expires_at'],
            'user' => $link['user'],
            'use' => 'Open url once in the browser; the browser then holds a normal session cookie. The link works once.',
            'warning' => $warning,
            'next' => 'After reproducing: php artisan lantern:issues --since=10m --format=json',
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private function sanctum(Access $access, string $name, ?string $user): array
    {
        $token = $access->sanctumToken($name, $user);

        return [
            'headers' => $token['headers'],
            'expires_at' => $token['expires_at'],
            'user' => $token['user'],
            'use' => 'Send these headers with each request to the API.',
            'next' => 'Revoke when done: php artisan lantern:login '.$name.' --revoke',
        ];
    }

    /** @return array<string, mixed> */
    private function custom(Access $access, string $name): array
    {
        $creds = $access->customCredentials($name);

        return array_filter([
            'headers' => $creds['headers'],
            'query' => $creds['query'] ?: null,
            'expires_at' => $creds['expires_at'],
            'subject' => $creds['subject'],
            'use' => 'Send these headers (and query parameters) with each request.',
            'next' => 'Revoke when done: php artisan lantern:login '.$name.' --revoke',
        ], fn ($v) => $v !== null);
    }

    private function list(Access $access): int
    {
        $surfaces = [];
        foreach ($access->surfaces() as $name => $surface) {
            $type = (string) ($surface['type'] ?? 'session');
            $problem = null;
            try {
                match ($type) {
                    'session' => $access->user($surface),
                    'sanctum' => $access->user($surface, null, 'web'),
                    'custom' => is_string($surface['credentials'] ?? null) && class_exists($surface['credentials']) ? null : throw AgentException::config('credentials class not found'),
                    default => throw AgentException::config('unknown type'),
                };
            } catch (Throwable $e) {
                $problem = $e->getMessage();
            }
            $surfaces[] = array_filter(['name' => $name, 'type' => $type, 'guard' => $surface['guard'] ?? null, 'usable' => $problem === null, 'problem' => $problem], fn ($v) => $v !== null);
        }

        $this->emit([
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.login.list',
            'surfaces' => $surfaces,
            'issued' => $access->issued(),
            'cache_store' => $this->laravel->make(AccessTokens::class)->storeName(),
            'hint' => $surfaces === [] ? 'No surfaces configured. php artisan lantern:init proposes them.' : 'php artisan lantern:login <surface> --format=json',
        ], 'surfaces');

        return self::SUCCESS;
    }

    private function revoke(Access $access): int
    {
        $name = (string) $this->argument('surface');
        if ($name === '' && ! $this->option('all')) {
            throw AgentException::usage('--revoke needs a surface or --all.');
        }
        if ($name !== '') {
            $access->surface($name);
        }

        $this->emit(['schema_version' => Findings::VERSION, 'schema' => 'lantern.login.revoke', 'revoked' => $access->revoke($name !== '' ? $name : null, (bool) $this->option('all'))]);

        return self::SUCCESS;
    }

    /**
     * APP_URL as the base of the link, checked against the host the browser reached on the last request Lantern saw.
     *
     * @return array{0: string, 1: string|null} base url, and a warning when no request has been seen to compare with
     */
    private function baseUrl(Reader $reader): array
    {
        $url = (string) config('app.url');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            throw AgentException::config('APP_URL has no host ("'.$url.'"); the link would not open the right site.', 'Set APP_URL to the address the browser uses, for example http://myapp.test.');
        }

        $expected = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $seen = $this->lastHost($reader);
        if ($seen !== null && strcasecmp($seen, $expected) !== 0 && strcasecmp(explode(':', $seen)[0], $parts['host']) !== 0) {
            throw AgentException::config(
                'APP_URL host ('.$expected.') differs from the host of the last request Lantern saw ('.$seen.'). The session cookie would not be sent.',
                'Set APP_URL to http(s)://'.$seen.' (or open the app on '.$expected.' once to refresh).',
            );
        }

        return [rtrim($url, '/'), $seen === null ? 'No request has been recorded yet, so APP_URL could not be checked against the host the browser uses.' : null];
    }

    private function lastHost(Reader $reader): ?string
    {
        try {
            if (config('lantern.storage.driver', 'memory') !== 'database') {
                return null;
            }
            foreach ($reader->traces([], 15) as $row) {
                if (($row['method'] ?? null) === null) {
                    continue;
                }
                $trace = $reader->traceRow((string) $row['id']);
                $host = $trace['payload']['meta']['host'] ?? null;
                if (is_string($host) && $host !== '') {
                    return $host;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }
}

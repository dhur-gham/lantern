<?php

declare(strict_types=1);

namespace Lantern\Scenario;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lantern\Access\Access;
use Lantern\Access\AccessTokens;
use Lantern\Agent\AgentException;
use Lantern\Recording;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/** Runs a scenario inside the current process; every step, and every Livewire action, becomes one `record:` trace. */
final class ScenarioRunner
{
    public function __construct(
        private readonly Application $app,
        private readonly Recording $recording,
    ) {}

    public function run(Scenario $scenario, bool $verifyRollback = true): void
    {
        $this->fake($scenario->effectiveFakes(Scenario::fakes(config('lantern.scenarios.fake', []))));

        if (! $this->throttles($scenario)) {
            $this->app->bind(ThrottleRequests::class, NoThrottle::class);
            $this->app->bind(ThrottleRequestsWithRedis::class, NoThrottle::class);
        }

        if ($verifyRollback && config('lantern.scenarios.verify_rollback', true)) {
            WriteGuard::forApp($this->app)->start();
        }

        foreach ($scenario->steps as $index => $step) {
            $this->resetIdentity();
            if (($step['as']['via'] ?? null) !== 'token') {
                $this->signIn($step['as']['surface'] ?? $scenario->surface, $step['as']['user'] ?? $scenario->user);
            }
            $this->putSession($scenario->session);
            $this->putSession($step['session']);

            $prefix = sprintf('%s:%02d', $scenario->name, $index + 1);

            $step['kind'] === 'livewire'
                ? $this->livewire($prefix, $step)
                : $this->request($prefix, $step, $scenario);
        }
    }

    /** @param  array<string, mixed>  $step */
    private function livewire(string $prefix, array $step): void
    {
        if (! class_exists(Livewire::class)) {
            throw AgentException::config('Livewire is not installed, so a "livewire" step cannot run.');
        }
        if (! class_exists($step['class'])) {
            throw AgentException::notFound('Livewire component not found: '.$step['class'], 'Use the full class name, such as App\\Livewire\\Orders.');
        }

        $test = $this->recording->record("{$prefix}:mount", fn () => Livewire::test($step['class'], $step['mount']));

        foreach ($step['do'] as $action) {
            if (isset($action['upload'])) {
                $files = array_map(fn (array $spec) => $this->fakeFile($spec), $action['files']);
                $value = $action['multiple'] ? $files : $files[0];
                $this->recording->record("{$prefix}:upload {$action['upload']}", fn () => $test->set($action['upload'], $value));

                continue;
            }

            if (isset($action['set'])) {
                $this->recording->record("{$prefix}:set {$action['set']}", fn () => $test->set($action['set'], $action['value']));

                continue;
            }

            $this->recording->record("{$prefix}:call {$action['call']}", fn () => $test->call($action['call'], ...$action['args']));
        }
    }

    /** @param  array<string, mixed>  $step */
    private function request(string $prefix, array $step, Scenario $scenario): void
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        foreach ($step['headers'] as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', (string) $name))] = $value;
        }

        $body = null;
        if ($step['json'] !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $body = json_encode($step['json'], JSON_THROW_ON_ERROR);
        }

        $files = $this->requestFiles($step['files']);
        if ($files !== []) {
            $server['CONTENT_TYPE'] = 'multipart/form-data';
        }

        $issued = $this->tokenFor($step, $scenario);
        foreach ($issued['headers'] ?? [] as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', (string) $name))] ??= $value;
        }

        $label = "{$prefix}:{$step['method']} {$step['path']}";

        try {
            $this->recording->record($label, function () use ($step, $server, $body, $files): void {
                $request = Request::create($this->uri($step), $step['method'], $this->normalised($step['form']), [], $files, $server, $body);
                $kernel = $this->app->make(Kernel::class);

                $response = $kernel->handle($request);
                $this->finish($kernel, $request, $response, $step['expect']);
            });
        } finally {
            $this->discard($issued);
        }
    }

    /**
     * With `as.via: token` the request carries a real Sanctum token, so token lookup runs as for an API client.
     *
     * @param  array<string, mixed>  $step
     * @return array{headers: array<string, string>, registry_id: string, owner: Authenticatable}|null
     */
    private function tokenFor(array $step, Scenario $scenario): ?array
    {
        if (($step['as']['via'] ?? null) !== 'token') {
            return null;
        }

        $surface = $step['as']['surface'] ?? $scenario->surface;
        if ($surface === null) {
            throw AgentException::usage('as.via "token" needs a surface: set "surface" on the scenario or in "as".');
        }

        $access = $this->app->make(Access::class);
        $type = (string) ($access->surface($surface)['type'] ?? '');
        if ($type !== 'sanctum') {
            throw AgentException::usage("as.via \"token\" works with sanctum surfaces; \"{$surface}\" is {$type}.");
        }

        $user = $step['as']['user'] ?? $scenario->user;
        $issued = $access->sanctumToken($surface, $user);
        $issued += ['owner' => $access->user($access->surface($surface), $user, 'web')];

        $createdOn = $this->tokenCreatedOn($issued['owner']);
        $lookedUpOn = $this->tokenLookedUpOn();
        if ($createdOn !== $lookedUpOn) {
            $this->discard($issued);

            throw AgentException::config(
                "as.via \"token\" cannot work here: the token is created on connection \"{$createdOn}\" (the user model's) inside this run's rollback transaction, but Sanctum looks tokens up on \"{$lookedUpOn}\", and another connection cannot see a row that is not committed yet.",
                'Use as.surface alone (the user is signed in directly), or make the user model and Sanctum use the same connection.',
            );
        }

        return $issued;
    }

    private function tokenCreatedOn(Authenticatable $owner): string
    {
        $connection = method_exists($owner, 'tokens') ? $owner->tokens()->getRelated()->getConnectionName() : null;

        return (string) ($connection ?? config('database.default'));
    }

    private function tokenLookedUpOn(): string
    {
        $model = Sanctum::$personalAccessTokenModel;
        $connection = (new $model)->getConnectionName();

        return (string) ($connection ?? config('database.default'));
    }

    /**
     * The token row goes through the owner's own relation: a related model inherits its parent's connection, so this is
     * the connection the token was created on. Another connection to the same database would wait for the lock the
     * rolled-back transaction of this run still holds on the new row, and never get it.
     *
     * @param  array{headers: array<string, string>, registry_id: string, owner: Authenticatable}|null  $issued
     */
    private function discard(?array $issued): void
    {
        if ($issued === null) {
            return;
        }

        $tokens = $this->app->make(AccessTokens::class);
        $tokenId = $tokens->registry()[$issued['registry_id']]['token_id'] ?? null;
        $tokens->remove($issued['registry_id']);

        if ($tokenId !== null && method_exists($issued['owner'], 'tokens')) {
            $issued['owner']->tokens()->whereKey($tokenId)->delete();
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $specs
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    private function requestFiles(array $specs): array
    {
        $files = [];
        foreach ($specs as $field => $list) {
            $made = array_map(fn (array $spec) => $this->fakeFile($spec), $list);
            $files[$field] = count($made) === 1 ? $made[0] : $made;
        }

        return $files;
    }

    /** @param  array<string, mixed>  $spec */
    private function fakeFile(array $spec): UploadedFile
    {
        if ($spec['kind'] === 'image') {
            if (! extension_loaded('gd')) {
                throw AgentException::config('Uploading an image needs the PHP gd extension (Laravel generates the image with it).', 'Install ext-gd, or upload {"kind": "file"} instead.');
            }

            return UploadedFile::fake()->image($spec['name'], $spec['width'], $spec['height'])->size($spec['kb']);
        }

        return UploadedFile::fake()->create($spec['name'], $spec['kb'], $spec['mime']);
    }

    /**
     * The values as a real request has them: strings, in the order of the query string.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalised(array $values): array
    {
        parse_str(http_build_query($values), $out);

        return $out;
    }

    private function throttles(Scenario $scenario): bool
    {
        return $scenario->throttle ?? (bool) config('lantern.scenarios.throttle', true);
    }

    /**
     * The query goes into the URL, as a client sends it: for a POST, Request::create would take it as form data, and it
     * would keep ints as ints where a real request only has strings.
     *
     * @param  array<string, mixed>  $step
     */
    private function uri(array $step): string
    {
        $query = http_build_query($step['query']);

        return $query === '' ? $step['path'] : $step['path'].(str_contains($step['path'], '?') ? '&' : '?').$query;
    }

    private function finish(Kernel $kernel, Request $request, Response $response, ?int $expect): void
    {
        $kernel->terminate($request, $response);

        $status = $response->getStatusCode();
        if ($expect === null ? $status < 400 : $status === $expect) {
            return;
        }

        $wanted = $expect === null ? 'a status below 400' : "status {$expect}";
        $body = mb_substr(trim((string) $response->getContent()), 0, 200);

        throw new \RuntimeException("{$request->method()} {$request->getPathInfo()} answered {$status}, expected {$wanted}. Body: {$body}");
    }

    /**
     * Swaps in Laravel's fakes so a step cannot queue a job, send mail or call another service for real; the rollback
     * only covers the database. Events stay real on purpose: faking them would also stop model observers.
     *
     * @param  list<string>  $fakes
     */
    private function fake(array $fakes): void
    {
        foreach ($fakes as $fake) {
            match ($fake) {
                'queue' => Queue::fake(),
                'bus' => Bus::fake(),
                'mail' => Mail::fake(),
                'notification' => Notification::fake(),
                'http' => Http::fake(),
                'storage' => $this->fakeStorage(),
            };
        }
    }

    /**
     * Every disk that is not a local one (S3, FTP, SFTP...) becomes a local folder under storage/framework/testing, so
     * nothing reaches a remote bucket. Local disks stay as they are: code that builds a path from storage_path('app')
     * would not find its files under another root.
     */
    private function fakeStorage(): void
    {
        foreach ((array) config('filesystems.disks', []) as $name => $disk) {
            if (($disk['driver'] ?? null) !== 'local') {
                Storage::fake((string) $name);
            }
        }
    }

    /** A step starts signed out: the sign-in and session of the previous step must not carry over. */
    private function resetIdentity(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse((string) $this->app['config']->get('auth.defaults.guard', 'web'));

        if ($this->app->bound('session')) {
            // a saved session is no longer "started", but its attributes stay and would be merged into the next step's
            $this->app['session']->flush();
        }
    }

    private function signIn(?string $surface, ?string $user): void
    {
        if ($surface === null) {
            return;
        }

        $this->app->make(Access::class)->actingAs($surface, $user);
    }

    /** @param  array<string, mixed>  $values */
    private function putSession(array $values): void
    {
        if ($values === [] || ! $this->app->bound('session')) {
            return;
        }

        foreach ($values as $key => $value) {
            $this->app['session']->put((string) $key, $value);
        }
    }
}

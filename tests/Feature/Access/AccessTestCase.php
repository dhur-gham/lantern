<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use Lantern\Access\ProvidesCredentials;
use Lantern\Agent\Commands;
use Lantern\LanternServiceProvider;
use Orchestra\Testbench\TestCase;

class AccessUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];
}

final class PartnerCredentials implements ProvidesCredentials
{
    /** @var list<array<string, mixed>> */
    public static array $revoked = [];

    public function issue(array $subject): array
    {
        return ['headers' => ['X-API-KEY' => 'key-'.($subject['merchant'] ?? 0), 'X-API-SECRET' => 's3cret']];
    }

    public function revoke(array $issued): void
    {
        self::$revoked[] = $issued;
    }
}

abstract class AccessTestCase extends TestCase
{
    protected string $environment = 'local';

    protected function getPackageProviders($app): array
    {
        return [SanctumServiceProvider::class, LanternServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->detectEnvironment(fn () => $this->environment);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'http://myapp.test');
        $app['config']->set('lantern.enabled', true);
        $app['config']->set('lantern.kill_switch', false);
        $app['config']->set('lantern.storage.driver', 'database');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', AccessUser::class);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('lantern.access.surfaces', [
            'panel' => ['type' => 'session', 'guard' => 'web', 'user' => 1, 'session' => ['current_merchant_id' => 222], 'home' => '/dashboard'],
            'api' => ['type' => 'sanctum', 'user' => 1, 'abilities' => ['*'], 'ttl_minutes' => 30, 'headers' => ['mr-id' => '222']],
            'partner' => ['type' => 'custom', 'credentials' => PartnerCredentials::class, 'subject' => ['merchant' => 222]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Artisan::call('migrate', ['--path' => dirname(__DIR__, 3).'/database/migrations', '--realpath' => true, '--force' => true]);
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        foreach (Commands::all() as $class) {
            $kernel->registerCommand($this->app->make($class));
        }
        AccessUser::query()->create(['name' => 'Dev', 'email' => 'dev@example.test', 'password' => 'x']);
        PartnerCredentials::$revoked = [];
    }

    /** @param array<string, mixed> $params @return array{0: int, 1: array<string, mixed>} */
    protected function login(array $params = []): array
    {
        $code = Artisan::call('lantern:login', $params + ['--format' => 'json']);
        $data = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($data);

        return [$code, $data];
    }

    protected function tokenFrom(string $url): string
    {
        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}

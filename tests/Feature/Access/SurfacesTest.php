<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Lantern\Access\ProvidesUser;
use Lantern\Facades\Lantern;

final class GuardedCredentials implements \Lantern\Access\ProvidesCredentials, ProvidesUser
{
    public function issue(array $subject): array
    {
        return ['headers' => ['X-API-KEY' => 'k']];
    }

    public function revoke(array $issued): void
    {
    }

    public function user(array $subject): Authenticatable
    {
        return AccessUser::query()->findOrFail(1);
    }
}

final class SurfacesTest extends AccessTestCase
{
    public function test_a_sanctum_surface_issues_a_working_short_lived_token_and_revoke_deletes_it(): void
    {
        Route::middleware('auth:sanctum')->get('/api/me', fn () => ['id' => auth()->id()]);

        [$code, $data] = $this->login(['surface' => 'api']);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith('Bearer ', $data['headers']['Authorization']);
        $this->assertSame('222', $data['headers']['mr-id']);
        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->assertNotNull(PersonalAccessToken::query()->first()->expires_at);

        $this->withHeaders($data['headers'])->getJson('/api/me')->assertOk()->assertJson(['id' => 1]);

        $this->login(['surface' => 'api', '--revoke' => true]);
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_an_expired_sanctum_token_is_refused(): void
    {
        Route::middleware('auth:sanctum')->get('/api/me', fn () => ['id' => auth()->id()]);
        config(['sanctum.expiration' => null]);
        [, $data] = $this->login(['surface' => 'api']);

        $this->travel(31)->minutes();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($data['headers'])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_a_custom_surface_asks_the_app_for_credentials_and_revoke_calls_it_back(): void
    {
        [$code, $data] = $this->login(['surface' => 'partner']);

        $this->assertSame(0, $code);
        $this->assertSame('custom', $data['type']);
        $this->assertSame(['X-API-KEY' => 'key-222', 'X-API-SECRET' => 's3cret'], $data['headers']);

        $this->login(['surface' => 'partner', '--revoke' => true]);

        $this->assertCount(1, PartnerCredentials::$revoked);
        $this->assertSame('key-222', PartnerCredentials::$revoked[0]['headers']['X-API-KEY']);
    }

    public function test_a_surface_of_another_type_is_refused_by_name(): void
    {
        config(['lantern.access.surfaces.broken' => ['type' => 'custom', 'credentials' => 'Nope\\Missing']]);

        [$code, $data] = $this->login(['surface' => 'broken']);

        $this->assertSame(2, $code);
        $this->assertSame('config_error', $data['error']['code']);

        [, $list] = $this->login(['--list' => true]);
        $broken = collect($list['surfaces'])->firstWhere('name', 'broken');
        $this->assertFalse($broken['usable']);
    }

    public function test_acting_as_signs_the_user_in_for_code_in_this_process(): void
    {
        $user = Lantern::actingAs('panel');

        $this->assertSame(1, $user->getAuthIdentifier());
        $this->assertAuthenticatedAs($user);
        $this->assertSame(222, session('current_merchant_id'));
    }

    public function test_acting_as_works_for_a_custom_guard_whose_credentials_provide_a_user(): void
    {
        config(['lantern.access.surfaces.guarded' => ['type' => 'custom', 'guard' => 'web', 'credentials' => GuardedCredentials::class]]);

        $user = Lantern::actingAs('guarded');

        $this->assertSame(1, $user->getAuthIdentifier());
        $this->assertAuthenticatedAs($user);
    }

    public function test_init_proposes_surfaces_from_the_auth_config_and_writes_them_on_request(): void
    {
        $file = config_path('lantern-access.php');
        @unlink($file);

        Artisan::call('lantern:init', ['--pretty' => true]);
        $data = json_decode(Artisan::output(), true);

        $this->assertSame('lantern.init', $data['schema']);
        $this->assertSame('session', $data['surfaces']['panel']['type']);
        $this->assertSame(1, $data['surfaces']['panel']['user']);
        $this->assertSame('sanctum', $data['surfaces']['api']['type']);
        $this->assertFalse($data['written']);
        $this->assertFileDoesNotExist($file);

        Artisan::call('lantern:init', ['--write' => true]);
        $written = json_decode(Artisan::output(), true);
        $this->assertTrue($written['written']);
        $this->assertFileExists($file);
        $this->assertSame('panel', array_key_first(require $file));

        Artisan::call('lantern:init', ['--write' => true]);
        $again = json_decode(Artisan::output(), true);
        $this->assertFalse($again['written']);

        @unlink($file);
    }

    public function test_status_warns_when_the_access_cache_store_is_array(): void
    {
        Artisan::call('lantern:status', ['--json' => true]);
        $data = json_decode(Artisan::output(), true);

        $this->assertTrue($data['access']['available']);
        $this->assertSame(3, $data['access']['surfaces']);
        $this->assertSame('ArrayStore', $data['access']['cache_store']);
        $this->assertStringContainsString('array', $data['access']['warnings'][0]);
    }
}

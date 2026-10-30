<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Access;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Trace\Recorder;

final class LoginLinkTest extends AccessTestCase
{
    public function test_the_link_signs_the_user_in_sets_app_session_state_and_redirects(): void
    {
        [$code, $data] = $this->login(['surface' => 'panel', '--to' => '/dashboard/items/1/edit']);

        $this->assertSame(0, $code);
        $this->assertSame('lantern.login', $data['schema']);
        $this->assertSame('session', $data['type']);
        $this->assertStringStartsWith('http://myapp.test/_lantern/access/', $data['url']);
        $this->assertSame('dev@example.test', $data['user']['label']);

        $this->get($data['url'])->assertRedirect('/dashboard/items/1/edit');

        $this->assertAuthenticatedAs(AccessUser::find(1));
        $this->assertSame(222, session('current_merchant_id'));
    }

    public function test_the_session_cookie_from_the_link_passes_auth_on_the_next_request(): void
    {
        Route::middleware(['web', 'auth'])->get('/whoami', fn () => ['id' => auth()->id(), 'merchant' => session('current_merchant_id')]);
        [, $data] = $this->login(['surface' => 'panel']);

        $response = $this->get($data['url']);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);

        $this->app['auth']->forgetGuards();
        $this->withCookie($cookie->getName(), $cookie->getValue())->getJson('/whoami')->assertOk()->assertJson(['id' => 1, 'merchant' => 222]);
    }

    public function test_a_link_works_once(): void
    {
        [, $data] = $this->login(['surface' => 'panel']);

        $this->get($data['url'])->assertRedirect('/dashboard');
        $this->get($data['url'])->assertNotFound();
    }

    public function test_a_link_expires_after_five_minutes(): void
    {
        [, $data] = $this->login(['surface' => 'panel']);

        $this->travel(6)->minutes();

        $this->get($data['url'])->assertNotFound();
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/_lantern/access/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/_lantern/access/not-a-token')->assertNotFound();
    }

    public function test_requests_through_a_proxy_or_tunnel_are_refused_and_do_not_use_up_the_link(): void
    {
        [, $data] = $this->login(['surface' => 'panel']);

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.9'])->get($data['url'])->assertForbidden();
        $this->withHeaders(['X-Forwarded-Host' => 'abc.ngrok.io'])->get($data['url'])->assertForbidden();

        $this->flushHeaders();
        $this->get($data['url'])->assertRedirect('/dashboard');
    }

    public function test_absolute_and_protocol_relative_targets_are_refused(): void
    {
        foreach (['https://evil.test/', '//evil.test/x', '/ok\\evil', 'javascript:alert(1)'] as $to) {
            [$code, $data] = $this->login(['surface' => 'panel', '--to' => $to]);
            $this->assertSame(2, $code, $to);
            $this->assertSame('usage_error', $data['error']['code']);
        }
    }

    public function test_the_login_event_is_not_fired_by_default_but_can_be(): void
    {
        Event::fake([Login::class]);
        [, $data] = $this->login(['surface' => 'panel']);
        $this->get($data['url']);
        Event::assertNotDispatched(Login::class);

        config(['lantern.access.fire_login_event' => true]);
        [, $data] = $this->login(['surface' => 'panel']);
        $this->get($data['url']);
        Event::assertDispatched(Login::class);
    }

    public function test_a_user_can_be_chosen_by_email_or_id(): void
    {
        AccessUser::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);

        [, $byEmail] = $this->login(['surface' => 'panel', '--user' => 'other@example.test']);
        [, $byId] = $this->login(['surface' => 'panel', '--user' => '2']);
        [$code, $missing] = $this->login(['surface' => 'panel', '--user' => '99']);

        $this->assertSame(2, $byEmail['user']['id']);
        $this->assertSame(2, $byId['user']['id']);
        $this->assertSame(2, $code);
        $this->assertSame('config_error', $missing['error']['code']);
    }

    public function test_an_app_url_without_a_host_fails_loudly(): void
    {
        config(['app.url' => 'localhost-no-scheme']);

        [$code, $data] = $this->login(['surface' => 'panel']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('APP_URL', $data['error']['message']);
    }

    public function test_an_app_url_host_that_differs_from_the_last_request_host_fails_loudly(): void
    {
        $this->storeRequestTrace('other.test');

        [$code, $data] = $this->login(['surface' => 'panel']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('other.test', $data['error']['message']);
    }

    public function test_a_matching_host_passes_and_no_history_only_warns(): void
    {
        [$code, $data] = $this->login(['surface' => 'panel']);
        $this->assertSame(0, $code);
        $this->assertFalse($data['host_verified']);
        $this->assertStringContainsString('could not be checked', $data['hint']);

        $this->storeRequestTrace('myapp.test');

        [$code, $data] = $this->login(['surface' => 'panel']);
        $this->assertSame(0, $code);
        $this->assertTrue($data['host_verified']);
        $this->assertArrayNotHasKey('hint', $data);
    }

    public function test_a_request_made_in_a_console_process_does_not_count_for_the_host_check(): void
    {
        config(['app.url' => 'http://app.test']);
        Route::get('/probe', fn () => 'ok');

        // through the HTTP kernel with the host "localhost", like tinker, Livewire::test or lantern:run would
        $this->get('http://localhost/probe')->assertOk();

        $stored = \Illuminate\Support\Facades\DB::table('lantern_traces')->where('path', '/probe')->first();
        $this->assertNotNull($stored, 'the request should have been traced');
        $this->assertArrayNotHasKey('host', \Lantern\Storage\PayloadCodec::decode((string) $stored->payload)['meta'] ?? []);

        [$code, $data] = $this->login(['surface' => 'panel']);

        $this->assertSame(0, $code, json_encode($data));
        $this->assertFalse($data['host_verified']);
    }

    public function test_list_shows_surfaces_and_what_is_outstanding_and_revoke_clears_it(): void
    {
        $this->login(['surface' => 'panel']);

        [$code, $list] = $this->login(['--list' => true]);
        $this->assertSame(0, $code);
        $this->assertSame(['panel', 'api', 'partner'], array_column($list['surfaces'], 'name'));
        $this->assertTrue($list['surfaces'][0]['usable']);
        $this->assertCount(1, $list['issued']);

        [$code, $revoked] = $this->login(['--revoke' => true, '--all' => true]);
        $this->assertSame(0, $code);
        $this->assertSame(1, $revoked['revoked']);
        [, $list] = $this->login(['--list' => true]);
        $this->assertSame([], $list['issued']);
    }

    public function test_a_revoked_link_no_longer_works(): void
    {
        [, $data] = $this->login(['surface' => 'panel']);
        $this->login(['surface' => 'panel', '--revoke' => true]);

        $this->get($data['url'])->assertNotFound();
    }

    public function test_an_unknown_surface_is_a_usage_error(): void
    {
        [$code, $data] = $this->login(['surface' => 'nope']);

        $this->assertSame(2, $code);
        $this->assertSame('usage_error', $data['error']['code']);
    }

    private function storeRequestTrace(string $host): void
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200, 'host' => $host]);
        $recorder->end();
        (new DatabaseTraceStore($this->app['db']))->store($trace, []);
    }
}

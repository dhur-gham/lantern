<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Access;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/** The route and the command exist only with APP_ENV=local, so the testing environment already proves the guard. */
final class AccessUnavailableTest extends AccessTestCase
{
    protected string $environment = 'testing';

    public function test_the_route_is_not_registered_outside_the_local_environment(): void
    {
        $this->assertFalse(Route::has('lantern.access'));
        $this->get('/_lantern/access/'.str_repeat('a', 64))->assertNotFound();
    }

    public function test_login_and_acting_as_refuse_outside_the_local_environment(): void
    {
        [$code, $data] = $this->login(['surface' => 'panel']);

        $this->assertSame(2, $code);
        $this->assertSame('config_error', $data['error']['code']);
        $this->expectException(\Lantern\Agent\AgentException::class);
        \Lantern\Facades\Lantern::actingAs('panel');
    }
}

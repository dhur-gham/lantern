<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Access;

use Illuminate\Support\Facades\Route;

final class AccessProductionTest extends AccessTestCase
{
    protected string $environment = 'production';

    public function test_nothing_is_registered_and_the_command_exits_two_in_production(): void
    {
        $this->assertFalse(Route::has('lantern.access'));

        [$code, $data] = $this->login(['surface' => 'panel']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('APP_ENV=local', $data['error']['message']);
    }

    public function test_availability_is_false_in_production(): void
    {
        $this->assertFalse(\Lantern\Access\Access::available($this->app));
    }
}

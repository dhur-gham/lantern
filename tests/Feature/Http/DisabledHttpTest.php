<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Http;

use Lantern\Tests\TestCase;

final class DisabledHttpTest extends TestCase
{
    public function test_routes_not_registered_when_disabled(): void
    {
        $this->getJson('/lantern/api/traces')->assertNotFound();
    }
}

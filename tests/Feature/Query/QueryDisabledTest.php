<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Lantern\Instrumentation\Query\QueryInstrumentation;
use ReflectionProperty;

require_once __DIR__.'/QueryTestCase.php';

final class QueryDisabledTest extends QueryTestCase
{
    protected bool $lanternEnabled = false;

    public function test_disabled_registers_no_hooks(): void
    {
        $this->assertFalse($this->app->bound(QueryInstrumentation::class));
        $this->assertFalse($this->app['events']->hasListeners(QueryExecuted::class));
        $this->assertFalse($this->app['events']->hasListeners(ConnectionEstablished::class));

        $connection = DB::connection();
        $callbacks = (new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks'))->getValue($connection);
        $this->assertSame([], $callbacks);
    }
}

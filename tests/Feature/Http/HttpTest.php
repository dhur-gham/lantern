<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Http;

use Lantern\Detection\Issue;
use Lantern\Lantern;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Storage\TraceQueries;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\Trace;

final class HttpTest extends TestCase
{
    private const MIGRATIONS = ['2024_01_01_000001_create_lantern_traces_table', '2024_01_01_000002_create_lantern_issues_table'];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('lantern.enabled', true);
        $app['env'] = 'local';
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::MIGRATIONS as $file) {
            (require __DIR__.'/../../../database/migrations/'.$file.'.php')->up();
        }
        $this->app['view']->getFinder()->prependNamespace('lantern', [__DIR__.'/views']);
    }

    protected function tearDown(): void
    {
        Lantern::flush();
        parent::tearDown();
    }

    private function seedTrace(string $path, int $status, array $issues = [], bool $compress = false): Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => $path, 'status' => $status, 'issues' => array_map(fn (Issue $i) => $i->toArray(), $issues)]);
        $recorder->end();
        (new DatabaseTraceStore($this->app['db'], null, $compress))->store($trace, $issues);
        usleep(3000);

        return $trace;
    }

    private function issue(string $key, string $type, string $sev, float $ms, int $count = 5): Issue
    {
        return new Issue($type, $key, 'Issue '.$key, $sev, $count, $ms, ['file' => 'app/A.php', 'line' => 7], [1, 2]);
    }

    public function test_api_lists_traces_with_filters(): void
    {
        $a = $this->seedTrace('/posts', 200);
        $b = $this->seedTrace('/boom', 500, [$this->issue('k1', Issue::TYPE_N_PLUS_ONE, Issue::SEV_WARNING, 10.0)]);

        $ids = collect($this->getJson('/lantern/api/traces')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $ids);

        $this->assertCount(1, $this->getJson('/lantern/api/traces?status=500')->json('data'));
        $this->assertCount(1, $this->getJson('/lantern/api/traces?status=5xx')->json('data'));
        $this->assertSame([$b->id], collect($this->getJson('/lantern/api/traces?has_issues=1')->json('data'))->pluck('id')->all());
        $this->assertSame([$a->id], collect($this->getJson('/lantern/api/traces?path=post')->json('data'))->pluck('id')->all());
        $this->assertCount(0, $this->getJson('/lantern/api/traces?min_ms=999999')->json('data'));
        $this->assertCount(1, $this->getJson('/lantern/api/traces?limit=1')->json('data'));
    }

    public function test_trace_detail_decodes_gzip_payload_and_issues(): void
    {
        $t = $this->seedTrace('/x', 200, [$this->issue('k1', Issue::TYPE_N_PLUS_ONE, Issue::SEV_WARNING, 10.0)], true);

        $data = $this->getJson('/lantern/api/traces/'.$t->id)->assertOk()->json('data');
        $this->assertSame($t->id, $data['payload']['id']);
        $this->assertSame('k1', $data['issues'][0]['key'] ?? $data['issues'][0]['issue_key'] ?? null);
        $this->getJson('/lantern/api/traces/missing')->assertNotFound();
    }

    public function test_issues_filter_and_sort(): void
    {
        $this->seedTrace('/a', 200, [
            $this->issue('k1', Issue::TYPE_N_PLUS_ONE, Issue::SEV_WARNING, 10.0),
            $this->issue('k2', Issue::TYPE_SLOW, Issue::SEV_CRITICAL, 90.0),
        ]);
        $this->seedTrace('/b', 200, [$this->issue('k1', Issue::TYPE_N_PLUS_ONE, Issue::SEV_WARNING, 10.0)]);

        $keys = fn (string $qs) => collect($this->getJson('/lantern/api/issues'.$qs)->assertOk()->json('data'))->pluck('issue_key')->all();
        $this->assertSame(['k2', 'k1'], $keys(''));
        $this->assertSame(['k1', 'k2'], $keys('?sort=occurrences'));
        $this->assertSame(['k2'], $keys('?severity='.Issue::SEV_CRITICAL));
        $this->assertSame(['k1'], $keys('?type='.Issue::TYPE_N_PLUS_ONE));

        $one = $this->getJson('/lantern/api/issues/k1')->assertOk()->json('data');
        $this->assertSame(7, $one['call_site']['line']);
        $this->getJson('/lantern/api/issues/nope')->assertNotFound();
    }

    public function test_pages_render_named_views(): void
    {
        $t = $this->seedTrace('/x', 200);

        $this->get(route('lantern.traces'))->assertOk()->assertSee('view traces');
        $this->get(route('lantern.trace', $t->id))->assertOk()->assertSee('view trace');
        $this->get(route('lantern.issues'))->assertOk()->assertSee('view issues');
        $this->get(route('lantern.issue', 'k1'))->assertOk()->assertSee('view issue');
    }

    public function test_forbidden_outside_local_and_custom_gate(): void
    {
        $this->app['env'] = 'production';
        $this->getJson('/lantern/api/traces')->assertForbidden();

        Lantern::auth(fn () => true);
        $this->getJson('/lantern/api/traces')->assertOk();

        Lantern::auth(fn () => false);
        $this->getJson('/lantern/api/traces')->assertForbidden();
    }

    public function test_own_path_is_ignored_for_tracing(): void
    {
        $ignored = (array) $this->app['config']->get('lantern.ignore_paths');
        $this->assertContains('lantern/*', $ignored);
        $this->assertInstanceOf(TraceQueries::class, $this->app->make(TraceQueries::class));
    }
}

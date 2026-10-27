<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Http;

use Carbon\Carbon;
use Lantern\Lantern;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Storage\TraceQueries;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;

final class RealPagesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('lantern.enabled', true);
        $app['config']->set('view.compiled', sys_get_temp_dir());
        $app['env'] = 'local';
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2024_01_01_000001_create_lantern_traces_table', '2024_01_01_000002_create_lantern_issues_table'] as $file) {
            (require __DIR__.'/../../../database/migrations/'.$file.'.php')->up();
        }
    }

    protected function tearDown(): void
    {
        Lantern::flush();
        parent::tearDown();
    }

    private function seedTrace(): string
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200]);
        $child = $recorder->start(\Lantern\Trace\SpanType::View, 'child');
        $recorder->stop($child);
        $recorder->end();
        (new DatabaseTraceStore($this->app['db'], null, false))->store($trace, []);

        return $trace->id;
    }

    public function test_css_asset_is_non_empty(): void
    {
        $this->assertNotSame('', Lantern::asset('lantern.css'));
    }

    public function test_real_layout_inlines_css_and_sets_data_base(): void
    {
        $id = $this->seedTrace();
        $css = Lantern::asset('lantern.css');

        foreach ([route('lantern.traces'), route('lantern.trace', $id), route('lantern.issues')] as $url) {
            $response = $this->get($url)->assertOk();
            $html = (string) $response->getContent();
            $this->assertStringContainsString('data-base="/lantern"', $html);
            $this->assertStringContainsString(substr($css, 0, 40), $html);
            $this->assertStringNotContainsString('file_get_contents', $html);
        }
    }

    public function test_trace_queries_iso_dates_and_tree(): void
    {
        $id = $this->seedTrace();
        $queries = $this->app->make(TraceQueries::class);

        $list = $queries->traces();
        $this->assertNotEmpty($list);
        $this->assertNotFalse(Carbon::hasFormat($list[0]['started_at'], 'Y-m-d\TH:i:sP'));
        $this->assertMatchesRegularExpression('/(Z|[+-]\d{2}:\d{2})$/', $list[0]['started_at']);

        $row = $queries->trace($id);
        $this->assertArrayHasKey('tree', $row);
        $this->assertSame($row['payload']['root'], $row['tree']);
        $this->assertArrayHasKey('start_ms', $row['tree']['children'][0] ?? $row['tree']);
        $this->assertMatchesRegularExpression('/(Z|[+-]\d{2}:\d{2})$/', $row['started_at']);
    }
}


<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Scenario;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Lantern\Tests\Feature\Agent\AgentTestCase;

final class ScenarioCommandTest extends AgentTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lantern-scn-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['lantern.scenarios.path' => $this->dir]);

        Schema::create('s_items', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });

        Route::get('/s/ping', fn () => response()->json(['ok' => true]));
        Route::post('/s/write', function () {
            DB::table('s_items')->insert(['name' => 'w']);

            return response()->json(['ok' => true]);
        });
        Route::post('/s/ddl', function () {
            DB::statement('create table s_tmp (id integer)');
            DB::table('s_items')->insert(['name' => 'd']);

            return response()->json(['ok' => true]);
        });
        Route::get('/s/missing', fn () => response('nope', 404));
        Route::get('/s/limited', fn () => response()->json(['ok' => true]))->middleware('throttle:1,1');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $data */
    private function scenario(string $name, array $data): string
    {
        $file = $this->dir.DIRECTORY_SEPARATOR.$name.'.json';
        file_put_contents($file, json_encode($data));

        return $file;
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runScenario(string $name, array $opts = []): array
    {
        [$code, $out] = $this->run_('lantern:scenario', ['action' => 'run', 'file' => $name] + $opts);
        $data = json_decode($out, true);
        $this->assertIsArray($data, $out);

        return [$code, $data];
    }

    public function test_list_reports_valid_and_invalid_files(): void
    {
        $this->scenario('good', ['steps' => [['request' => 'GET', 'path' => '/s/ping']]]);
        $this->scenario('bad', ['stepz' => []]);

        [$code, $out] = $this->run_('lantern:scenario', ['action' => 'list']);
        $data = json_decode($out, true);

        $this->assertSame(0, $code, $out);
        $this->assertSame('lantern.scenarios', $data['schema']);
        $rows = array_column($data['scenarios'], null, 'name');
        $this->assertTrue($rows['good']['valid']);
        $this->assertSame(1, $rows['good']['steps']);
        $this->assertFalse($rows['bad']['valid']);
        $this->assertArrayHasKey('error', $rows['bad']);
    }

    public function test_list_with_no_files_suggests_creating_one(): void
    {
        [$code, $out] = $this->run_('lantern:scenario', ['action' => 'list']);
        $data = json_decode($out, true);

        $this->assertSame(0, $code);
        $this->assertSame([], $data['scenarios']);
        $this->assertStringContainsString('Create', $data['next']);
    }

    public function test_runs_an_http_request_scenario(): void
    {
        $this->scenario('ping', ['steps' => [['request' => 'GET', 'path' => '/s/ping', 'expect' => 200]]]);

        [$code, $data] = $this->runScenario('ping');

        $this->assertSame(0, $code, json_encode($data));
        $this->assertSame('lantern.run', $data['schema']);
        $this->assertSame('ping', $data['scenario']['name']);
        $this->assertSame(1, $data['scenario']['steps']);
        $this->assertTrue($data['scenario']['throttle']);
        $this->assertCount(1, $data['traces']);
        $this->assertStringStartsWith('ping:01:', $data['traces'][0]['name']);
    }

    public function test_writes_are_rolled_back_and_checked(): void
    {
        $this->scenario('w', ['fake' => ['queue', 'mail', 'notification', 'http'], 'steps' => [['request' => 'POST', 'path' => '/s/write']]]);

        [$code, $data] = $this->runScenario('w');

        $this->assertSame(0, $code, json_encode($data));
        $this->assertSame(0, DB::table('s_items')->count());
        $this->assertTrue($data['scenario']['rollback_check']['ok']);
        $this->assertSame(['queue', 'mail', 'notification', 'http'], $data['scenario']['faked']);
        $this->assertArrayNotHasKey('warnings', $data['scenario']);
    }

    public function test_warns_about_unfaked_channels_on_write(): void
    {
        $this->scenario('w', ['steps' => [['request' => 'POST', 'path' => '/s/write']]]);

        [, $data] = $this->runScenario('w');

        $this->assertStringContainsString('without faking queue, mail, notification, http', $data['scenario']['warnings'][0]);
    }

    public function test_commit_keeps_writes_and_skips_the_check(): void
    {
        $this->scenario('w', ['steps' => [['request' => 'POST', 'path' => '/s/write']]]);

        [$code, $data] = $this->runScenario('w', ['--commit' => true]);

        $this->assertSame(0, $code, json_encode($data));
        $this->assertSame(1, DB::table('s_items')->count());
        $this->assertNull($data['scenario']['rollback_check']);
        $this->assertFalse($data['rolled_back']);
    }

    public function test_ddl_fails_the_run(): void
    {
        $this->scenario('ddl', ['steps' => [['request' => 'POST', 'path' => '/s/ddl']]]);

        [$code, $data] = $this->runScenario('ddl');

        $this->assertSame(1, $code, json_encode($data));
        $this->assertSame('failed', $data['status']);
        $this->assertFalse($data['scenario']['rollback_check']['ok']);
    }

    public function test_fake_list_merges_config_default(): void
    {
        config(['lantern.scenarios.fake' => ['storage']]);
        $this->scenario('f', ['fake' => ['mail', 'storage'], 'steps' => [['request' => 'GET', 'path' => '/s/ping']]]);

        [, $data] = $this->runScenario('f');

        $this->assertSame(['storage', 'mail'], $data['scenario']['faked']);
    }

    public function test_invalid_fake_is_a_usage_error(): void
    {
        $this->scenario('f', ['fake' => [[]], 'steps' => [['request' => 'GET', 'path' => '/s/ping']]]);

        [$code] = $this->run_('lantern:scenario', ['action' => 'run', 'file' => 'f']);

        $this->assertSame(2, $code);
    }

    public function test_throttle_false_bypasses_the_rate_limiter(): void
    {
        $steps = array_fill(0, 3, ['request' => 'GET', 'path' => '/s/limited']);

        $this->scenario('on', ['steps' => $steps]);
        [$code, $data] = $this->runScenario('on');
        $this->assertSame(1, $code, json_encode($data));

        $this->app['cache']->flush();
        $this->scenario('off', ['throttle' => false, 'steps' => $steps]);
        [$code, $data] = $this->runScenario('off');

        $this->assertSame(0, $code, json_encode($data));
        $this->assertFalse($data['scenario']['throttle']);
        $this->assertGreaterThanOrEqual(1, $data['scenario']['throttle_skipped']);
    }

    public function test_error_status_fails_unless_expected(): void
    {
        $this->scenario('nf', ['steps' => [['request' => 'GET', 'path' => '/s/missing']]]);
        [$code] = $this->runScenario('nf');
        $this->assertSame(1, $code);

        $this->scenario('nf2', ['steps' => [['request' => 'GET', 'path' => '/s/missing', 'expect' => 404]]]);
        [$code, $data] = $this->runScenario('nf2');
        $this->assertSame(0, $code, json_encode($data));
    }

    public function test_config_error_exit_codes(): void
    {
        $this->scenario('ok', ['steps' => [['request' => 'GET', 'path' => '/s/ping']]]);

        $this->app['env'] = 'production';
        [$code, $out] = $this->run_('lantern:scenario', ['action' => 'run', 'file' => 'ok']);
        $this->assertSame(2, $code, $out);
        $this->assertStringContainsString('APP_ENV=local', $out);
        $this->app['env'] = 'local';

        $this->assertSame(2, $this->run_('lantern:scenario', ['action' => 'run', 'file' => 'missing'])[0]);
        $this->assertSame(2, $this->run_('lantern:scenario', ['action' => 'run'])[0]);
        $this->assertSame(2, $this->run_('lantern:scenario', ['action' => 'nope'])[0]);

        file_put_contents($this->dir.'/broken.json', '{not json');
        $this->assertSame(2, $this->run_('lantern:scenario', ['action' => 'run', 'file' => 'broken'])[0]);
    }
}

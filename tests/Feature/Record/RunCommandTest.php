<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Record;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Tests\Feature\Agent\AgentTestCase;

final class RunCommandTest extends AgentTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        Schema::create('r_items', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function script(string $body): string
    {
        $file = tempnam(sys_get_temp_dir(), 'lantern-probe-').'.php';
        file_put_contents($file, "<?php\nuse Lantern\Facades\Lantern;\n".$body);

        return $this->files[] = $file;
    }

    /** @return array<string, mixed> */
    private function decode(string $output): array
    {
        $data = json_decode($output, true);
        $this->assertIsArray($data, $output);

        return $data;
    }

    private function loopScript(): string
    {
        return $this->script(<<<'PHP'
DB::table('r_items')->insert(['name' => $args['name'] ?? 'x']);
Lantern::record('loop', function () {
    foreach (range(1, 8) as $id) {
        DB::select('select * from r_items where id = ?', [$id]);
    }
});
PHP
        );
    }

    public function test_turns_lantern_on_for_the_process_and_stores_each_record_block(): void
    {
        $this->assertFalse((bool) config('lantern.enabled'));

        [$code, $out] = $this->run_('lantern:run', ['script' => $this->loopScript(), '--arg' => ['name=abc']]);
        $data = $this->decode($out);

        $this->assertSame(0, $code, $out);
        $this->assertSame('lantern.run', $data['schema']);
        $this->assertSame(['name' => 'abc'], $data['args']);
        $this->assertCount(1, $data['traces']);
        $this->assertSame('loop', $data['traces'][0]['name']);
        $this->assertSame(8, $data['traces'][0]['query_count']);
        $this->assertGreaterThanOrEqual(1, $data['traces'][0]['issue_count']);
        $this->assertSame(1, DB::table('lantern_traces')->where('route', 'record:loop')->count());
        $this->assertStringContainsString('lantern:issues', $data['next']);
    }

    public function test_rolls_back_script_writes_by_default_and_commit_keeps_them(): void
    {
        [, $out] = $this->run_('lantern:run', ['script' => $this->loopScript()]);
        $this->assertTrue($this->decode($out)['rolled_back']);
        $this->assertSame(0, DB::table('r_items')->count());
        $this->assertSame(1, DB::table('lantern_traces')->count());

        [, $out] = $this->run_('lantern:run', ['script' => $this->loopScript(), '--commit' => true]);
        $this->assertFalse($this->decode($out)['rolled_back']);
        $this->assertSame(1, DB::table('r_items')->count());
    }

    private function otherConnection(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'lantern-other-');
        $this->files[] = $file;
        config(['database.connections.other' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
        Schema::connection('other')->create('o_items', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });
        // the script has to open it again while it runs
        DB::purge('other');

        return $file;
    }

    public function test_rolls_back_every_connection_the_script_touches_including_ones_opened_mid_run(): void
    {
        $this->otherConnection();
        $script = $this->script(<<<'PHP'
DB::table('r_items')->insert(['name' => 'default']);
DB::connection('other')->table('o_items')->insert(['name' => 'other']);
Lantern::record('both', fn () => DB::connection('other')->table('o_items')->count());
PHP
        );

        [$code, $out] = $this->run_('lantern:run', ['script' => $script]);
        $data = $this->decode($out);

        $this->assertSame(0, $code, $out);
        $this->assertTrue($data['rolled_back']);
        $this->assertSame(['testing' => true, 'other' => true], $data['connections']);
        $this->assertSame(0, DB::table('r_items')->count());
        $this->assertSame(0, DB::connection('other')->table('o_items')->count());

        [, $out] = $this->run_('lantern:run', ['script' => $script, '--commit' => true]);
        $data = $this->decode($out);
        $this->assertFalse($data['rolled_back']);
        $this->assertSame(1, DB::connection('other')->table('o_items')->count());
    }

    public function test_requests_outside_record_blocks_are_not_stored(): void
    {
        $script = $this->script(<<<'PHP'
app('router')->get('/outside', fn () => DB::select('select 1'));
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/outside');
$kernel->terminate($request, $kernel->handle($request));
Lantern::record('inside', fn () => DB::select('select 2'));
PHP
        );

        [$code, $out] = $this->run_('lantern:run', ['script' => $script]);

        $this->assertSame(0, $code, $out);
        $this->assertSame(['record:inside'], DB::table('lantern_traces')->pluck('route')->all());
    }

    public function test_repeat_reports_a_median_per_record_name(): void
    {
        [$code, $out] = $this->run_('lantern:run', ['script' => $this->loopScript(), '--repeat' => '3']);
        $data = $this->decode($out);

        $this->assertSame(0, $code);
        $this->assertCount(3, $data['traces']);
        $this->assertSame('loop', $data['summary'][0]['name']);
        $this->assertSame(3, $data['summary'][0]['runs']);
        $this->assertEquals(8, $data['summary'][0]['median_queries']);
    }

    public function test_a_throwing_script_exits_one_with_the_error(): void
    {
        $script = $this->script("Lantern::record('ok', fn () => 1);\nthrow new RuntimeException('probe broke');");
        [$code, $out] = $this->run_('lantern:run', ['script' => $script]);
        $data = $this->decode($out);

        $this->assertSame(1, $code);
        $this->assertSame('failed', $data['status']);
        $this->assertSame('probe broke', $data['error']['message']);
        $this->assertCount(1, $data['traces']);
    }

    public function test_refuses_outside_the_local_environment(): void
    {
        $this->app['env'] = 'production';

        [$code, $out] = $this->run_('lantern:run', ['script' => $this->loopScript()]);

        $this->assertSame(2, $code);
        $this->assertSame('config_error', $this->decode($out)['error']['code']);
        $this->assertSame(0, DB::table('lantern_traces')->count());
        $this->app['env'] = 'local';
    }

    public function test_diff_compares_the_first_trace_with_an_earlier_record_of_the_same_name(): void
    {
        $this->run_('lantern:run', ['script' => $this->loopScript()]);
        $fixed = $this->script("Lantern::record('loop', fn () => DB::select('select * from r_items where id in (?, ?)', [1, 2]));");

        [$code, $out] = $this->run_('lantern:run', ['script' => $fixed, '--diff' => 'loop']);
        $data = $this->decode($out);

        $this->assertSame(0, $code);
        $this->assertSame('lantern.diff', $data['diff']['schema']);
        $this->assertSame('improved', $data['diff']['verdict']);
    }

    public function test_missing_script_is_a_not_found_error(): void
    {
        [$code] = $this->run_('lantern:run', ['script' => 'nope/missing.php']);

        $this->assertSame(2, $code);
    }
}

<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Query\CallSiteResolver;
use Lantern\Tests\Feature\Query\fixtures\CallSiteFixture;
use Lantern\Tests\TestCase;

require_once __DIR__.'/fixtures/CallSiteFixture.php';

final class CallSiteResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    /** @return list<array<string, mixed>> */
    private function filler(int $count): array
    {
        return array_fill(0, $count, ['file' => '/proj/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 1, 'function' => 'run', 'class' => 'Illuminate\\Database\\Connection']);
    }

    public function test_app_frame_deep_in_trace(): void
    {
        $trace = [...$this->filler(20), ['file' => '/proj/app/Http/PostController.php', 'line' => 42, 'function' => 'first', 'class' => 'Illuminate\\Database\\Eloquent\\Builder'], ['file' => '/proj/app/Http/PostController.php', 'line' => 10, 'function' => 'index', 'class' => 'App\\Http\\PostController']];

        $hit = (new CallSiteResolver(['/proj/app'], '/proj'))->resolveFromTrace($trace);

        $this->assertSame('app/Http/PostController.php', $hit['file']);
        $this->assertSame(42, $hit['line']);
        $this->assertSame('index', $hit['function']);
        $this->assertSame('App\\Http\\PostController', $hit['class']);
        $this->assertFalse($hit['vendor']);
        $this->assertNull($hit['view']);
        $this->assertSame(20, $hit['depth']);
    }

    public function test_vendor_only_falls_back_to_first_package_frame(): void
    {
        $trace = [
            ...$this->filler(3),
            ['file' => '/proj/vendor/laravel/framework/src/Illuminate/Support/Collection.php', 'line' => 5, 'function' => 'map'],
            ['file' => '/proj/vendor/filament/tables/src/Table.php', 'line' => 77, 'function' => 'render', 'class' => 'Filament\\Tables\\Table'],
            ['file' => '/proj/vendor/livewire/livewire/src/Component.php', 'line' => 9, 'function' => 'x'],
        ];

        $hit = (new CallSiteResolver(['/proj/app'], '/proj'))->resolveFromTrace($trace);

        $this->assertTrue($hit['vendor']);
        $this->assertSame('vendor/filament/tables/src/Table.php', $hit['file']);
        $this->assertSame(77, $hit['line']);
        $this->assertSame(4, $hit['depth']);
    }

    public function test_app_frame_wins_over_earlier_vendor_frame(): void
    {
        $trace = [
            ['file' => '/proj/vendor/filament/tables/src/Table.php', 'line' => 77, 'function' => 'render'],
            ['file' => '/proj/app/Page.php', 'line' => 5, 'function' => 'x'],
        ];

        $hit = (new CallSiteResolver(['/proj/app'], '/proj'))->resolveFromTrace($trace);

        $this->assertSame('app/Page.php', $hit['file']);
        $this->assertFalse($hit['vendor']);
    }

    public function test_vendor_dir_under_app_path_is_not_app_code(): void
    {
        $trace = [
            ['file' => '/proj/app/vendor/pkg/Thing.php', 'line' => 3, 'function' => 'a'],
            ['file' => '/proj/app/Real.php', 'line' => 8, 'function' => 'b'],
        ];

        $hit = (new CallSiteResolver(['/proj/app'], '/proj'))->resolveFromTrace($trace);

        $this->assertSame('app/Real.php', $hit['file']);
        $this->assertFalse($hit['vendor']);
        $this->assertSame(1, $hit['depth']);
    }

    public function test_sibling_dir_with_same_prefix_does_not_match(): void
    {
        $trace = [['file' => '/proj/app-other/X.php', 'line' => 1, 'function' => 'a']];

        $this->assertNull((new CallSiteResolver(['/proj/app'], '/proj'))->resolveFromTrace($trace));
    }

    public function test_windows_backslashes_and_case(): void
    {
        $trace = [['file' => 'C:\\Proj\\App\\Models\\Post.php', 'line' => 12, 'function' => 'a']];

        $hit = (new CallSiteResolver(['c:\\proj\\app'], 'c:\\proj'))->resolveFromTrace($trace);

        $this->assertSame('App/Models/Post.php', $hit['file']);
        $this->assertSame(12, $hit['line']);
    }

    public function test_empty_and_fileless_traces(): void
    {
        $resolver = new CallSiteResolver(['/proj/app'], '/proj');

        $this->assertNull($resolver->resolveFromTrace([]));
        $this->assertNull($resolver->resolveFromTrace([['function' => 'internal']]));
    }

    public function test_compiled_blade_frame_maps_to_original_view(): void
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()).'/lantern-cs-'.uniqid();
        $views = $dir.'/resources/views';
        mkdir($views, 0777, true);
        mkdir($dir.'/storage/framework/views', 0777, true);
        $source = $views.'/posts.blade.php';
        file_put_contents($source, "a\nb\nc\n");
        $compiled = $dir.'/storage/framework/views/abc.php';
        file_put_contents($compiled, "<?php echo 1; ?>\n<?php /**PATH {$source} ENDPATH**/ ?>");

        $this->app['config']->set('view.paths', [$views]);

        $trace = [['file' => $compiled, 'line' => 2, 'function' => 'x'], ['file' => $dir.'/vendor/a/b.php', 'line' => 1, 'function' => 'y']];
        $hit = (new CallSiteResolver([$dir.'/resources'], $dir))->resolveFromTrace($trace);

        $this->assertSame('resources/views/posts.blade.php', $hit['file']);
        $this->assertSame(2, $hit['line']);
        $this->assertSame('posts', $hit['view']);
        $this->assertFalse($hit['vendor']);

        // line beyond the source length: keep the compiled path, still report the view name
        $trace[0]['line'] = 99;
        $hit = (new CallSiteResolver([$dir.'/resources'], $dir))->resolveFromTrace($trace);
        $this->assertSame('posts', $hit['view']);
        $this->assertSame('storage/framework/views/abc.php', $hit['file']);

        @unlink($source);
        @unlink($compiled);
    }

    public function test_real_plain_query_and_lazy_load_resolve_to_fixture_lines(): void
    {
        Schema::create('authors', function ($t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('posts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('author_id');
        });
        DB::table('authors')->insert(['name' => 'a']);
        DB::table('posts')->insert(['author_id' => 1]);

        $resolver = new CallSiteResolver([__DIR__.'/fixtures'], dirname(__DIR__, 3));
        $hits = [];
        DB::listen(function ($q) use ($resolver, &$hits) {
            $hits[$q->sql] = $resolver->resolve();
        });

        $fixture = new CallSiteFixture;
        $fixture->plain();
        $fixture->lazy();

        $expect = 'tests/Feature/Query/fixtures/CallSiteFixture.php';
        $plain = $hits['select 1 as one'];
        $this->assertSame($expect, $plain['file']);
        $this->assertSame(CallSiteFixture::line('plain'), $plain['line']);
        $this->assertSame('plain', $plain['function']);
        $this->assertFalse($plain['vendor']);

        $lazy = $hits['select * from "authors" where "authors"."id" = ? limit 1'];
        $this->assertSame($expect, $lazy['file']);
        $this->assertSame(CallSiteFixture::line('lazy'), $lazy['line']);
        $this->assertGreaterThan($plain['depth'], $lazy['depth']);
    }

    public function test_paging_frames_below_the_app_frame_mark_the_site(): void
    {
        $r = new CallSiteResolver(['/proj/app'], '/proj');
        $db = 'Illuminate\Database\Eloquent\Builder';
        $paging = [
            ['file' => '/proj/vendor/laravel/framework/src/Illuminate/Database/Concerns/BuildsQueries.php', 'line' => 1, 'function' => 'get', 'class' => $db],
            ['file' => '/proj/app/Job.php', 'line' => 7, 'function' => 'chunk', 'class' => $db],
        ];
        $this->assertTrue($r->resolveFromTrace($paging)['paging']);

        $lazy = [
            ['file' => '/proj/vendor/laravel/framework/src/Illuminate/Database/Concerns/BuildsQueries.php', 'line' => 1, 'function' => '{closure:Illuminate\Database\Concerns\BuildsQueries::lazy():9}', 'class' => $db],
            ['file' => '/proj/app/Job.php', 'line' => 7, 'function' => 'x'],
        ];
        $this->assertTrue($r->resolveFromTrace($lazy)['paging']);

        // query issued inside the chunk callback: app frame comes first
        $callback = [
            ['file' => '/proj/app/Job.php', 'line' => 8, 'function' => 'first', 'class' => $db],
            ['file' => '/proj/vendor/laravel/framework/src/Illuminate/Database/Concerns/BuildsQueries.php', 'line' => 1, 'function' => '{closure}'],
            ['file' => '/proj/app/Job.php', 'line' => 7, 'function' => 'chunk', 'class' => $db],
        ];
        $this->assertArrayNotHasKey('paging', $r->resolveFromTrace($callback));
    }
}

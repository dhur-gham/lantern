<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Explain;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Issue;
use Lantern\Tests\Feature\Query\QueryTestCase;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class MissingIndexDetectorTest extends QueryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('e_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
        });
        DB::table('e_items')->insert([['sku' => 'a'], ['sku' => 'b']]);
    }

    /** @return array<string, mixed> */
    private function config(bool $explain = true): array
    {
        return ['explain' => $explain, 'thresholds' => ['slow_query_ms' => 100, 'n_plus_one_min_repeats' => 5]];
    }

    /** @param list<mixed> $bindings */
    private function traceFor(string $sql, array $bindings): Trace
    {
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        $now = hrtime(true);
        $recorder->recordClosed(SpanType::Query, 'q', $now, $now + 60_000_000, [
            'sql' => $sql, 'bindings' => $bindings, 'fingerprint' => md5($sql), 'connection' => 'testing',
            'time_ms' => 60.0, 'failed' => false,
        ]);

        return $trace;
    }

    public function test_unindexed_flags_and_indexed_does_not(): void
    {
        $sql = 'select * from "e_items" where "sku" = ?';
        $issues = (new MissingIndexDetector($this->config()))->detect($this->traceFor($sql, ['a']));
        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_MISSING_INDEX, $issues[0]->type);
        $this->assertSame('Possible missing index on e_items', $issues[0]->title);
        $this->assertSame('Consider an index on (sku)', $issues[0]->suggestion);

        Schema::table('e_items', fn (Blueprint $t) => $t->index('sku'));
        $this->assertSame([], (new MissingIndexDetector($this->config()))->detect($this->traceFor($sql, ['a'])));
    }

    public function test_disabled_and_writes_do_nothing(): void
    {
        $sql = 'select * from "e_items" where "sku" = ?';
        $this->assertSame([], (new MissingIndexDetector($this->config(false)))->detect($this->traceFor($sql, ['a'])));
        $this->assertNull((new ExplainRunner)->explain('testing', 'delete from "e_items" where "sku" = ?', ['a']));
        $this->assertNull((new ExplainRunner)->explain('testing', 'update e_items set sku = 1', []));
        $this->assertSame(2, DB::table('e_items')->count());
    }

    public function test_failing_explain_is_swallowed(): void
    {
        $this->assertNull((new ExplainRunner)->explain('testing', 'select * from nope where x = ?', [1]));
        $this->assertNull((new ExplainRunner)->explain('missing_conn', 'select 1', []));
    }

    public function test_explain_creates_no_spans(): void
    {
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        $plan = (new ExplainRunner)->explain('testing', 'select * from "e_items" where "sku" = ?', ['a']);
        $this->assertTrue($plan['full_scan']);
        $this->assertCount(0, $this->queries($trace));
    }

    public function test_scan_without_a_predicate_and_framework_tables_are_not_flagged(): void
    {
        $this->assertSame([], (new MissingIndexDetector($this->config()))->detect($this->traceFor('select count(*) as aggregate from "e_items"', [])));
        $this->assertSame([], (new MissingIndexDetector($this->config()))->detect($this->traceFor('select * from "e_items"', [])));

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('payload');
        });
        $this->assertSame([], (new MissingIndexDetector($this->config()))->detect($this->traceFor('select * from "sessions" where "payload" = ?', ['x'])));
    }

    public function test_cheap_queries_below_explain_min_ms_are_not_explained(): void
    {
        $cfg = $this->config();
        $cfg['thresholds']['explain_min_ms'] = 100;
        $cfg['thresholds']['slow_query_ms'] = 1000;
        $this->assertSame([], (new MissingIndexDetector($cfg))->detect($this->traceFor('select * from "e_items" where "sku" = ?', ['a'])));
    }
}

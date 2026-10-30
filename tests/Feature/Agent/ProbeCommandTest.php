<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\PlanFlip\InListProbe;

final class ProbeCommandTest extends AgentTestCase
{
    private const KEY = 'plan0flip0issue0key0000000000001';

    private const SQL = 'select * from "pr_orders" where "merchant_id" in (?, ?, ?, ?, ?, ?, ?) and "state" = ?';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pr_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('merchant_id')->index();
            $t->string('state')->default('open');
        });
        foreach (range(1, 60) as $i) {
            DB::table('pr_orders')->insert(['merchant_id' => 1000 + ($i % 30)]);
        }

        $this->seedTrace('t-probe', 7, [], sql: self::SQL);
        $this->seedIssue(self::KEY, 'plan_flip', traceId: 't-probe');
        $trace = json_decode((string) DB::table('lantern_traces')->where('id', 't-probe')->value('payload'), true);
        $trace['meta']['issues'] = [[
            'key' => self::KEY, 'type' => 'plan_flip', 'severity' => 'warning', 'title' => 'Plan changes', 'count' => 7, 'total_ms' => 9.0,
            'call_site' => null, 'span_ids' => [2], 'meta' => ['sample_sql' => self::SQL], 'suggestion' => null,
        ]];
        DB::table('lantern_traces')->where('id', 't-probe')->update(['payload' => json_encode($trace)]);
    }

    public function test_probe_uses_real_ids_and_reports_a_stable_plan(): void
    {
        [$code, $out] = $this->jsonOut('lantern:probe', ['key' => substr(self::KEY, 0, 12), '--in-sizes' => '1,7,20,100']);

        $this->assertSame(0, $code);
        $this->assertSame('lantern.probe', $out['schema']);
        $this->assertSame(1, $out['schema_version']);
        $this->assertSame(self::KEY, $out['issue_id']);
        $this->assertSame('pr_orders', $out['table']);
        $this->assertSame('merchant_id', $out['column']);
        $this->assertSame('frequent_values', $out['ids_source']);
        $this->assertArrayNotHasKey('ids_note', $out);
        $this->assertSame('stable', $out['verdict']);
        $this->assertSame([], $out['plan_changes']);
        $this->assertSame([1, 7, 20, 30], array_column($out['sizes'], 'in_size'));
        $this->assertSame([1, 7, 20, 100], array_column($out['sizes'], 'requested'));
        $this->assertStringContainsString('in (?)', $out['sql_shape']);
        $this->assertStringNotContainsString('1000', json_encode($out));
    }

    public function test_probe_reports_where_the_plan_changes(): void
    {
        $this->app->instance(InListProbe::class, new InListProbe(null, function (?string $connection, string $sql): array {
            $scan = substr_count($sql, '?') > 21; // list length plus the state binding

            return ['full_scan' => $scan, 'table' => 'pr_orders', 'rows' => $scan ? 800_000 : 40, 'key' => $scan ? null : 'merchant_id', 'possible_keys' => 'merchant_id', 'raw' => []];
        }));

        [$code, $out] = $this->jsonOut('lantern:probe', ['key' => self::KEY, '--in-sizes' => '7,20,30']);

        $this->assertSame(0, $code);
        $this->assertSame('plan_changes', $out['verdict']);
        $this->assertCount(1, $out['plan_changes']);
        $this->assertSame(20, $out['plan_changes'][0]['from_size']);
        $this->assertSame(30, $out['plan_changes'][0]['to_size']);
        $this->assertSame('full scan on pr_orders', $out['plan_changes'][0]['to']);
    }

    public function test_probe_falls_back_to_synthetic_ids(): void
    {
        Schema::drop('pr_orders');
        $this->app->instance(InListProbe::class, new InListProbe(null, fn () => ['full_scan' => false, 'table' => null, 'rows' => null, 'key' => 'idx', 'possible_keys' => null, 'raw' => []]));

        [$code, $out] = $this->jsonOut('lantern:probe', ['key' => self::KEY, '--in-sizes' => '3,50']);

        $this->assertSame(0, $code);
        $this->assertSame('synthetic', $out['ids_source']);
        $this->assertStringContainsString('1..N', $out['ids_note']);
        $this->assertSame([3, 50], array_column($out['sizes'], 'in_size'));
    }

    public function test_lists_hold_the_most_frequent_values_and_smaller_lists_are_prefixes(): void
    {
        $this->skew();
        $seen = [];
        $this->app->instance(InListProbe::class, new InListProbe(null, function (?string $connection, string $sql, array $bindings) use (&$seen): array {
            $seen[] = array_slice($bindings, 0, -1);

            return ['full_scan' => false, 'table' => 'pr_orders', 'rows' => 10, 'key' => 'merchant_id', 'possible_keys' => null, 'raw' => []];
        }));

        [$code, $out] = $this->jsonOut('lantern:probe', ['key' => self::KEY, '--in-sizes' => '1,3']);

        $this->assertSame(0, $code);
        $this->assertSame('frequent_values', $out['ids_source']);
        $this->assertSame([[1029], [1029, 1005, 1000]], $seen, 'most frequent first, ties by value');
        $this->assertStringNotContainsString('1029', json_encode($out), 'the values themselves are not emitted');
    }

    public function test_a_value_with_few_rows_does_not_report_a_false_flip(): void
    {
        $this->skew();
        // the optimizer scans when the list matches only a handful of rows, as it does for an id with no data
        $this->app->instance(InListProbe::class, new InListProbe(null, function (?string $connection, string $sql, array $bindings): array {
            $matched = DB::table('pr_orders')->whereIn('merchant_id', array_slice($bindings, 0, -1))->count();
            $scan = $matched < 5;

            return ['full_scan' => $scan, 'table' => 'pr_orders', 'rows' => $matched, 'key' => $scan ? null : 'merchant_id', 'possible_keys' => 'merchant_id', 'raw' => []];
        }));

        [$code, $out] = $this->jsonOut('lantern:probe', ['key' => self::KEY, '--in-sizes' => '1,7,20']);

        $this->assertSame(0, $code);
        $this->assertSame('stable', $out['verdict']);
        $this->assertSame([], $out['plan_changes']);
        $this->assertFalse($out['sizes'][0]['plan']['full_scan'], 'size 1 uses a value that has rows');
    }

    public function test_null_values_are_never_probed(): void
    {
        Schema::create('pr_owned', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('owner')->nullable();
        });
        foreach (range(1, 30) as $i) {
            DB::table('pr_owned')->insert(['owner' => null]);
        }
        DB::table('pr_owned')->insert([['owner' => 5], ['owner' => 5], ['owner' => 9]]);

        $seen = [];
        $probe = new InListProbe(null, function (?string $connection, string $sql, array $bindings) use (&$seen): array {
            $seen[] = $bindings;

            return ['full_scan' => false, 'table' => 'pr_owned', 'rows' => 3, 'key' => 'idx', 'possible_keys' => null, 'raw' => []];
        });

        $out = $probe->run('select * from "pr_owned" where "owner" in (?, ?)', [1, 2], null, [1, 4]);

        $this->assertSame('frequent_values', $out['ids_source']);
        $this->assertSame([1, 2], array_column($out['sizes'], 'in_size'), 'only the two non-null values exist');
        $this->assertSame([[5], [5, 9]], $seen);
    }

    /** 1029 holds 22 rows, 1005 holds 10, every other value 2. */
    private function skew(): void
    {
        DB::table('pr_orders')->insert(array_fill(0, 20, ['merchant_id' => 1029]));
        DB::table('pr_orders')->insert(array_fill(0, 8, ['merchant_id' => 1005]));
    }

    public function test_usage_and_lookup_errors_exit_2(): void
    {
        foreach (['0', 'abc', '1,,2', '99999', '1,2,3,4,5,6,7,8,9,10,11,12,13'] as $bad) {
            [$code, $out] = $this->jsonOut('lantern:probe', ['key' => self::KEY, '--in-sizes' => $bad]);
            $this->assertSame(2, $code, $bad);
            $this->assertSame('usage_error', $out['error']['code']);
        }

        [$code, $out] = $this->jsonOut('lantern:probe', ['key' => 'ffffffffffff']);
        $this->assertSame(2, $code);
        $this->assertSame('not_found', $out['error']['code']);
    }

    public function test_query_without_an_in_list_is_a_usage_error(): void
    {
        $this->seedTrace('t-plain', 2, ['plain0issue0key00000000000000001'], sql: 'select * from "pr_orders" where "id" = ?');
        $this->seedIssue('plain0issue0key00000000000000001', 'slow_query', traceId: 't-plain');

        [$code, $out] = $this->jsonOut('lantern:probe', ['key' => 'plain0issue0key00000000000000001']);

        $this->assertSame(2, $code);
        $this->assertSame('usage_error', $out['error']['code']);
    }
}

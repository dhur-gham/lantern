<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lantern\Lantern;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;

final class DashboardAuthTest extends StressTestCase
{
    protected bool $persist = true;

    protected bool $seed = false;

    private const SECRET = 'S3CR3T-BINDING-VALUE';

    protected function setUp(): void
    {
        $this->environment = str_contains($this->name(), 'production') ? 'production' : 'local';
        parent::setUp();
    }

    private function seedSecretTrace(): string
    {
        $r = new Recorder;
        $trace = $r->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/orders', 'status' => 200]);
        $r->recordClosed(SpanType::Query, 'select * from users where token = ?', hrtime(true), hrtime(true), [
            'sql' => 'select * from users where token = ?', 'bindings' => [self::SECRET], 'fingerprint' => 'fp', 'failed' => false,
        ]);
        $r->end();
        (new DatabaseTraceStore($this->app['db'], null, false))->store($trace, []);
        DB::table('lantern_issues')->insert([
            'issue_key' => 'k1', 'type' => 'n_plus_one', 'title' => 'N+1 '.self::SECRET, 'severity' => 'warning', 'occurrences' => 1,
            'count_total' => 5, 'total_ms' => 1.0, 'max_ms' => 1.0, 'call_site' => null, 'suggestion' => 's',
            'first_seen' => date('Y-m-d H:i:s'), 'last_seen' => date('Y-m-d H:i:s'), 'last_trace_id' => $trace->id,
        ]);

        return $trace->id;
    }

    public function test_local_environment_is_allowed_by_default(): void
    {
        $id = $this->seedSecretTrace();

        $this->getJson('/lantern/api/traces')->assertOk();
        $this->getJson('/lantern/api/traces/'.$id)->assertOk();
        $this->getJson('/lantern/api/issues')->assertOk();
        $this->getJson('/lantern/api/issues/k1')->assertOk();
    }

    public function test_production_environment_is_forbidden_by_default_on_every_endpoint(): void
    {
        $id = $this->seedSecretTrace();

        foreach (['/lantern/api/traces', '/lantern/api/traces/'.$id, '/lantern/api/issues', '/lantern/api/issues/k1'] as $url) {
            $response = $this->getJson($url);
            $response->assertForbidden();
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        }
    }

    public function test_local_environment_is_forbidden_for_non_loopback_clients(): void
    {
        $id = $this->seedSecretTrace();

        foreach (['203.0.113.9', '10.0.0.5', '172.18.0.1'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/lantern/api/traces/'.$id)->assertForbidden();
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/lantern')->assertForbidden();
        }
    }

    public function test_local_environment_allows_loopback_clients(): void
    {
        $this->seedSecretTrace();

        foreach (['127.0.0.1', '::1'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/lantern/api/traces')->assertOk();
        }
    }

    public function test_forwarded_headers_cannot_make_a_remote_client_look_local(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-For' => '127.0.0.1', 'X-Real-IP' => '127.0.0.1'])
            ->getJson('/lantern/api/traces')
            ->assertForbidden();
    }

    public function test_tunnelled_requests_are_forbidden_even_from_loopback(): void
    {
        $this->seedSecretTrace();

        foreach (['X-Forwarded-For' => '203.0.113.9', 'Forwarded' => 'for=203.0.113.9', 'X-Forwarded-Host' => 'abc.ngrok.io', 'X-Real-IP' => '203.0.113.9'] as $header => $value) {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
                ->withHeaders([$header => $value])
                ->getJson('/lantern/api/traces')
                ->assertForbidden();
        }
    }

    public function test_custom_gate_decides_alone_for_non_loopback_clients(): void
    {
        Lantern::auth(fn (Request $r) => true);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/lantern/api/traces')->assertOk();
    }

    public function test_production_pages_are_forbidden_and_do_not_leak(): void
    {
        $id = $this->seedSecretTrace();

        foreach (['/lantern', '/lantern/traces/'.$id, '/lantern/issues', '/lantern/issues/k1'] as $url) {
            $response = $this->get($url);
            $response->assertForbidden();
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        }
    }

    public function test_production_unknown_id_is_403_not_404(): void
    {
        $this->getJson('/lantern/api/traces/nope')->assertForbidden();
        $this->getJson('/lantern/api/issues/nope')->assertForbidden();
    }

    public function test_local_unknown_id_is_404(): void
    {
        $this->getJson('/lantern/api/traces/nope')->assertNotFound();
        $this->getJson('/lantern/api/issues/nope')->assertNotFound();
    }

    public function test_production_custom_gate_true_allows(): void
    {
        $id = $this->seedSecretTrace();
        Lantern::auth(fn (Request $r) => true);

        $this->getJson('/lantern/api/traces/'.$id)->assertOk();
    }

    public function test_production_custom_gate_false_denies_even_though_local_rule_would_not_apply(): void
    {
        $id = $this->seedSecretTrace();
        Lantern::auth(fn (Request $r) => false);

        $this->getJson('/lantern/api/traces/'.$id)->assertForbidden();
    }

    public function test_custom_gate_false_denies_in_local_too(): void
    {
        $id = $this->seedSecretTrace();
        Lantern::auth(fn (Request $r) => false);

        $this->getJson('/lantern/api/traces/'.$id)->assertForbidden();
        $this->get('/lantern')->assertForbidden();
    }

    public function test_custom_gate_receives_the_request_and_can_check_a_header(): void
    {
        $id = $this->seedSecretTrace();
        Lantern::auth(fn (Request $r) => $r->header('X-Lantern-Key') === 'let-me-in');

        $this->getJson('/lantern/api/traces/'.$id)->assertForbidden();
        $this->getJson('/lantern/api/traces/'.$id, ['X-Lantern-Key' => 'wrong'])->assertForbidden();
        $this->getJson('/lantern/api/traces/'.$id, ['X-Lantern-Key' => 'let-me-in'])->assertOk();
    }

    public function test_custom_gate_truthy_non_bool_values_are_cast(): void
    {
        Lantern::auth(fn () => 1);
        $this->getJson('/lantern/api/traces')->assertOk();

        Lantern::auth(fn () => 0);
        $this->getJson('/lantern/api/traces')->assertForbidden();

        Lantern::auth(fn () => null);
        $this->getJson('/lantern/api/traces')->assertForbidden();

        Lantern::auth(fn () => '');
        $this->getJson('/lantern/api/traces')->assertForbidden();
    }

    public function test_throwing_gate_does_not_leak_data(): void
    {
        $id = $this->seedSecretTrace();
        Lantern::auth(function () {
            throw new \RuntimeException('gate exploded');
        });

        $response = $this->getJson('/lantern/api/traces/'.$id);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_local_authorised_trace_detail_exposes_bindings_but_listing_does_not(): void
    {
        $id = $this->seedSecretTrace();

        $this->assertStringNotContainsString(self::SECRET, $this->getJson('/lantern/api/traces')->getContent());
        $this->assertStringContainsString(self::SECRET, $this->getJson('/lantern/api/traces/'.$id)->getContent());
    }

    public function test_query_string_filters_cannot_bypass_the_gate_in_production(): void
    {
        $this->seedSecretTrace();

        $this->getJson('/lantern/api/traces?status=200&path=orders&has_issues=1&limit=500')->assertForbidden();
        $this->getJson('/lantern/api/issues?type=n_plus_one&sort=total_ms')->assertForbidden();
    }

    public function test_method_override_and_head_do_not_bypass_the_gate_in_production(): void
    {
        $this->seedSecretTrace();

        $this->call('HEAD', '/lantern/api/traces')->assertForbidden();
        $options = $this->call('OPTIONS', '/lantern/api/traces');
        $this->assertSame('', $options->getContent());
        $this->getJson('/lantern/api/traces', ['X-HTTP-Method-Override' => 'GET', 'X-Forwarded-For' => '127.0.0.1', 'X-Forwarded-Host' => 'localhost'])->assertForbidden();
    }

    public function test_dashboard_requests_are_not_traced(): void
    {
        $this->getJson('/lantern/api/traces')->assertOk();
        $this->get('/lantern')->assertOk();

        $this->assertSame(0, DB::table('lantern_traces')->count());
    }

    public function test_filters_survive_sql_special_characters(): void
    {
        $this->seedSecretTrace();

        foreach (["' OR 1=1 --", '%', '_', '\\', "a\0b", str_repeat('é', 5000)] as $path) {
            $this->getJson('/lantern/api/traces?'.http_build_query(['path' => $path]))->assertOk();
        }
        $this->getJson('/lantern/api/traces?status='.urlencode("500' OR '1'='1"))->assertOk();
        $this->getJson('/lantern/api/issues?sort=total_ms;drop table lantern_issues')->assertOk();
        $this->assertSame(1, DB::table('lantern_issues')->count());
    }

    public function test_limit_is_clamped(): void
    {
        $this->seedSecretTrace();

        $this->getJson('/lantern/api/traces?limit=-5')->assertOk();
        $this->getJson('/lantern/api/traces?limit=999999999')->assertOk();
        $this->getJson('/lantern/api/traces?limit=abc')->assertOk();
    }

    public function test_malformed_gzip_payload_does_not_500_the_detail_endpoint(): void
    {
        $this->seedSecretTrace();
        $row = (array) DB::table('lantern_traces')->first();
        $row['id'] = 'bad-gz-0001';
        $row['payload'] = 'gz:'.base64_encode('this is not gzip data');
        DB::table('lantern_traces')->insert($row);

        $this->getJson('/lantern/api/traces/bad-gz-0001')->assertOk();
    }

    public function test_malformed_plain_payloads_are_tolerated(): void
    {
        $this->seedSecretTrace();
        $base = (array) DB::table('lantern_traces')->first();
        foreach (['not json at all', '', '[]', '"str"', '{"root":', '{"root":"notanode"}', '{"root":{"children":"x"}}'] as $i => $payload) {
            $row = $base;
            $row['id'] = 'bad-'.$i;
            $row['payload'] = $payload;
            DB::table('lantern_traces')->insert($row);

            $response = $this->getJson('/lantern/api/traces/bad-'.$i);
            $this->assertSame(200, $response->getStatusCode(), 'payload '.json_encode($payload).' -> '.$response->getStatusCode());
        }
    }

    public function test_empty_gz_payload_is_tolerated(): void
    {
        $this->seedSecretTrace();
        $row = (array) DB::table('lantern_traces')->first();
        $row['id'] = 'bad-empty-gz';
        $row['payload'] = 'gz:';
        DB::table('lantern_traces')->insert($row);

        $this->assertSame(200, $this->getJson('/lantern/api/traces/bad-empty-gz')->getStatusCode());
    }

    public function test_non_base64_gz_payload_is_tolerated(): void
    {
        $this->seedSecretTrace();
        $row = (array) DB::table('lantern_traces')->first();
        $row['id'] = 'bad-b64';
        $row['payload'] = 'gz:!!!not-base64!!!';
        DB::table('lantern_traces')->insert($row);

        $this->assertSame(200, $this->getJson('/lantern/api/traces/bad-b64')->getStatusCode());
    }
}

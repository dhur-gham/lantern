<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Jobs;

use Lantern\Trace\SpanType;
use RuntimeException;

final class JobTraceTest extends JobTestCase
{
    public function test_sync_job_is_its_own_trace_with_n_plus_one_issue(): void
    {
        NPlusOneJob::dispatch();

        $trace = $this->sink()->last();
        $this->assertNotNull($trace);
        $root = $trace->root();
        $this->assertSame(SpanType::Job, $root->type);
        $this->assertSame(NPlusOneJob::class, $root->name);
        $this->assertSame('job', $trace->meta['kind']);
        $this->assertSame('processed', $trace->meta['status']);
        $this->assertSame('sync', $trace->meta['connection']);
        $this->assertSame(1, $trace->meta['attempts']);
        $this->assertCount(8, $this->queries($trace));

        $issue = collect($trace->meta['issues'])->firstWhere('type', 'n_plus_one');
        $this->assertNotNull($issue);
        $this->assertStringEndsWith('JobTestCase.php', $issue['call_site']['file']);
        $this->assertSame(NPlusOneJob::$line, $issue['call_site']['line']);
    }

    public function test_failed_job_is_recorded_with_exception(): void
    {
        try {
            FailingJob::dispatch();
            $this->fail('expected the job to throw');
        } catch (RuntimeException) {
        }

        $trace = $this->sink()->last();
        $this->assertSame(FailingJob::class, $trace->root()->name);
        $this->assertSame('failed', $trace->meta['status']);
        $this->assertSame(RuntimeException::class, $trace->meta['exception']['class']);
        $this->assertSame('job exploded', $trace->root()->meta['exception']['message']);
        $this->assertCount(1, $this->queries($trace));
        $this->assertCount(1, $this->sink()->all());
    }

    public function test_two_jobs_in_one_process_do_not_leak_state(): void
    {
        NPlusOneJob::dispatch();
        SingleQueryJob::dispatch();

        [$first, $second] = $this->sink()->all();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(SingleQueryJob::class, $second->root()->name);
        $this->assertCount(1, $this->queries($second));
        $this->assertSame([], $second->meta['issues']);
        $this->assertFalse($this->recorder()->isActive());
    }

    public function test_sync_job_inside_a_request_is_a_child_span(): void
    {
        $this->app['router']->get('/dispatch', function () {
            SingleQueryJob::dispatch();

            return 'ok';
        });

        $this->get('/dispatch')->assertOk();

        $this->assertCount(1, $this->sink()->all());
        $trace = $this->sink()->last();
        $this->assertSame(SpanType::Request, $trace->root()->type);
        $jobs = array_values(array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Job));
        $this->assertCount(1, $jobs);
        $this->assertSame(SingleQueryJob::class, $jobs[0]->name);
        $this->assertSame('processed', $jobs[0]->meta['status']);
        $this->assertNotNull($jobs[0]->parentId);
        $this->assertNotEmpty(array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Query && $s->parentId === $jobs[0]->id));
    }
}

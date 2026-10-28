<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;

/** App that never enabled prevention: Lantern must not introduce exceptions. */
final class LazyLoadNeverEnabledTest extends StressTestCase
{
    public function test_lazy_loop_is_not_turned_into_an_exception(): void
    {
        $this->get('/scenario/lazy-loop')->assertOk()->assertSee('30');
    }

    public function test_all_lazy_style_scenarios_still_succeed(): void
    {
        foreach (['accessor-n1', 'resource-n1', 'blade-n1', 'poly-lazy', 'listener-n1', 'job-n1'] as $name) {
            $this->get('/scenario/'.$name)->assertOk();
        }
    }

    public function test_model_created_in_request_does_not_trigger_violation(): void
    {
        $this->app['router']->get('/create-then-lazy', function () {
            $post = \Lantern\Tests\Stress\Support\SPost::create(['author_id' => 1, 'title' => 'x']);

            return $post->author->name;
        });

        $this->get('/create-then-lazy')->assertOk()->assertSee('author1');
    }

    public function test_single_model_lazy_load_does_not_throw(): void
    {
        $this->app['router']->get('/single', fn () => \Lantern\Tests\Stress\Support\SPost::first()->author->name);

        $this->get('/single')->assertOk();
    }
}

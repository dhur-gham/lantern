<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Eloquent;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Database\Eloquent\Model;
use Lantern\Query\CallSiteResolver;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use ReflectionProperty;
use Throwable;

/**
 * Lazy-load events, observed without changing app behaviour.
 *
 * Mechanism (verified, docs/research/04-verified.md section 0.4 and 2.C/9): Eloquent only reports a lazy load when
 * Model::preventLazyLoading() is on, and the violation callback is ONE static slot (Model::$lazyLoadingViolationCallback)
 * that does not chain. register() reads both by reflection, enables prevention (so the hook fires) and installs a
 * wrapper that records an event span, then:
 *   - a callback the app had installed is called with the same arguments (its result and exceptions pass through);
 *   - with no previous callback, the default LazyLoadingViolationException is thrown ONLY when the app had enabled
 *     prevention itself (mirroring HasAttributes: exists and not recently created); otherwise nothing is thrown, so
 *     the lazy load proceeds exactly as before.
 * Per-request state lives in the Recorder; the only static state is the one-time install (idempotent, Octane safe).
 *
 * Limits: call register() after the app's providers booted (e.g. inside $app->booted()), otherwise an app that enables
 * prevention or sets its own callback later overrides or is mistaken for "no prevention". An app that calls
 * handleLazyLoadingViolationUsing() after register() removes the wrapper. Eloquent only flags models hydrated in
 * batches of 2+ rows, and automaticallyEagerLoadRelationships() hides the violation; single-row N+1 is not visible here.
 */
final class LazyLoadInstrumentation
{
    private static ?Closure $installed = null;

    public static function register(Application $app): void
    {
        $callback = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');
        $current = $callback->getValue();

        if (self::$installed !== null && $current === self::$installed) {
            return;
        }

        $appEnabledPrevention = Model::preventsLazyLoading();
        $previous = $current;
        $resolver = null;

        $wrapper = static function (Model $model, string $relation) use ($app, $previous, $appEnabledPrevention, &$resolver): mixed {
            try {
                $recorder = Container::getInstance()->make(Recorder::class);
                if ($recorder->isActive()) {
                    $resolver ??= self::resolver($app);
                    $now = hrtime(true);
                    $recorder->recordClosed(
                        SpanType::Event,
                        'lazy_load '.class_basename($model).'::'.$relation,
                        $now,
                        $now,
                        ['model' => $model::class, 'relation' => $relation, 'kind' => 'lazy_load'],
                        $resolver->resolve(),
                    );
                }
            } catch (Throwable) {
                // instrumentation must never break the host app
            }

            if ($previous !== null) {
                return $previous($model, $relation);
            }

            if ($appEnabledPrevention && $model->exists && ! $model->wasRecentlyCreated) {
                throw new LazyLoadingViolationException($model, $relation);
            }

            return null;
        };

        self::$installed = $wrapper;
        Model::handleLazyLoadingViolationUsing($wrapper);

        if (! $appEnabledPrevention) {
            Model::preventLazyLoading(true);
        }
    }

    private static function resolver(Application $app): CallSiteResolver
    {
        $config = $app['config'];
        $paths = array_values(array_filter((array) $config->get('lantern.app_paths', []), 'is_string'));
        if ($paths === []) {
            $paths = [$app->path()];
        }

        return new CallSiteResolver($paths, $app->basePath(), max(1, (int) $config->get('lantern.backtrace_limit', 50)));
    }
}

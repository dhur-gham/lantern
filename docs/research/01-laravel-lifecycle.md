# 01 - Laravel 12.x lifecycle map for a tracing package (Lantern)

Scope: where a package can observe each stage of HTTP / console / queue / Octane execution, what it costs, and what is unsafe.
Sources: laravel.com/docs/12.x and laravel/framework `12.x` branch (`Application::VERSION` = `12.70.2` at fetch time, 2026-10-27), laravel/octane `1.x`.

Evidence legend: **[V]** = verified this session against the cited official doc/source. **[M]** = from memory / not re-verified, check before relying on it.

---

## 0. Source index

| Tag | URL |
|---|---|
| doc-lifecycle | https://laravel.com/docs/12.x/lifecycle |
| doc-middleware | https://laravel.com/docs/12.x/middleware |
| doc-database | https://laravel.com/docs/12.x/database |
| doc-eloquent | https://laravel.com/docs/12.x/eloquent |
| doc-relations | https://laravel.com/docs/12.x/eloquent-relationships |
| doc-events | https://laravel.com/docs/12.x/events |
| doc-queues | https://laravel.com/docs/12.x/queues |
| doc-octane | https://laravel.com/docs/12.x/octane |
| doc-cache | https://laravel.com/docs/12.x/cache |
| doc-http-client | https://laravel.com/docs/12.x/http-client |
| doc-validation | https://laravel.com/docs/12.x/validation |
| doc-logging | https://laravel.com/docs/12.x/logging |
| doc-releases | https://laravel.com/docs/12.x/releases |
| src-Kernel (http) | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Foundation/Http/Kernel.php |
| src-Kernel (console) | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Foundation/Console/Kernel.php |
| src-Application | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Foundation/Application.php |
| src-Connection | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Connection.php |
| src-QueryExecuted | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Events/QueryExecuted.php |
| src-Router | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Routing/Router.php |
| src-Route | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Routing/Route.php |
| src-Dispatcher | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Events/Dispatcher.php |
| src-View Factory | https://github.com/laravel/framework/blob/12.x/src/Illuminate/View/Factory.php |
| src-ManagesEvents | https://github.com/laravel/framework/blob/12.x/src/Illuminate/View/Concerns/ManagesEvents.php |
| src-View | https://github.com/laravel/framework/blob/12.x/src/Illuminate/View/View.php |
| src-CompilerEngine | https://github.com/laravel/framework/blob/12.x/src/Illuminate/View/Engines/CompilerEngine.php |
| src-Model | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Eloquent/Model.php |
| src-HasAttributes | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php |
| src-Eloquent Builder | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Eloquent/Builder.php |
| src-LazyLoadingViolationException | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/LazyLoadingViolationException.php |
| src-Queue Worker | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Queue/Worker.php |
| src-RequestHandled | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Foundation/Http/Events/RequestHandled.php |
| src-HttpClient Factory | https://github.com/laravel/framework/blob/12.x/src/Illuminate/Http/Client/Factory.php |
| src-octane config | https://github.com/laravel/octane/blob/1.x/config/octane.php |
| src-octane RequestReceived | https://github.com/laravel/octane/blob/1.x/src/Events/RequestReceived.php |

---

## 1. End-to-end stage map (HTTP)

| # | Stage | What happens | Primary observation point |
|---|---|---|---|
| 1 | Entry | `public/index.php` loads autoloader, gets app from `bootstrap/app.php`, calls `$app->handleRequest()` (console: `handleCommand()`) [V doc-lifecycle, src-Application] | Nothing in framework yet. Package code is not loaded until providers register. Only `LARAVEL_START` / `$_SERVER['REQUEST_TIME_FLOAT']` exist. |
| 2 | Kernel `handle()` | Sets `requestStartedAt`, `enableHttpMethodParameterOverride()`, `sendRequestThroughRouter()`; on throwable: `reportException` + `renderException`; always dispatches `RequestHandled` [V src-Kernel] | `Kernel::requestStartedAt()`, `RequestHandled` |
| 3 | Bootstrap | `bootstrapWith()`: for each bootstrapper dispatches `bootstrapping: <Class>`, runs it, dispatches `bootstrapped: <Class>`. HTTP list: LoadEnvironmentVariables, LoadConfiguration, HandleExceptions, RegisterFacades, RegisterProviders, BootProviders [V src-Kernel, src-Application] | string events `bootstrapping: *` / `bootstrapped: *` (see 2.1) |
| 4 | Providers | `register()` on all, then `boot()` on all. `booting` callbacks, per-provider booting, `boot()`, booted callbacks, then app `booted` callbacks [V src-Application] | `app->booting()`, `app->booted()`, your own provider's `boot()` |
| 5 | Global middleware | Pipeline over global stack unless `shouldSkipMiddleware()` [V src-Kernel] | Wrap via your own global middleware (prepend) |
| 6 | Routing | `Router::dispatch` -> `dispatchToRoute` -> `findRoute` (fires `Routing`, matches, binds `Route::class`) -> `runRoute` (sets route resolver, fires `RouteMatched`) [V src-Router] | `Routing`, `RouteMatched` |
| 7 | Route middleware | `runRouteWithinStack`: `gatherRouteMiddleware` + `Pipeline`; skipped if container has `middleware.disable` [V src-Router] | Per-middleware timing only via own wrapper (see 2.3) |
| 8 | Controller / closure | `Route::run()` -> `runController` (`ControllerDispatcher::dispatch`) or `runCallable` (`CallableDispatcher`) [V src-Route] | No framework event. Use `RouteMatched` for identity and a route middleware or `Route::getActionName()` for naming |
| 9 | Queries / models / cache / http / events | During 8 and elsewhere | See sections 3-6 |
| 10 | Validation | In controller or FormRequest (resolved before controller method) [V doc-validation] | No dedicated events (see 7) |
| 11 | View render | `view()` -> `Factory::make()` -> `callCreator`; `View::render()` -> `renderContents()` -> `callComposer` -> `getContents()` [V src-View Factory, src-View, src-ManagesEvents] | `creating: <view>`, `composing: <view>` events (see 8) |
| 12 | Response prep | `prepareResponse` fires `PreparingResponse`, converts via `toResponse`, fires `ResponsePrepared`; runs twice per request (inside pipeline `then` and again in `runRoute`) [V src-Router] | `PreparingResponse`, `ResponsePrepared` |
| 13 | Response back through middleware | Outward half of each middleware | Own middleware |
| 14 | Kernel `handle()` returns | Dispatches `RequestHandled` (request + response) [V src-RequestHandled] | `RequestHandled` |
| 15 | Send | `handleRequest()` calls `$response->send()` [V src-Application] | none; time between `RequestHandled` and `Terminating` ~ send time under FPM |
| 16 | Terminate | `Kernel::terminate()`: dispatches `Terminating`, `terminateMiddleware()`, `app->terminate()` callbacks; then runs `whenRequestLifecycleIsLongerThan` handlers; resets `requestStartedAt` [V src-Kernel, src-Application] | `Terminating`, `app->terminating()`, middleware `terminate()` |

Note: `handleRequest()` calls the **kernel's** `terminate($request, $response)`, which in turn calls `app->terminate()` [V src-Application].

---

## 2. Stage detail: bootstrap, providers, middleware, routing

### 2.1 Bootstrap and providers

| Hook | Exact API | Fires | Notes |
|---|---|---|---|
| Per-bootstrapper | `Event::listen('bootstrapping: '.Bootstrapper::class)` / `'bootstrapped: '...`; helpers `Application::beforeBootstrapping($b, $cb)`, `afterBootstrapping($b, $cb)` [V src-Application] | Around each of the 6 HTTP / 7 console bootstrappers | The dispatcher exists only after `RegisterProviders` / early container setup; `LoadEnvironmentVariables` / `LoadConfiguration` events fire before a user package can register a listener. A package cannot observe those, only the bootstrappers that run after its own provider is registered. |
| Provider timing | `$app->booting(cb)`, `$app->booted(cb)`; `register()` / `boot()` of own provider [V src-Application] | `booting` just before first provider `boot()`; `booted` after all | `booted()` runs the callback immediately if already booted. Per-provider boot time is NOT exposed by the framework; to measure it a package would need to wrap `Application::bootProvider` (not public API), so do not. Report aggregate "bootstrap" = (start of `Kernel::handle` -> `booted`). |
| Start of request | `$_SERVER['REQUEST_TIME_FLOAT']` / `LARAVEL_START` constant (defined in default `public/index.php`) [M] | Before anything | Only reliable "HTTP in" timestamp under FPM. Under Octane it is stale (see section 11). Prefer `Kernel::requestStartedAt()` where available [V], but it is set inside `handle()` (after bootstrap start? no: at the top of `handle()`, before `sendRequestThroughRouter` which runs bootstrap). |
| Provider deferral | `ServiceProvider::provides()` + deferred providers [M] | | Not needed for tracing. |

Package registration: a package registers its provider via composer `extra.laravel.providers` auto-discovery; `Application::registerConfiguredProviders` inserts package manifest providers at index 1 after the `Illuminate\` providers [V src-Application]. So the package `boot()` runs after framework providers have booted only if the app has no ordering overrides, and before all app providers that come later in `app.providers`.

### 2.2 Global / group / route middleware

| Hook | API | Notes |
|---|---|---|
| Register a global middleware from a package | `$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class); $kernel->prependMiddleware(X::class);` in provider `boot()` [M] | Laravel 11+ moved app middleware config to `bootstrap/app.php` `->withMiddleware(fn (Middleware $m) => $m->prepend(...))` [V doc-middleware], but packages cannot touch that closure; the `Kernel::prependMiddleware` / `pushMiddleware` methods still exist on the kernel [M, verify in 12.x source before use]. |
| Route-group middleware from a package | `Router::pushMiddlewareToGroup('web', X::class)` / `prependMiddlewareToGroup` [M] | Safe, documented-style router API; use for web/api. |
| Terminable middleware | define `terminate(Request, Response): void` [V doc-middleware] | Laravel resolves a **fresh instance** for `terminate` unless the class is registered as a container `singleton`. So a tracing middleware must hold state in a singleton collector, not on `$this`. Called "after the response is sent" only under FastCGI (FPM); elsewhere it runs inline [V doc-middleware]. |
| Skip flag | container binding `middleware.disable` [V src-Router] | If bound, route middleware is skipped (tests). The Kernel has `shouldSkipMiddleware()` similarly for global [V]. |
| Default web group (12.x) | EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings [V doc-middleware] | Default global stack (12.x): InvokeDeferredCallbacks, TrustProxies, HandleCors, PreventRequestsDuringMaintenance, ValidatePostSize, TrimStrings, ConvertEmptyStringsToNull (TrustHosts commented) [V doc-middleware]. |

Per-middleware timing: the framework dispatches NO event per middleware. Options: (a) one tracing middleware at the front that records "total in middleware before route" using `Routing`/`RouteMatched` as the end marker (timeline: `handle()` start -> `Routing` = global middleware + bootstrap; `RouteMatched` -> controller start = route middleware); (b) decorate the pipeline: not documented API, avoid. Recommendation: stage-level (not per-class) timing from events; optional per-middleware spans by resolving `Router::gatherRouteMiddleware($route)` at `RouteMatched` and listing class names (names only, no timing).

### 2.3 Routing events

| Event class (namespace `Illuminate\Routing\Events`) | Fired where [V src-Router] | Payload |
|---|---|---|
| `Routing` | `Router::findRoute()` before match | `$request` |
| `RouteMatched` | `Router::runRoute()` after `setRouteResolver` | `$route`, `$request` |
| `PreparingResponse` | `prepareResponse()` before `toResponse` | `$request`, `$response` (raw return value) |
| `ResponsePrepared` | after `toResponse` | `$request`, `$response` (Symfony response) |

From `Route`: `getActionName()` returns `$action['controller'] ?? 'Closure'`; `getAction('uses')`; `getController()`; `controllerDispatcher()` -> `Illuminate\Routing\ControllerDispatcher` (or a container-bound contract) [V src-Route]. Use `$route->getActionName()`, `$route->uri()`, `$route->getName()`, `$route->methods()` for trace metadata. For a closure route the declaring file/line can be read via `ReflectionFunction` from `$route->getAction('uses')` (may be a serialized closure: `Route::runCallable` unserializes it [V]).

No event is fired for "controller started / finished". Package options: (1) a route middleware that `$next`s and times the inner part (controller + route middleware inner stack); (2) approximate with `RouteMatched` -> `PreparingResponse` delta (= route middleware pre + controller + middleware post up to first `prepareResponse`).

---

## 3. Database layer

| Concern | Hook | Detail |
|---|---|---|
| Every query | `DB::listen(Closure(QueryExecuted))` -> `Connection::listen` registers on `QueryExecuted::class` via the dispatcher [V src-Connection, doc-database] | `QueryExecuted` public props: `$sql`, `$bindings`, `$time` (ms float), `$connection`, `$connectionName`, `$readWriteType` (`null`/`read`/`write`); method `toRawSql()` [V src-QueryExecuted]. |
| Per-connection, before exec | `$connection->beforeExecuting(Closure($query, $bindings, $connection))` [V src-Connection] | Called inside `run()` first, before reconnect and timing. Good place to capture call site **before** the PDO call (same stack depth as `QueryExecuted`, but gives "intent" even if the query throws). Documented in source; the 12.x database doc page does not describe it [V doc-database]. Callbacks are per connection instance and not cleared by Octane; register once. |
| Failed queries | `QueryException` thrown from `runQueryCallback`; `logQuery` is only reached on success [V src-Connection] | A failing query dispatches **no** `QueryExecuted`. To record failures use `beforeExecuting` (record intent) + exception reporting hook (`ExceptionHandler::reportable`) or `Event::listen` on `MessageLogged` [M]. |
| Cumulative DB time | `DB::whenQueryingForLongerThan($ms, fn(Connection, QueryExecuted))`; `$connection->totalQueryDuration()`; `resetTotalQueryDuration()`; `allowQueryDurationHandlersToRunAgain()` [V src-Connection, doc-database] | Handler fires once, the first time cumulative time passes threshold. Cumulative counter lives on the connection, i.e. **persists across requests under Octane / queue workers** unless reset. Call `resetTotalQueryDuration()` at request/job start for per-request totals. |
| Query log | `enableQueryLog()`, `getQueryLog()`, `flushQueryLog()` [V src-Connection: `enableQueryLog`, `logQuery` appends only when logging on] | **Do not use**: unbounded memory growth in long-lived workers and it stores everything. Use own bounded collector. |
| Statement prepared | `StatementPrepared` event (`$connection`, `$statement`) dispatched from `prepared()` [V src-Connection] | Lets a package change fetch mode; not needed for tracing. |
| Transactions | `TransactionBeginning`, `TransactionCommitting`, `TransactionCommitted`, `TransactionRolledBack` (all `Illuminate\Database\Events`, ctor takes the connection) via `fireConnectionEvent` [V src-Connection] | Use to mark transaction spans; `$connection->transactionLevel()` [M]. Also `ShouldDispatchAfterCommit` events are deferred until commit [V src-Dispatcher]. |
| Pool health | `DatabaseBusy` (from `db:monitor`) [V doc-database] | Not a request-level signal. |
| Read/write split + sticky | `readWriteType` on `QueryExecuted`; `sticky` makes reads use the write PDO after a write in the same request [V doc-database] | Record `readWriteType` in the trace. |

`QueryExecuted` is dispatched from `logQuery()` through the container event dispatcher. `logQuery` runs **synchronously inside `Connection::run`**, so a call-site capture inside the listener sees the frames: `...userland... -> Eloquent\Builder / Query\Builder -> Connection::select -> Connection::run -> Connection::logQuery -> Dispatcher::dispatch -> listener` [V src-Connection call chain; exact frame count [M]].

### 3.1 Detecting slow, duplicate, N+1 queries (query level)

All three can be computed purely from `QueryExecuted` + call site, without touching Eloquent:

| Detection | Algorithm |
|---|---|
| Slow | `$event->time >= threshold_ms` (per-query) and cumulative via `whenQueryingForLongerThan`. |
| Duplicate | key = `connectionName + sql + serialized(bindings)`; count > 1 within one request. Exclude `readWriteType === 'write'`. |
| N+1 (heuristic) | key = `connectionName + normalized sql (bindings stripped, `in (?, ?, ?)` collapsed) + call-site (file:line of first non-vendor frame)`; count >= N (typical 5-10) within one request, differing bindings. Same SQL shape fired repeatedly from the same app line = N+1 candidate. |
| Hint for fix | The N+1 shape `select * from x where x.id = ?` (belongsTo/hasOne lazy) or `... where y.fk = ?` (hasMany) tells you the relation kind; eager loads look like `where id in (?, ?, ...)` [V doc-relations example SQL]. |

### 3.2 Relation-level lazy loading (exact classes/methods) [V]

| Piece | Location | Behavior |
|---|---|---|
| Switch | `Model::preventLazyLoading(bool $value = true)` sets static `$modelsShouldPreventLazyLoading`; `Model::preventsLazyLoading()` getter [V src-Model] | Documented to be called in `AppServiceProvider::boot()`, typically `! app()->isProduction()` [V doc-relations]. |
| Strict bundle | `Model::shouldBeStrict(bool $shouldBeStrict = true)` calls `preventLazyLoading`, `preventSilentlyDiscardingAttributes`, `preventAccessingMissingAttributes` [V src-Model] | Its three components can each be toggled separately. |
| Custom handler | `Model::handleLazyLoadingViolationUsing(?callable $callback)` stores static `$lazyLoadingViolationCallback`; callback signature `(Model $model, string $relation)` [V src-Model, doc-relations]. (The docs prose names it `handleLazyLoadingViolationsUsing` once; the method is **`handleLazyLoadingViolationUsing`** per source and the doc code sample.) | **Single global callback slot**: a package that sets it overwrites the app's own handler. Wrap rather than replace (see below). |
| Per-instance flag | In `Eloquent\Builder::hydrate()`: `$model->preventsLazyLoading = Model::preventsLazyLoading();` and it is applied **only when the result set has more than one model** [V src-Eloquent Builder, summary of tool output]. | Consequence: a single-row `first()` / `find()` never triggers a violation (not an N+1 source anyway). Models built via `new`, `create`, `factory()` do not get the flag. |
| Access path | `HasAttributes::getRelationValue($key)`: (1) if `relationLoaded($key)` return it; (2) `attemptToAutoloadRelation($key)` (12.x automatic eager loading); (3) `if ($this->preventsLazyLoading) $this->handleLazyLoadingViolation($key)`; (4) `getRelationshipFromMethod($key)` [V src-HasAttributes]. |
| Violation | `handleLazyLoadingViolation($key)`: if custom callback set -> `call_user_func($cb, $this, $key)` and **return its result** (query then proceeds? No: after a callback returns, `getRelationValue` falls through to `getRelationshipFromMethod`, so the lazy load still executes); else skip if `! $this->exists || $this->wasRecentlyCreated`; else `throw new LazyLoadingViolationException($this, $key)` [V src-HasAttributes]. |
| Exception | `Illuminate\Database\LazyLoadingViolationException extends RuntimeException`, public `$model` (class name string), `$relation` [V src-LazyLoadingViolationException]. |
| Actual query | `getRelationshipFromMethod()` calls the relation method, `$relation->getResults()`, then `setRelation()` [V]. The query that follows still emits `QueryExecuted`. |

Important gap for a package: the lazy loading flag only fires when the app enabled `preventLazyLoading`. The package should NOT require that. Options:

1. **Passive (recommended default)**: query-level N+1 heuristic (3.1) plus, if the app has not set a callback, the package may enable it in non-production: `Model::preventLazyLoading()` + `Model::handleLazyLoadingViolationUsing(...)` that records `(get_class($model), $relation, call site)` into the collector and then **does not throw** (the load proceeds). Provide config `lantern.lazy_loading.mode = off|record|inherit`. In `inherit` the package wraps any pre-existing handler: it cannot read `Model::$lazyLoadingViolationCallback` (protected static) without reflection, so the safest approach is to document "set yours through Lantern" or read via `ReflectionProperty` once at boot [M].
2. **Per-relation attribution without enabling the global switch**: listen to the `retrieved` Eloquent event [V doc-eloquent lists `retrieved`] to count hydrated models per class per call site; combine with query-level repetition to name the model class.
3. `Model::automaticallyEagerLoadRelationships()` (beta in 12.x docs) [V doc-relations]: static `$modelsShouldAutomaticallyEagerLoadRelationships`; `Collection::withRelationshipAutoloading()` per collection; `attemptToAutoloadRelation` runs **before** the lazy-loading check, so with auto-eager-load on, lazy-loading violations do not fire for relations that were auto-loaded [V src-HasAttributes ordering]. A tracer should record "auto eager loaded" separately if the app enabled it (it removes N+1 but still costs a query). Docs say behavior may change on patch releases [V doc-relations].
4. Other strictness flags worth recording: `preventSilentlyDiscardingAttributes`, `preventAccessingMissingAttributes` (violation callback `$missingAttributeViolationCallback`, exception `MissingAttributeException`) [V src-HasAttributes].

---

## 4. Eloquent model events

| Hook | Detail |
|---|---|
| Lifecycle events | `retrieved, creating, created, updating, updated, saving, saved, deleting, deleted, trashed, forceDeleting, forceDeleted, restoring, restored, replicating` [V doc-eloquent: first six names confirmed in the intro sentence; remainder [M]]. `retrieved` fires when an existing model is hydrated from the DB [V doc-eloquent]. |
| Wildcard observation | Model events are plain dispatcher events named like `eloquent.<event>: <FQCN>` (e.g. `eloquent.retrieved: App\Models\User`) with the model as payload [M]. A wildcard `Event::listen('eloquent.*', fn (string $name, array $payload) => ...)` observes all models [V wildcard signature in src-Dispatcher / doc-events; name format [M]]. |
| Cost | `retrieved` fires **once per hydrated model**: a 10k-row query = 10k dispatches. Do not do work per event beyond an integer increment keyed by class. Use `Event::listen` with a class-keyed counter, not a trace span. |
| Quiet / muting | `Model::withoutEvents(fn)`, `saveQuietly()`, `pushQuietly()`, `createQuietly()` suppress events [V doc-eloquent, doc-relations]. Counters will undercount in those paths. |
| Bulk ops | `Model::query()->update()/delete()`, `insert()`, `upsert()` do not fire model events [M] (they still emit `QueryExecuted`). |
| Pruning | Prunable models and `model:prune`: `Illuminate\Database\Events\ModelsPruned` event (`$model` class, `$count`) [M, not verified this session; the doc-eloquent "Pruning Models" section exists]. Console-only, no relevance to request traces; include only for artisan command traces. |
| Memory | Memory growth is dominated by hydrated models and loaded relations. Large result sets: `chunk`, `chunkById`, `lazy`, `cursor` are documented in doc-eloquent (cursor hydrates one at a time and uses `cursor()->map` [V src-Eloquent Builder]). A package can flag "N models hydrated in one request > threshold" from the `retrieved` counter. |

---

## 5. Other instrumentable subsystems

| Subsystem | Hooks [all V unless marked] | Notes |
|---|---|---|
| Cache | Events `Illuminate\Cache\Events\{CacheFlushed, CacheFlushing, CacheHit, CacheMissed, ForgettingKey, KeyForgetFailed, KeyForgotten, KeyWriteFailed, KeyWritten, RetrievingKey, RetrievingManyKeys, WritingKey, WritingManyKeys}` [doc-cache]. `CacheHit` has `$value`, `$key`, `$storeName`, `$tags` [src CacheHit]. Per store `'events' => false` in `config/cache.php` disables them [doc-cache]. | If an app disables events for performance, Lantern cache spans are missing; document it. Also `CacheFailedOver` for the `failover` driver [doc-cache]. `Cache::memo()` memoizes per request/job and avoids repeat hits [doc-cache]; hits served from memo emit no store event. |
| Outbound HTTP | `Illuminate\Http\Client\Events\{RequestSending, ResponseReceived, ConnectionFailed}` [doc-http-client]; `RequestSending` and `ConnectionFailed` carry public `$request`; `ResponseReceived` carries request + response. `Http::globalMiddleware()`, `globalRequestMiddleware()`, `globalResponseMiddleware()` on the factory [src-HttpClient Factory]. | Time the span between `RequestSending` and `ResponseReceived`/`ConnectionFailed` keyed by `spl_object_id($event->request)` [M]. Events fire only if the factory has a dispatcher (it does in the app). Pooled/concurrent requests interleave, so key by request object. |
| Events (the app's own) | `Event::listen('*', fn (string $eventName, array $payload))` wildcard [V doc-events: `Event::listen('event.*', function (string $eventName, array $data)`; src-Dispatcher: wildcard listeners get `($eventName, array $payload)`, object events are wrapped as `[$event]`]. | **Overhead warning**: `setupWildcardListen` clears `wildcardsCache` whenever a wildcard is added; `getListeners` consults the per-event-name cache [V]. A `'*'` listener runs for **every** dispatch in the process (including every `eloquent.retrieved`, view composing events, cache events, `bootstrapping:*`). Cost is small per call but multiplied by thousands; keep the listener O(1) and filter by early `str_starts_with`/class checks. Prefer narrow wildcards or explicit event classes. Also `Dispatcher::push/flush` use `_pushed` pseudo events [V]. `ShouldDispatchAfterCommit` events are deferred via transaction manager [V]. |
| Logging | `Log::withContext()`, `Log::shareContext()` [V doc-logging]; Monolog `processors` (e.g. `MemoryUsageProcessor`) configurable per channel [V doc-logging]; `tap` classes receive `Illuminate\Log\Logger` [V]. `Illuminate\Log\Events\MessageLogged` event (level, message, context) [M, not fetched]. | Use `MessageLogged` to attach log lines to the active trace. Beware recursion: do not log inside the listener. |
| Queue dispatch | `Illuminate\Queue\Events\JobQueueing` / `JobQueued` [M: `JobQueueing` was NOT found in `Worker.php`, which is expected as it is raised by `Queue` not `Worker`]; `Queue::createPayloadUsing` [M] to inject trace id into payload. | To link HTTP trace to the job trace, inject a trace id into the payload and read it in `JobProcessing`. Not verified, confirm in `Illuminate\Queue\Queue.php`. |
| Deferred functions (11.x+) | `defer()` helper and `InvokeDeferredCallbacks` global middleware (in the 12.x default global stack) [V doc-middleware, doc-cache mentions deferred function]. | Deferred callbacks run at terminate. Queries run in them are attributed to "terminate" phase. |

---

## 6. Validation [V doc-validation]

- Failures surface as `Illuminate\Validation\ValidationException`; web -> redirect with flashed errors, XHR -> 422 JSON. FormRequests validate **before** the controller method runs, with `authorize()` returning `false` -> 403 [V].
- Hooks: FormRequest `prepareForValidation()`, `passedValidation()`, `after(): array`, `stopOnFirstFailure`; manual `$validator->after()` [V].
- **No dedicated validation events** documented. Observation options: (a) record `ValidationException` in the exception reporting path (`ExceptionHandler::reportable` / `dontReport` interplay: `ValidationException` is in the default dont-report list, so use `$exceptions->render()` / a `renderable` or listen at `RequestHandled` and inspect `$response->exception` [M]); (b) a FormRequest base trait (invasive, not a package default); (c) container `resolving` callback for `Illuminate\Foundation\Http\FormRequest` to time form request construction/validation: `$app->afterResolving(FormRequest::class, ...)` fires after the request is built but `validateResolved()` runs in the `ValidatesWhenResolved` trait after that [M]. Recommend (a) for the failed-fields summary, plus a `Validator::resolver` wrapper [M] only if timing is needed.

---

## 7. Views / Blade

| Hook | API | Detail |
|---|---|---|
| Creator | `View::creator($views, $cb)` -> event `creating: <name>`; fired from `Factory::make()` via `callCreator($view)` (and from `file()`) [V src-ManagesEvents, src-View Factory] | Fires when the `View` object is **made**, before data is rendered. |
| Composer | `View::composer($views, $cb)` -> event `composing: <name>`; `View::renderContents()` calls `callComposer` after `incrementRender()` and before `getContents()` [V src-View] | Fires at render start. Listeners exist only if `hasListeners('composing: '.$view->name())`, i.e. a **wildcard** composer like `View::composer('*', ...)` is required to see all views; wildcard listeners here are wrapped to receive the view as `$data[0]` [V src-ManagesEvents]. |
| End of render | None. `View::render()` -> `flushStateIfDoneRendering` after contents [V src-View]. `Factory::incrementRender/decrementRender`, `renderCount` track nesting [V src-View Factory]. | Compute span end by wrapping: a package can register a **custom engine decorator** (`View::getEngineResolver()->register('blade', fn () => new TracingCompilerEngine(...))` [M]) to time `CompilerEngine::get($path, $data)`. This is the only cheap place that gives a precise "end" and includes nested includes/components. Verify `EngineResolver::register` in 12.x. |
| Compiled vs source path | `CompilerEngine::get($path)` receives the **source** `.blade.php` path; evaluates `compiler->getCompiledPath($path)`; keeps a `lastCompiled` stack of source paths for error messages; `$compiledOrNotExpired[$path]` cache [V src-CompilerEngine]. | So `View::getPath()` / the `$path` in the engine is the source path: good for traces. Blade exception handling maps to source via `ViewException` with `(View: realpath(last($this->lastCompiled)))` [V]. |
| Data | `View::gatherData()` merges `Factory::getShared()` + view data, then renders `Renderable` values [V src-View] | Never store view data in traces (PII, size). Store `array_keys` only. |
| Cost | Listener per `composing: *` per view render; Blade components/`@include` in loops can render thousands of views per request (e.g. rows). Aggregate by view name (count + total ms), do not create one span per render beyond a cap. |

Lazy loads and queries triggered inside templates show up as normal `QueryExecuted` events; the call-site resolver should walk past `storage/framework/views/*.php` compiled frames: map compiled path back to source using the `lastCompiled` approach (the compiled file header contains `/**PATH ... ENDPATH**/` marker in 12.x [M]) or by ignoring compiled frames and reporting the view name from the active view stack (own stack via `creating`/`composing`).

---

## 8. Response, terminate, exceptions

| Hook | Detail |
|---|---|
| `RequestHandled` | `Illuminate\Foundation\Http\Events\RequestHandled` props `$request`, `$response` [V src-RequestHandled]. Fired **always** at the end of `handle()`, including error responses rendered by the exception handler [V src-Kernel]. Best point to finish "request" span, read status code, response size (`strlen($response->getContent())` costs memory for large/streamed responses; prefer `Content-Length` header; do not read content of `StreamedResponse`/`BinaryFileResponse`). |
| `Terminating` | `Illuminate\Foundation\Events\Terminating` dispatched first thing in `Kernel::terminate()` (both HTTP and console kernels) [V src-Kernel x2]. |
| `app->terminating(cb)` | Callback list run by `Application::terminate()`; loop re-checks count so callbacks added during termination also run [V src-Application]. Use this as the **last** hook to persist the trace (put persistence into `terminating`, after middleware `terminate()`). |
| Slow request hook | `Kernel::whenRequestLifecycleIsLongerThan($threshold, $handler)` (ms int, `DateTimeInterface`, or `CarbonInterval`); evaluated in `terminate()`; also console `whenCommandLifecycleIsLongerThan` [V src-Kernel x2]. Can be registered via `app(Kernel::class)->whenRequestLifecycleIsLongerThan(...)` from a provider. Uses `requestStartedAt`, set at top of `handle()` (excludes `public/index.php` autoload time). |
| Exceptions | `Kernel::handle()` catches `Throwable`, calls `ExceptionHandler::report()` then `render()` [V src-Kernel]. Hook: `ExceptionHandler::reportable(fn (Throwable $e) => ...)->stop()` [M], or listen to `MessageLogged` for reported exceptions. Exceptions swallowed/rendered by middleware are not reported. |
| After-response persist | Under FPM, `fastcgi_finish_request()` runs inside `Response::send()` (Symfony) so work in `Terminating`/`terminate` happens after the client has the response [V doc-middleware, FastCGI requirement]. Under Octane/RoadRunner/Swoole/FrankenPHP, "after response" work still runs inline in the worker before it takes the next request (see section 11 for events). |
| `defer()` | Deferred callbacks run via `InvokeDeferredCallbacks` global middleware at terminate [V doc-middleware list]. |

---

## 9. Call-site capture (file:line)

### 9.1 `debug_backtrace` options [M: PHP manual not fetched, widely documented; confirm at https://www.php.net/debug_backtrace]

`debug_backtrace(int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT, int $limit = 0): array`

| Option | Effect on cost |
|---|---|
| `DEBUG_BACKTRACE_IGNORE_ARGS` | **Always set.** Skips copying args: big memory/time saving, avoids retaining big objects and secrets. |
| `DEBUG_BACKTRACE_PROVIDE_OBJECT` (default) | Includes `object` key: **do not use** (holds references to models/containers; leak and cost). Passing only `IGNORE_ARGS` clears it. |
| `$limit` | Cap frames (e.g. 25-50). Walk until first app frame; most queries hit within 10-20 frames. Too small a limit misses call sites behind deep vendor stacks (Eloquent chains are ~8-15 frames; collections `each`/`map` add more). |

Recommended call: `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40)`.

Alternatives: `(new \Exception)->getTrace()` is not cheaper (it still builds frames, includes args depending on `zend.exception_ignore_args` ini; default prod ini `zend.exception_ignore_args=On`) [M]. `debug_print_backtrace` is for output. There is no PHP-level API to read only the caller's file/line without building frames.

### 9.2 Filtering vendor frames

Each frame has `file`, `line`, `function`, `class` (all optional; `file`/`line` are absent for internal-function callbacks such as `array_map` and `Closure::call`). Algorithm:

1. Precompute `base = realpath(base_path())` and `vendor = base.'/vendor/'`, plus the package's own directory (skip frames from `Lantern\`).
2. Iterate frames. A frame's `file`/`line` is the **location of the call made from that frame** (i.e. the line in `file` that invoked `function`). So the first frame whose `file` is outside `vendor/` and not Lantern and not compiled view cache is the app code line that triggered the query: report `file:line` and `class::function` of that frame (note: `class`/`function` describe the *callee*; the caller's function is in the **next** frame).
3. Skip `storage/framework/views/` compiled frames, and optionally record the originating Blade view from your own view stack (section 7) because the compiled line number maps imperfectly to the template.
4. Treat `app/` as "first-party" via config (`lantern.app_paths`), fall back to "first non-vendor". Support `Illuminate\Support\Collection` callbacks: the first non-vendor frame is the closure inside the app file, correct.
5. Cache per `(file)` the vendor decision in a static array to avoid repeated `str_starts_with` on the same files; cache per request the **resolved call site per backtrace signature** is not possible (signature = the backtrace), but a hot path guard is: only capture when the query counter exceeds a sampling rule (e.g. always capture the first 200 queries, afterwards 1 in N), since per-call cost is ~microseconds-to-tens-of-microseconds (order of magnitude, [M], benchmark it).
6. Octane: same logic, `base_path()` is stable.

Where to call it: **inside the `QueryExecuted` listener** (the listener frames are `Dispatcher::dispatch`, `Connection::logQuery`, `Connection::run`: all vendor, skipped automatically) or inside `beforeExecuting` (earlier in `run()`, same effect, and works even if the query throws) [V call chain from src-Connection: `run()` calls `beforeExecuting` callbacks first and `logQuery()` last].

For lazy loading violations: capture in the `handleLazyLoadingViolationUsing` callback; the first non-vendor frame is the property access `$model->relation` line (via `Model::__get` -> `getAttribute` -> `getRelationValue` [V src-Model `__get` returns `getAttribute`]). Same filter works.

For Blade templates, the property access happens in the compiled PHP file; the reported call site will be `storage/framework/views/<hash>.php:LINE`. Map it to source by (a) your own view stack name, or (b) reading the `/**PATH <source> ENDPATH**/` footer of the compiled file [M] once per compiled file (cache).

---

## 10. Memory and timing

| Metric | API | Notes |
|---|---|---|
| Peak per request | `memory_get_peak_usage(bool $real_usage = false)` [M, php.net] | `false` = bytes allocated through the Zend MM as used; `true` = bytes from the system (chunks of 2 MB). Peak is process-lifetime on FPM = request-lifetime; **under Octane/queue workers the peak is per worker lifetime**, not per request. Use `memory_reset_peak_usage()` (PHP 8.2+) [M] at request start; Laravel 12 supports PHP 8.2-8.5 [V doc-releases], so it is available on all supported versions. |
| Current | `memory_get_usage()` | Take at stage boundaries (bootstrap done, RouteMatched, RequestHandled, Terminating); growth per stage = delta. Cheap (a counter read). |
| Queue worker limit check | Worker uses `memory_get_usage(true) / 1024 / 1024 >= $limit` for `--memory` [V src-Queue Worker] | Mirror this unit if reporting "close to worker limit". |
| Timing | `hrtime(true)` (monotonic ns) for spans; `microtime(true)` for wall timestamps. Laravel itself uses `microtime(true)` in `Connection::run` and Carbon `now()` in kernel [V src-Connection, src-Kernel]. | Use `hrtime` for durations; do not use `Carbon::now()` in hot paths (alloc). |
| Per-stage timing recipe | t0 = request start (see 2.1) ; `booted` callback ; `Routing` ; `RouteMatched` ; (optional route middleware wrapper) ; `PreparingResponse` first ; `RequestHandled` ; `Terminating` ; end of `app->terminating` persistence | Stage durations = deltas between consecutive markers. Memory snapshot at the same markers. |
| Memory growth detection | delta between `RouteMatched` and `RequestHandled` plus count of hydrated models (`retrieved` counter) and query rows; **under Octane** track `memory_get_usage()` at `RequestReceived` vs `RequestTerminated` across requests per worker to see leaks (docs warn about static arrays [V doc-octane "Managing Memory Leaks"]). | Report slope over N requests, not single values. |
| GC | `gc_collect_cycles()` is run by Octane's optional `CollectGarbage` listener (`'garbage' => 50`) [V src-octane config]. | Peak numbers fluctuate after GC. |

---

## 11. Octane (long-lived workers) [V doc-octane, src-octane config unless marked]

Octane boots the app once per worker; `register()` / `boot()` of providers run once; the same app instance serves subsequent requests (Octane clones a "sandbox" per request).

| Event (`Laravel\Octane\Events\*`) | Default listeners (from config) | Use for tracing |
|---|---|---|
| `WorkerStarting` | `EnsureUploadedFilesAreValid`, `EnsureUploadedFilesCanBeMoved` | Mark worker boot; reset worker-level counters. |
| `RequestReceived` (`$app`, `$sandbox`, `$request`) [V src-octane RequestReceived] | `...Octane::prepareApplicationForNextOperation()`, `...Octane::prepareApplicationForNextRequest()` | Request start timestamp for Octane (replaces `REQUEST_TIME_FLOAT`). Reset per-request collectors here (or in a Lantern middleware at the very front). |
| `RequestHandled` (Octane's own, distinct class from Foundation's) | none by default | Response ready. |
| `RequestTerminated` | `// FlushUploadedFiles::class` (commented) | Persist trace. Octane "terminate" runs here, per request. |
| `TaskReceived`, `TaskTerminated`, `TickReceived`, `TickTerminated` | `prepareApplicationForNextOperation()` on received | Swoole tasks/ticks are NOT HTTP requests; tag trace type accordingly. |
| `OperationTerminated` | `FlushTemporaryContainerInstances` (`DisconnectFromDatabases`, `CollectGarbage` commented) | Fires after request/task/tick; last cleanup. |
| `WorkerErrorOccurred` | `ReportException`, `StopWorkerIfNecessary` | Record worker-level failures. |
| `WorkerStopping` | none | Flush buffered traces on worker exit. |

(There is no event named `ServingOctane`; the event list above is from the shipped config.)

Safety rules for Lantern under Octane [V doc-octane]:
- Never keep a per-request collector in a plain singleton that is not reset: use `RequestReceived` (or front middleware) to `reset()` it, or bind it so `FlushTemporaryContainerInstances`/`flush` config handles it. Octane resets first-party framework state only; "Octane does not always know how to reset the global state created by your application" [V].
- Do not inject `$app`, `$request`, or `config` repo into singletons at provider time (stale after first request) [V doc-octane: Container/Request/Configuration Repository Injection]. Use `fn () => Container::getInstance()` / `request()` helpers.
- Static arrays that grow = leak [V doc-octane]. Collector buffers must be per-request, bounded (hard caps on spans, queries, bindings), and cleared at `RequestTerminated`.
- `DB::listen` / `beforeExecuting` are registered once at boot on the connection; because listeners live on connection objects that persist, do **not** re-register per request (listener leak, double counting). Reset only the collector.
- `Connection::totalQueryDuration()` and `queryLog` persist across requests; call `resetTotalQueryDuration()` / `allowQueryDurationHandlersToRunAgain()` at request start [V src-Connection].
- `Kernel::requestStartedAt` is reset in `terminate()` [V], so Foundation's slow-request hook still works per request if `terminate()` is called by Octane [M, Octane calls kernel terminate: verify].
- Worker restarts after `--max-requests` (default 500) and `max_execution_time` 30 s [V doc-octane].
- Persisting traces: do I/O at `RequestTerminated` (after response sent) to avoid latency, but it blocks that worker, so batch/buffer and use a queue/async sink.
- `Octane::concurrently` tasks (Swoole) run in other processes: DB listeners and collectors in the task process are separate [V doc-octane].

---

## 12. Queue worker lifecycle [V unless marked]

| Stage | Hook | Source |
|---|---|---|
| Worker boot | `WorkerStarting` event (`$connectionName`, `$queue`, `$options`) dispatched at daemon start [V src-Queue Worker] | Illuminate\Queue\Events |
| Loop tick | `resetScope` callable invoked at top of each daemon iteration (`($this->resetScope)()`); `Looping` event via `$events->until(new Looping(...))` returning `false` pauses the worker [V src-Queue Worker] | `resetScope` is injected by the framework and resets scoped bindings (clears `scoped()` container instances) [M]. |
| Job start | `JobProcessing` (`Queue::before(fn (JobProcessing $e))`) [V doc-queues] | `process()` dispatches `JobProcessing`, calls `$job->fire()`, dispatches `JobProcessed` [V src-Queue Worker] |
| Job end | `JobProcessed` (`Queue::after`) [V doc-queues] | |
| Job failure | `JobExceptionOccurred` (per attempt exception), `JobFailed` (`Queue::failing(fn (JobFailed $e))`) [V doc-queues, src-Queue Worker `raiseExceptionOccurredJobEvent`], `JobTimedOut` (SIGALRM handler) [V] | `JobFailed` not found in `Worker.php` since it is raised by `FailingJob`/job classes [M]. |
| Worker stop | `WorkerStopping` (`$status`, `$options`, `$reason`) from `stop()` / `kill()`; reasons include `MaxMemoryExceeded` (exit code 12), `MaxJobsExceeded`, `MaxTimeExceeded` [V src-Queue Worker] | Flush buffers here. |
| Batch | `Bus::batch()->before/progress/then/catch/finally` callbacks (serialized, no `$this`) [V doc-queues] | |
| Serialization | Models serialized as identifiers, re-fetched on run; relations restored unless `withoutRelations`/`#[WithoutRelations]`; collections of models do not restore relations [V doc-queues] | Each model re-fetch is a query at job start (`SerializesModels`); show them under "job hydrate". |
| Worker state | "queue workers are long-lived processes and store the booted application state in memory", so they do not notice code changes; use `queue:restart` on deploy; `--max-jobs`, `--max-time` options [V doc-queues] | |

Tracing recipe for a job: start on `JobProcessing` (job class from `$e->job->resolveName()` [M], attempts `$e->job->attempts()` [M]), end on `JobProcessed` / `JobFailed`. Memory: `memory_get_usage()` at start and end; reset per-job collector at `JobProcessing`; reset DB totals; persist at `JobProcessed`/`JobFailed` (not in `app->terminating`: the worker process keeps going and `Terminating` is not dispatched per job unless run via `queue:work --once`/sync driver; verify) [M].
`sync` queue driver: job runs inside the web request: JobProcessing/JobProcessed fire within the request trace, nest them as spans.
Horizon: out of scope (not part of the official-source list), but it uses the same worker events.

---

## 13. Artisan / console lifecycle [V src-Kernel console]

| Stage | Hook |
|---|---|
| Entry | `Application::handleCommand($input)`: resolves console kernel, `handle($input, new ConsoleOutput)`, then kernel `terminate($input, $status)` [V src-Application] |
| Kernel `handle` | sets `commandStartedAt`, bootstraps, runs Artisan; for `env:encrypt`/`env:decrypt` bootstraps without booting providers [V] |
| Bootstrappers (console) | LoadEnvironmentVariables, LoadConfiguration, HandleExceptions, RegisterFacades, **SetRequestForConsole**, RegisterProviders, BootProviders [V] |
| Command events | `Illuminate\Console\Events\CommandStarting` (command, input, output) and `CommandFinished` (+ exit code), re-routed from Symfony `ConsoleEvents::COMMAND` / `TERMINATE` through a Symfony `EventDispatcher` installed in `rerouteSymfonyCommandEvents()`, which is **skipped when running unit tests** [V src-Kernel console] |
| Terminate | `Terminating` event, `app->terminate()`, then `whenCommandLifecycleIsLongerThan` handlers; `commandStartedAt` reset [V] |
| Long-running commands | `queue:work`, `schedule:work`, `octane:start`, `horizon`: never "finish"; use per-unit events (jobs, scheduled tasks) not `CommandFinished` |
| Scheduler | `Illuminate\Console\Events\ScheduledTaskStarting/Finished/Failed/Skipped` [M, not fetched] |
| Context | `runningInConsole()` caches `APP_RUNNING_IN_CONSOLE` or `cli`/`phpdbg` SAPI [V src-Application]; `runningUnitTests()` true when env = testing [V]. Gate HTTP-only collectors on `! app()->runningInConsole()`; under Octane `runningInConsole()` is true for the server process but requests are still HTTP: check for the Octane request event or a bound `request` rather than `runningInConsole()` [M]. |

---

## 14. Laravel 11 vs 12 differences that matter here

| Topic | 11.x | 12.x | Source |
|---|---|---|---|
| Release / PHP | Mar 12 2024, PHP 8.2-8.4 | Feb 24 2025, PHP 8.2-8.5; bug fixes until Aug 13 2026, security until Feb 24 2027 | [V doc-releases] |
| Nature of 12 | n/a | "Minimal breaking changes", maintenance release (dependency upgrades, new starter kits) | [V doc-releases] |
| Bootstrap/middleware config | Slim skeleton: middleware, routes, exceptions configured in `bootstrap/app.php` (`withMiddleware`, `withExceptions`); HTTP kernel class no longer in app | Same | [V doc-middleware, doc-lifecycle]; consequence: a package cannot edit an app Kernel file; use provider-time router/kernel APIs |
| Entry call | `$app->handleRequest(Request::capture())` | same; lifecycle doc names `handleRequest`/`handleCommand` | [V doc-lifecycle, src-Application] |
| Provider list | `bootstrap/providers.php` | same | [V doc-lifecycle] |
| Query-time hooks | `DB::whenQueryingForLongerThan`, `Connection::beforeExecuting`, `QueryExecuted::toRawSql()` exist (11.x added `toRawSql` and cumulative-time hook) | same | [V src-Connection, src-QueryExecuted]; availability in 11.x specifically [M] |
| Automatic eager loading | not present | `Model::automaticallyEagerLoadRelationships()` and `Collection::withRelationshipAutoloading()`, flagged **beta** in the 12.x docs; its branch sits **before** the lazy-loading check in `getRelationValue` | [V doc-relations, src-HasAttributes]; whether it first shipped in a 12.x minor [M] |
| Request lifecycle hooks | `Kernel::whenRequestLifecycleIsLongerThan` | same (plus console equivalent) | [V src-Kernel]; 11 availability [M] |
| Deferred functions | `defer()` introduced in 11.x with `InvokeDeferredCallbacks` | in default global stack in 12.x docs | [V doc-middleware]; introduction version [M] |
| Octane | same | same events | [V config] |
| Cache events | fewer events (no `RetrievingKey`/`WritingKey`) [M] | 13 event classes incl. `RetrievingKey`, `WritingKey`, `*ManyKeys`, `KeyForgetFailed`, `KeyWriteFailed` | [V doc-cache 12.x]; 11 list [M, verify before supporting 11 with these events] |
| Cache memo | n/a | `Cache::memo()` documented | [V doc-cache] |
| Docs note | | The 12.x site says 13.x exists (Q1 2026); the doc excerpts show `now()->plus(...)` Carbon-style helpers and `Pdo\Mysql` for PHP 8.5, i.e. the fetched 12.x pages are current to a late 12.x minor | [V] |

Practical stance: support 11.x and 12.x with the same code path and feature-detect optional hooks (`method_exists(Connection::class, 'beforeExecuting')`, `class_exists(RetrievingKey::class)`, `method_exists(Model::class, 'automaticallyEagerLoadRelationships')`). Do not branch on version numbers.

---

## 15. Hook summary table (what Lantern should subscribe to)

| Stage / signal | Hook | Kind | Overhead | Octane/queue/console |
|---|---|---|---|---|
| Request start | front global middleware + `Kernel::requestStartedAt()` / Octane `RequestReceived` | middleware / event | low | reset collector per request |
| Bootstrap done | `$app->booted()` | callback | none | once per worker under Octane |
| Route identity | `RouteMatched` (`$route`, `$request`) | event | low | per request |
| Routing time | `Routing` .. `RouteMatched` | events | low | |
| Controller time | route middleware wrapper or `RouteMatched`..`PreparingResponse` | middleware / event | low | |
| Queries | `DB::listen` / `Connection::beforeExecuting` | callback | per query: backtrace dominates | register once, do not re-register |
| Cumulative DB | `totalQueryDuration()` + reset at start | method | none | **must reset per request/job** |
| Lazy loading | `Model::handleLazyLoadingViolationUsing` (+ `preventLazyLoading`) | static callback (single slot) | only on violation | global static, set once |
| Hydration counts | `Event::listen('eloquent.retrieved: *')` [name format M] | event | per model | per request counters |
| Transactions | `TransactionBeginning/Committed/RolledBack` | events | low | |
| Cache | `CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten` ... | events | per op; store may disable events | |
| Outbound HTTP | `RequestSending`, `ResponseReceived`, `ConnectionFailed` | events | per call | |
| Views | wildcard `View::composer('*')` / `View::creator('*')` or engine decorator | events | per view render | |
| Logs | `MessageLogged` [M] | event | per log | avoid recursion |
| Exceptions | `ExceptionHandler::reportable`, `RequestHandled` (`$response->exception`) | callback | none | |
| Response done | Foundation `RequestHandled` / Octane `RequestTerminated` | event | none | |
| Persist | `app->terminating()` (HTTP/console) ; `JobProcessed/JobFailed` (queue) ; `RequestTerminated` (Octane) ; `WorkerStopping` (flush) | callbacks/events | I/O must be after response | |
| Slow request alert | `Kernel::whenRequestLifecycleIsLongerThan` | method | none | |
| Console | `CommandStarting` / `CommandFinished` | events | none | not fired in unit tests |
| Jobs | `JobProcessing`, `JobProcessed`, `JobExceptionOccurred`, `JobFailed`, `JobTimedOut`, `Looping`, `WorkerStopping` | events | per job | per-job reset |

---

## 16. Open items to verify before coding (not confirmed this session)

1. Exact Eloquent event name strings (`eloquent.retrieved: <class>`) and that a wildcard `eloquent.*` receives `($name, [$model])`: check `Illuminate\Database\Eloquent\Concerns\HasEvents::fireModelEvent`.
2. `ModelsPruned` class and payload.
3. `Kernel::prependMiddleware/pushMiddleware`, `Router::pushMiddlewareToGroup` presence in 12.x; `EngineResolver::register` override path for Blade.
4. `MessageLogged`, `JobQueueing`/`JobQueued`, `Queue::createPayloadUsing`, `ScheduledTask*` events.
5. `debug_backtrace` flag semantics and `memory_reset_peak_usage()` (php.net).
6. Whether `Octane` invokes `Kernel::terminate()` per request (affects `requestStartedAt` reset and `Terminating` event).
7. Compiled Blade footer marker format `/**PATH ... ENDPATH**/`.
8. Whether `hydrate()` sets `preventsLazyLoading` only when count > 1 (taken from a tool summary of `Eloquent\Builder`; re-read the method).
9. Behavior detail in `handleLazyLoadingViolation` after a custom callback returns (read as: lazy load then proceeds); confirm there is no early `return` that suppresses the load.

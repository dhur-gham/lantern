# 04 - Verified claims (empirical, against installed vendor source)

Targets: PHP 8.4.21 (Herd, NTS), `laravel/framework` 12.70.2, `livewire/livewire` v4.4.8, `filament/filament` 5.11
(all in `C:\Users\pc\Desktop\demo-app\vendor`, read-only). Paths below are relative to
`vendor/laravel/framework/src/Illuminate/` (LF), `vendor/livewire/livewire/src/` (LW), `vendor/filament/filament/src/` (FM).
Scripts (all in-memory sqlite, array cache/session, compiled-view dir redirected to scratch) live in the session scratchpad
`...\scratchpad\spikes\` as `s1_eloquent.php`, `s2_bench.php`, `s2b_flags.php`, `s3_frames.php`, `s4_blade.php`,
`s5_mw.php`, `s6_livewire.php`, `s7_hook.php`, `s8_lazy.php`, `s9_engine.php`.
Verdicts: CONFIRMED / WRONG / PARTLY / UNVERIFIED (could not verify here).

## 0. Plan-changing findings (read first)

1. **`debug_backtrace(..., 15)` is too shallow.** In a plain Capsule app the first app-code frame is at index 13-15 from inside
   `DB::listen`; for a lazy-loaded relation (`$post->author`) it is at index **20** (s8). Under Livewire/Filament it is deeper.
   Use a limit of about 40-50 (cost is only about 1.8 us vs 1.0 us per call) or walk lazily.
2. **`Livewire::componentHook()` called after Livewire boots is silently ignored** (s7). `ComponentHookRegistry::boot()`
   iterates the hook list once (`LW/LivewireServiceProvider.php:217`). Filament registers via `$app->booting(...)`. Prefer
   `Livewire::listen('call'|...)`, which works at any time (s6).
3. **No supported per-middleware hook exists; Pipeline is not swappable by binding** (`new Pipeline($this->app)` is hard-coded).
   `$app->extend($middlewareClass, fn($inst)=>new TimedMw($inst))` works, including parameterised and terminable middleware
   (s5, 31 classes wrapped, nested total/self timings produced). It is the cleanest supported method.
4. **Lazy-loading detection is a single static slot and needs `Model::preventLazyLoading(true)`.** The package must chain
   to any previous callback (read via reflection) and re-throw the default `LazyLoadingViolationException` itself,
   because a custom callback replaces the throw. The handler gets `(Model $model, string $relation)`.
5. **DB::listen never sees failed queries**, and `Connection::beforeExecuting` is the only hook to catch them (pending-query
   pattern). It must be attached per connection (`ConnectionEstablished` event).
6. Livewire `call` event has **7 args** in v4, `profile` events only fire when `config('app.debug')` is true, and the
   Livewire blade engine is `ExtendedCompilerEngine`, so any view-timing engine wrapper must decorate it, not replace it.

## 1. Doc 01 section 16 (open items)

| # | Claim | Verdict | Evidence | Use this |
|---|---|---|---|---|
| 1a | Eloquent event strings are `eloquent.{event}: {class}`; dispatched with the model as payload | CONFIRMED | `LF/Database/Eloquent/Concerns/HasEvents.php:224-226` (`"eloquent.{$event}: ".static::class`); listen helper `:194`. s1 output: `eloquent.retrieved: Author`, `saving`, `creating`, `created`, `saved`, `updating`, `updated`, `deleting`, `deleted`. | Observable set (`HasEvents.php:131-141`): retrieved, creating, created, updating, updated, saving, saved, restoring, restored, replicating, trashed, deleting, deleted, forceDeleting, forceDeleted. Custom `$observables` are appended. |
| 1b | Wildcard `eloquent.*` receives `($name, [$model])` | CONFIRMED | s1: wildcard closure got `$name='eloquent.retrieved: Author'`, `$payload=[Author]` (array of one model). | `Event::listen('eloquent.retrieved: *', fn(string $name, array $payload) => ...)`; model is `$payload[0]`. Also `'eloquent.*'` for all events. Pattern `'eloquent.retrieved*'` also matches (3 hits for `Author::all()` of 3 rows). |
| 1c | `retrieved` fires once per hydrated model (hydration count) | CONFIRMED | s1: `Author::all()` over 3 rows -> 3 events; `Author::all()+Post::all()` -> 6. | Count per class from `$name`. Note: with `until()` the handler returning `false` can halt `creating/saving`; a profiler must return `null`. |
| 1d | Custom `$dispatchesEvents` object events fire first | CONFIRMED (source) | `HasEvents.php:216-227,236-246`; the string event fires only if the object event returned empty. | If a model maps events to classes, the string event can be skipped (`! empty($result) ? $result : dispatcher->...`). A package using string wildcards can miss those. |
| 2 | `ModelsPruned` class and payload | CONFIRMED | `LF/Database/Events/ModelsPruned.php`: `__construct(public $model, public $count)`. Fired in `Prunable.php:42`, `MassPrunable.php:34`. Siblings `ModelPruningStarting`, `ModelPruningFinished` exist in the same dir. | `Illuminate\Database\Events\ModelsPruned` (`->model` class-string, `->count` int). |
| 3a | `Kernel::prependMiddleware/pushMiddleware` exist in 12.x | CONFIRMED | `LF/Foundation/Http/Kernel.php:347,362` (dedupe via `array_search`, `array_unshift`/append); group variants `:380` `prependMiddlewareToGroup`, `:404` `appendMiddlewareToGroup` (throw `InvalidArgumentException` if the group is undefined, then call `syncMiddlewareToRouter`). | `app(\Illuminate\Contracts\Http\Kernel::class)->prependMiddleware(X::class)` in a provider `boot()`. s5 shows it works: `bootstrap()` runs before `->through($this->middleware)` is read (`Kernel.php:164-176`). Other packages also prepend (Filament `PublishNotifications` ended up first), so being first is not guaranteed; last prepender wins. Do not call in `register()` before the Kernel is resolved: `ApplicationBuilder::withMiddleware` does `afterResolving(HttpKernel)` -> `setGlobalMiddleware(...)` which overwrites the array (`Configuration/ApplicationBuilder.php:274-286`). |
| 3b | `Router::pushMiddlewareToGroup` exists | CONFIRMED | `LF/Routing/Router.php:1112` (creates group if missing); `:1094 prependMiddlewareToGroup` (silently ignores a missing group). | Prefer `Kernel::prependMiddlewareToGroup/appendMiddlewareToGroup` (they also `syncMiddlewareToRouter`, `Kernel.php:521`, which re-pushes kernel group state into the router and could drop router-only additions; source-derived, not exercised). |
| 3c | A package can wrap/prepend middleware to time each one | PARTLY | See section 3 below. No built-in per-middleware event. `Pipeline` is constructed with `new Pipeline(...)` at `Kernel.php:172` and `Router.php:818`, so a container binding cannot replace it. | `Container::extend($middlewareClass, fn ($inst) => new TimedMiddleware($inst))` (s5). Works for string-resolved middleware (global, group, route, parameterised). Closures and object instances in the list are not resolved through the container (the `is_callable`/object branches in `Pipeline.php:197-216`), so they are not wrapped. |
| 3d | `EngineResolver::register` override path for Blade | CONFIRMED | `LF/View/Engines/EngineResolver.php:33-39` (`forget` then set resolver). s9 replaced `blade` with a decorator and got per-view `get()` timings. Current Blade engine under Livewire is `Livewire\Mechanisms\ExtendBlade\ExtendedCompilerEngine`. | `app('view.engine.resolver')->register('blade', fn () => new TimedEngine($inner))` where `$inner = $resolver->resolve('blade')` captured first, after Livewire has booted (use `$app->booted()`). Engine contract is only `get($path, array $data)`. |
| 4a | `MessageLogged` | CONFIRMED | `LF/Log/Events/MessageLogged.php`: `(public $level, public $message, public array $context = [])`. Dispatched at `Log/Logger.php:261`; skipped when the Logger wraps a `LogManager` that already has a dispatcher (`:252-256`) to avoid duplicates. | `Illuminate\Log\Events\MessageLogged`. A listener that logs will recurse; guard with a static flag (recursion not executed here). |
| 4b | `JobQueueing` / `JobQueued` | CONFIRMED (source) | `LF/Queue/Events/JobQueueing.php`: `($connectionName, $queue, $job, $payload, $delay)` plus `payload()`. Raised at `Queue/Queue.php:412-418` / `431-437`, wrapped in `enqueueUsing` (`:355-382`), deferred until commit when `shouldDispatchAfterCommit`. | `JobQueueing` fires before push; `JobQueued` gets `$jobId` also. **`SyncQueue` does not call these** (no `raiseJobQueue*` in `Queue/SyncQueue.php`); sync jobs only produce processing events. |
| 4c | `Queue::createPayloadUsing` | CONFIRMED | `Queue/Queue.php:317-324,333-342`: static list; callback signature `($connectionName, $queue, $payload)` and its returned array is `array_merge`d into the payload. `null` clears ALL callbacks. | Use to inject a trace id into payloads; never call with `null`. |
| 4d | `ScheduledTask*` events | CONFIRMED | `LF/Console/Events/`: `ScheduledTaskStarting`, `ScheduledTaskFinished`, `ScheduledTaskFailed`, `ScheduledTaskSkipped`, `ScheduledBackgroundTaskFinished`; dispatched in `Console/Scheduling/ScheduleRunCommand.php:122,192,199,210`. | `Illuminate\Console\Events\ScheduledTask*`. |
| 5a | `debug_backtrace` flag semantics | CONFIRMED | s2b: `PROVIDE_OBJECT (1)` frame keys `file,line,function,class,object,type`; `IGNORE_ARGS (2)` -> `file,line,function,class,type`; **default `debug_backtrace()` returns BOTH `object` and `args`**; `flags=0` -> args but no object. | Always pass `DEBUG_BACKTRACE_IGNORE_ARGS` (no `object` either, since PROVIDE_OBJECT is not set). Add `PROVIDE_OBJECT` only for flagged queries. |
| 5b | Cost (10k calls, IGNORE_ARGS, limit 15) | CONFIRMED (measured) | s2 at stack depth ~40: noop 0.04 us; default 2.85 us; IGNORE_ARGS no limit 2.13 us; **IGNORE_ARGS+15 = 1.01 us (10.1 ms / 10k)**; IGNORE_ARGS+50 = 1.77 us; `new Exception->getTrace()` 2.69 us; limit 15 + scan = 0.65 us. | Cost is negligible; the problem is depth, see section 0 item 1. |
| 5c | `memory_reset_peak_usage()` exists on PHP 8.2+ | CONFIRMED | s2: `function_exists` true on 8.4.21; peak before reset 28.5 MB, after 7.6 MB. 0 params. (Added in PHP 8.2 per php.net; 8.2/8.3 not installed here, so the "since 8.2" part is from documentation, not executed.) | Guard with `function_exists`; call at request/job start under Octane/queue workers. |
| 6 | Octane invokes `Kernel::terminate()` per request | UNVERIFIED | `laravel/octane` is not installed in demo-app (`vendor/laravel` has framework, pail, pint, prompts, sail, sanctum, serializable-closure, tinker). | Needs Octane source; keep `RequestTerminated` + app-level reset. |
| 7 | Compiled Blade footer marker `/**PATH ... ENDPATH**/` | CONFIRMED | `LF/View/Compilers/BladeCompiler.php:228`: `"<?php /**PATH {$this->getPath()} ENDPATH**/ ?>"`. s4 compiled file ends with `<?php /**PATH <abs source path> ENDPATH**/ ?>`. Compiled name = `hash('xxh128','v2'.Str::after($path,$basePath)).'.php'` (`Compilers/Compiler.php:94`; one-way). | Regex `~/\*\*PATH (.*?) ENDPATH\*\*/~` on the **last bytes** of the compiled file (read the tail only; cache by path). Path is the absolute source path; derive view name by stripping `resource_path('views/')` and `.blade.php`. For timing, `Engine::get($path)` already receives the source path, so no mapping is needed there. Stack frames show the compiled path, which does need the marker. |
| 8 | `hydrate()` sets `preventsLazyLoading` only when count > 1 | CONFIRMED | `LF/Database/Eloquent/Builder.php:471-474`: `if (count($items) > 1) $model->preventsLazyLoading = Model::preventsLazyLoading();`. s1: `Post::all()` (3 rows) flagged 3 times; `where('id',1)->get()` (1 row), `find(1)`, and `with('author')` eager load not flagged. | Violations require (a) global `Model::preventLazyLoading(true)` and (b) a multi-row hydrate. Single-row N+1 (loop of `find`) is invisible to this hook; detect those via SQL-shape grouping. |
| 9 | After a custom callback returns, the lazy load proceeds | CONFIRMED | `LF/Database/Eloquent/Concerns/HasAttributes.php:563-576` (`getRelationValue`): no return after `handleLazyLoadingViolation`, falls to `getRelationshipFromMethod`; `:600-603` custom callback is called and its return value ignored. s1: after the callback `relationLoaded('author')` true; 4 queries (1+3). | Callback only observes; it cannot suppress. Default (no callback) throws `LazyLoadingViolationException` unless `! exists || wasRecentlyCreated` (`:605-607`); the check is skipped entirely when a custom callback is set. |

## 2. Doc 03 unverified list and related claims

| # | Claim | Verdict | Evidence | Use this |
|---|---|---|---|---|
| A | `handleLazyLoadingViolationUsing` / `preventLazyLoading` / `automaticallyEagerLoadRelationships` exist at `Model.php` 510/521/532 | CONFIRMED | `LF/Database/Eloquent/Model.php:510` (`preventLazyLoading($value=true)`), `:521` (`automaticallyEagerLoadRelationships`), `:532` (`handleLazyLoadingViolationUsing(?callable)`); `:206` static `$lazyLoadingViolationCallback`. | |
| B | Callback signature / args | CONFIRMED | Docblock `(callable(self, string))`; s1/s8: `[Post, 'author']`, one call per lazily accessed model (3 calls for 3 posts). Model id readable from `$model->getKey()`. | `fn (Model $model, string $relation) => ...` |
| C | Chaining behaviour | WRONG if one assumes chaining: it is a **single static slot**, last writer wins | `Model.php:206,534`; s1: setting `first` then `second` -> only `second` runs. Old value readable via `ReflectionProperty(Model::class,'lazyLoadingViolationCallback')` (s1: `===second`). | Capture previous via reflection at boot, install a wrapper that records then calls previous (s1 showed both "lantern saw" and "userHandler"). If previous is `null` and the app expects the default throw, re-throw `new LazyLoadingViolationException($model, $relation)` (only when `$model->exists && ! $model->wasRecentlyCreated`, mirroring `HasAttributes.php:605-607`). Apps that call `handleLazyLoadingViolationUsing` after Lantern boots will silently remove Lantern's wrapper. |
| D | With `automaticallyEagerLoadRelationships(true)` violations occur | WRONG (none occur) | `getRelationValue` calls `attemptToAutoloadRelation` before the violation check (`HasAttributes.php:559-561`). s8: 0 violations with auto-eager on. | Auto-eager hides the N+1 from this hook; fall back to SQL-shape detection. |
| E | Lazy-load frame names `HasAttributes::getRelationshipFromMethod`, `Model::getRelationValue`, `lazyLoadRelation` | PARTLY | s8 frame list (innermost first): `Model->__get`, `Model->getAttribute`, `Model->getRelationValue`, `Model->getRelationshipFromMethod`, `BelongsTo->getResults`, `Eloquent\Builder->first/get/getModels`, ... Trait methods report `class = Illuminate\Database\Eloquent\Model`, not `HasAttributes`. **`lazyLoadRelation` does not exist** (grep over `Eloquent/`). | Marker = frame with `function === 'getRelationshipFromMethod'` and class `Illuminate\Database\Eloquent\Model` (or `getRelationValue`). |
| F | Livewire `listen`/`componentHook` are source-level APIs | CONFIRMED | `LW/LivewireManager.php:53-56` (`componentHook` -> `ComponentHookRegistry::register`), `:116-118` (`listen` -> `on`). `Livewire\Livewire` facade root is `Livewire\LivewireManager` (s6). | `Livewire\Livewire::listen($event, $cb)` |
| G | Event arg lists | CONFIRMED (v4.4.8) | s6 captured real arg types (below). Trigger sites: `LW/Mechanisms/HandleComponents/HandleComponents.php:61,220,451,563,327,...`, `HandleRequests/HandleRequests.php:197,240`. | see table below |
| H | `componentHook()` works whenever it is called | WRONG | `ComponentHookRegistry::boot()` (`LW/LivewireServiceProvider.php:217`) snapshots `$componentHooks` once to wire `mount`/`hydrate`. s7: registered via `$app->booting(...)` -> hook `call()` ran for `inc`; registered after bootstrap -> **no output at all**. `register()` also calls `$hook::provide()` if present. | Use `Livewire::listen`, or register the hook inside `$this->app->booting(fn () => Livewire::componentHook(...))` from the provider's `register()`, exactly as Filament does (`FM/FilamentServiceProvider.php:98-100`). Hook `call` receives `($method, $params, $returnEarly, $metadata, $componentContext)` and returns an optional finish closure that gets the return value (s7: `finish ret="r"`). |
| I | `Livewire::isLivewireRequest()` | CONFIRMED, but the research text was imprecise | `LW/Mechanisms/HandleRequests/HandleRequests.php:119-122`: `request()->hasHeader('X-Livewire')`; s6: false without header, true with it. This is independent of the hashed update path. A separate `isLivewireRoute()` (`:124-138`) checks route name `*livewire.update`. | `Livewire::isLivewireRequest()` (header based; works only after the request is bound). |
| J | `profile` event only when `config('app.debug')` | CONFIRMED | Each `trigger('profile', ...)` is guarded by `if (config('app.debug'))` (`HandleComponents.php:62,66,72,223,230,237,594`). s6 (debug true): `profile('mount', $id, [$start,$end])`, `render`, `dehydrate`, `hydrate`, `call0`. | Do not rely on `profile` in production; time `mount/hydrate/call/render/dehydrate` yourself from the finish closures. |
| K | Filament serving event class name | CONFIRMED | `FM/Events/ServingFilament.php`: `Filament\Events\ServingFilament` (`Dispatchable`, no payload). Dispatched by `Filament\Http\Middleware\DispatchServingFilamentEvent::handle` (`FM/Http/Middleware/DispatchServingFilamentEvent.php`). `Filament::serving(Closure)` = `Event::listen(ServingFilament::class, $cb)` (`FM/FilamentManager.php:896-899`). It is in Livewire's persistent middleware list (`FM/FilamentServiceProvider.php:147-152`). s6: `GET /admin/login` fired both the `serving` callback and the raw event. | `Event::listen(\Filament\Events\ServingFilament::class, ...)` (feature-detect with `class_exists`). Fires once per panel request, including Livewire update requests via persistent middleware. |
| L | `Kernel::prependMiddleware` / `Router::pushMiddlewareToGroup` for a package | CONFIRMED (see 3a/3b) | | |
| M | Livewire v3 arities (`call` has 5 args), Filament 4.x behaviour | UNVERIFIED | Only Livewire 4.4.8 and Filament 5.11 are installed. | Use variadic callbacks; the v4 `call` signature is `($root, $method, $params, $componentContext, $returnEarly, $metadata, $idx)`. |
| N | Livewire `exception` event args | PARTLY | Registry wiring `on('exception', function ($target, $e, $stopPropagation) ...)` (`LW/ComponentHookRegistry.php`); not triggered in any scratch test. | Treat as `($target, $e, $stopPropagation)` from source only. |

### Livewire v4.4.8 event arguments (observed in s6, `Livewire::test('probe')->call('inc')`)

| Event | Args (observed) | Notes |
|---|---|---|
| `pre-mount` | `($name, $params, $key, $parent, Closure $addHtml, $htmlAttrs?, array)` argc 7 | initial render only |
| `mount` | `(Component, array $params, string $key, bool $parent, array $htmlAttributes)` argc 5 | |
| `request` | `(array $requestPayload)` argc 1 | once per update request |
| `hydrate` | `(Component, array $memo, ComponentContext)` argc 3 | |
| `update` | `($component, $path, $value)` | source `:451`; not exercised (no property update in test) |
| **`call`** | `(Component $root, string $method, array $params, ComponentContext $ctx, Closure $returnEarly, array $metadata, int $idx)` argc **7** | returns finish closure receiving the method return value (`HandleComponents.php:563,594-596`); call the closure with a value to short-circuit via `$returnEarly` |
| `render` | `(Component, Illuminate\View\View, array $properties)` argc 3 | |
| `dehydrate` / `destroy` | `(Component, ComponentContext)` argc 2 | |
| `response` | `(array $responsePayload)` argc 1 | once per HTTP request |
| `profile` | `(string $name, string $componentId, array [$start, $end])` argc 3 | debug only |

Also: `Livewire::current()` returns `false` outside a component (s6); `EventBus::trigger` returns a closure `(&$forward, ...$extras)` that runs the finisher
closures returned by listeners (`LW/EventBus.php`).

## 3. Per-middleware timing: methods tested

| Method | Result |
|---|---|
| Custom `Pipeline` container binding | **WRONG**: pipelines are created with `new` (`Kernel.php:172`, `Router.php:818`); nothing resolves them from the container. Would require subclassing and rebinding the HTTP Kernel itself. |
| `Router::aliasMiddleware` wrapper | Not general: group lists and global lists contain class names, not aliases, so only aliased route middleware would be affected. Not pursued. |
| Replace list entries with wrapper closures/objects (`setGlobalMiddleware`) | Works for `handle` (callable branch) but `terminateMiddleware` skips non-strings (`Kernel.php:250-253`), losing `terminate()` of the originals. Rejected. |
| **`$app->extend($class, fn ($inst) => new TimedMiddleware($inst, $class))`** | **CONFIRMED (s5)**: 31 classes (global+group+aliases) wrapped at `booted`; `GET /probe` (web + `throttle:60,1`) returned 200 with per-middleware `total` and `self` (total minus time inside `$next`). Sample self times (ms, cold): ThrottleRequests 4.6, StartSession 2.4, EncryptCookies 1.3, PreventRequestsDuringMaintenance 0.86, SubstituteBindings 0.48. `terminate()` delegated and ran for all. Pipe string parameters (`throttle:60,1`) still resolve because `Pipeline::carry` does `container->make($name)` then calls `handle(...$params)` (`Pipeline.php:205-220`). Wrapper must expose `handle($req, Closure $next, ...$params)` and `terminate($req, $resp)`. |
| Order stability | Global list at runtime (demo-app): `PublishNotifications` (Filament, prepended), ValidatePathEncoding, InvokeDeferredCallbacks, TrustProxies, HandleCors, PreventRequestsDuringMaintenance, ValidatePostSize, TrimStrings, ConvertEmptyStringsToNull, `DisableBackButtonCacheMiddleware` (Livewire, pushed). Packages mutate the list, so compute the class set at `booted`, and for route-level middleware use `Router::gatherRouteMiddleware($route)` in a `RouteMatched` listener (`Router.php:797` fires before `runRouteWithinStack`). |
| Caveats (not exercised) | `instanceof` checks on resolved middleware, code doing `app(Middleware::class)` and expecting the real class, and middleware that are container singletons whose instance already exists (extend then applies immediately via `rebound`, `Container.php:575-590`). |

## 4. Query hooks

| Claim | Verdict | Evidence |
|---|---|---|
| `DB::listen` receives failed queries | **CONFIRMED NOT** | `Connection::run` calls `logQuery` only after `runQueryCallback` succeeds (`LF/Database/Connection.php:793-806`); exceptions rethrow as `QueryException` (`:833-846`) with no event. s1: `select 1` -> listen got it; `select * from nope` -> listen got nothing. |
| `Connection::beforeExecuting($cb)` catches them | CONFIRMED | `Connection.php:782-784` loops callbacks with `($query, $bindings, $connection)` before the run; s1: callback saw both statements. It fires on every `run()` (select/insert/statement), once (lost-connection retry uses `runQueryCallback`, not `run`). It is per connection object (`:1062-1067`); `DatabaseManager` has no manager-level variant (facade `__call` forwards to the default connection only). |
| Attach to every connection | CONFIRMED (source) | `DatabaseManager.php:105,161,334` dispatch `Events\ConnectionEstablished($connection)` (has `->connection`). Listen to it and call `$e->connection->beforeExecuting(...)`; also attach to already-resolved connections at boot. |
| Catching via exception reporting | PARTLY | `QueryException` exposes `getSql()`, `getBindings()`, `getConnectionName()` (s1: sql/bindings correct, previous = `PDOException`). Only reaches `ExceptionHandler::reportable` if the exception is reported (swallowed `try/catch` exceptions are invisible), and has no start time. |
| Recommended design | | `beforeExecuting` stashes `[sql, bindings, start, connection]` in a per-connection pending slot; the `QueryExecuted` listener clears it (match on sql+bindings). Anything still pending at the next `beforeExecuting` on that connection, at `RequestHandled`/`JobProcessed`/terminate, or when a `QueryException` is reported is a failed query (duration = approximate). Not implemented/executed end to end here; the two primitive behaviours it relies on are confirmed. `QueryExecuted` has `->sql, ->bindings, ->time, ->connection, ->connectionName, ->readWriteType, ->toRawSql()` (`LF/Database/Events/QueryExecuted.php`; `toRawSql` measured at +10 us/query, so call lazily). |

## 5. Measured overhead of a `DB::listen` callback (s2, 10k sqlite `where id = 1` queries, PHP 8.4, shallow stack)

| Listener | Total (10k) | Per query |
|---|---|---|
| none | 271.5 ms | 27.1 us |
| noop closure | 285.3 ms | 28.5 us |
| record sql+time | 283.9 ms | 28.4 us |
| + `debug_backtrace(IGNORE_ARGS, 15)` | 290.3 ms | 29.0 us (+1.9 us vs none) |
| + backtrace(15) + scan for non-vendor frame | 303.2 ms | 30.3 us |
| + `debug_backtrace(IGNORE_ARGS, 50)` | 290.8 ms | 29.1 us |
| + default `debug_backtrace()` (args+object, unlimited) | 297.9 ms | 29.8 us |
| + `$q->toRawSql()` | 374.2 ms | 37.4 us |

Conclusion: a backtrace per query costs roughly 2 us (about 0.2% of a 1 ms real query); deeper stacks in Livewire/Filament raise it modestly
(s2 at depth 40: 1.8 us at limit 50). Overhead is not a reason to sample; correctness of depth is (section 0 item 1). Frame counts from inside
`DB::listen` to user code (s3, s8): `DB::table()->first()` 13, `Model::query()->first()` 15, `Model::all()` 15, lazy relation access 20.

## 6. Not verified here

- Octane (`Kernel::terminate`, `RequestReceived/Terminated`, worker reset): package not installed.
- PHP 8.2/8.3 behaviour of `memory_reset_peak_usage` (only 8.4.21 available).
- Livewire v3 and Filament 3/4 signatures; Laravel 11 / 13 differences.
- Real update-request path through HTTP (`/livewire-{hash}/update`): events observed through `Livewire::test()` (which calls the same `HandleComponents`/`HandleRequests` triggers), not through a hand-built signed snapshot POST.
- Recursion guard for `MessageLogged`, the pending-query failed-query tracker as a whole, and the `Kernel::prependMiddlewareToGroup` vs `Router::pushMiddlewareToGroup` sync interaction were derived from source, not run.
- Overhead of the middleware `extend` wrapper itself (timings above include only the wrapper's own `hrtime` calls; not benchmarked in isolation).

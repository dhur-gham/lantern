# 03 - Filament + Livewire: request flow, query sites, N+1 traps, observation hooks

Research date: 2026-10-27. Evidence = official docs fetched + source read from shallow clones of
`filamentphp/filament` (branch 4.x) and `livewire/livewire` (main = v4). Items marked **[UNVERIFIED]**
were not confirmed from docs/source.

## 1. Versions and install requirements

| Fact | Evidence |
|---|---|
| Latest Filament tags: **v5.9.0** (5.x line), **v4.16.0** (4.x line). Latest Livewire tag found: **v4.4.8** | `git ls-remote --tags` on both repos |
| **Filament 5** requires PHP 8.2+, Laravel v11.28+, **Livewire v4.0+**, Tailwind 4.0+/4.1+. Install: `composer require filament/filament:"^5.0"` then `php artisan filament:install --panels` | https://filamentphp.com/docs/5.x/introduction/installation , https://filamentphp.com/docs/5.x/upgrade-guide ("Filament v5 requires Livewire v4.0+") |
| **Filament 4** requires PHP 8.2+, Laravel v11.28+, Tailwind 4.1+; `livewire/livewire ^3.8.7` (packages/support/composer.json) | https://filamentphp.com/docs/4.x/introduction/installation ; https://github.com/filamentphp/filament/blob/4.x/packages/support/composer.json |
| Filament 5.x support package: `illuminate/contracts ^11.28\|^12.0\|^13.0`, `livewire/livewire ^4.4.8`, php ^8.2 | https://github.com/filamentphp/filament/blob/5.x/packages/support/composer.json |
| Filament 3: `illuminate/* ^10.45\|^11\|^12\|^13`, `livewire ^3.5`, php ^8.1 | https://github.com/filamentphp/filament/blob/3.x/packages/support/composer.json |
| Livewire main (v4): php ^8.1, illuminate/* `^10\|^11\|^12\|^13` | https://github.com/livewire/livewire/blob/main/composer.json |
| Laravel 12.x framework itself: php ^8.2; latest Laravel release seen is v13.39.0 | laravel/framework 12.x composer.json, GitHub releases API |

Conclusion: Laravel 12 is supported by Filament 3, 4 and 5. "Current" major is **5 (Livewire 4)**; 4 (Livewire 3.8.7+) is still maintained. Docs for 4.x/5.x are near-identical for the points below. A package must support **both Livewire 3 and 4** to cover Filament 3/4/5 installs.

The Filament installation docs page does not state a Livewire version (pulled in transitively by composer).

## 2. Request flow

### 2.1 Initial page load (GET /admin/...)
1. Route group built in `packages/panels/routes/web.php`: `Route::domain(..)->middleware($panel->getMiddleware())->prefix($panel->getPath())`. `getMiddleware()` returns `["panel:{id}", ...$panel middleware]` (`packages/panels/src/Panel/Concerns/HasMiddleware.php`). Authenticated routes add `$panel->getAuthMiddleware()` (Filament `Authenticate`), tenant routes add `getTenantMiddleware()` (`IdentifyTenant`).
2. `SetUpPanel` middleware: `Filament::setCurrentPanel()` + `Filament::bootCurrentPanel()` -> `Panel::boot()` (tenancy, assets, colors/icons, render hooks, plugin `boot()`, `bootUsing` callbacks). (`Http/Middleware/SetUpPanel.php`, `FilamentManager::bootCurrentPanel`, `Panel.php:87`).
3. Other middleware in `packages/panels/src/Http/Middleware/`: Authenticate, AuthenticateSession, DisableBladeIconComponents, DispatchServingFilamentEvent, IdentifyPageConfiguration, IdentifyResourceConfiguration, IdentifyTenant, SetUpPanel.
4. Route target is a **Livewire full-page component** (Page / ListRecords / EditRecord ...). Livewire runs `mount` -> `render` -> `dehydrate` (`HandleComponents::mount` triggers `mount`, `render`, `dehydrate`, `destroy`). For record pages: `EditRecord::mount()` calls `resolveRecord($record)` (**query: resource `getEloquentQuery()` via route binding**), `authorizeAccess()`, `fillForm()`.
5. Layout renders child Livewire components: sidebar/topbar/global search/notifications (`packages/panels/src/Livewire/{Sidebar,Topbar,GlobalSearch,DatabaseNotifications,Notifications}.php`), the page's table (rendered by the page component itself), widgets (child components), relation managers (child components). Lazy ones (`CanBeLazy` on Widget and RelationManager) render placeholders and are hydrated in a **second** update request.
6. Navigation: `NavigationManager::get()` builds items; `HasNavigation.php:77` calls `static::getNavigationBadge()` per resource. Badge queries therefore run whenever the sidebar renders (page load and any update request re-rendering it). No cache seen in `NavigationManager` beyond the per-instance `isNavigationMounted` flag.

### 2.2 Livewire update requests
- Endpoint: **Livewire 3: `POST /livewire/update`** (3.x HandleRequests: `Route::post('/livewire/update')->middleware('web')`). **Livewire 4: `POST /livewire-{hash}/update`**, hash = first 8 hex chars of `sha256(APP_KEY.'livewire-endpoint')` (`Mechanisms/HandleRequests/EndpointResolver.php`); route name ends `livewire.update`, middleware `['web', RequireLivewireHeaders]`. Do **not** hardcode the path: use `Livewire::isLivewireRequest()` or route name `*livewire.update`, and `app('livewire')->getUpdateUri()`. Sources: https://livewire.laravel.com/docs/4.x/upgrading , HandleRequests.php.
- Middleware on update requests: the update route only has `web`. Filament panel middleware is re-applied via **Livewire persistent middleware** (`FilamentServiceProvider.php:147`: Authenticate, AuthenticateSession, Illuminate AuthenticateSession, DisableBladeIconComponents, DispatchServingFilamentEvent, IdentifyPageConfiguration, IdentifyResourceConfiguration, IdentifyTenant, SetUpPanel) and `Panel::registerLivewirePersistentMiddleware()`. So `SetUpPanel` re-boots the panel on every update request.
- Per HTTP request: `HandleRequests::handleUpdate` triggers `request`; for each component `HandleComponents::update`: `fromSnapshot` -> `hydrate` -> `updateProperties` (`update` per property) -> `callMethods` (`call` per method) -> `render` -> `dehydrate` -> `destroy`; finally `response`. (HandleComponents.php ~lines 205-245, 465, 589; HandleRequests.php 197, 240.)
- Filament on hydrate: resource pages re-resolve the record **on every request** ("On every Livewire request, Filament re-queries the page's $record using the resource query." https://filamentphp.com/docs/5.x/resources/overview). Code: `ScopedModelPropertiesComponentHook::hydrate/call` -> `resolveScopedModelProperties()`; `EditRecord::hydrate()` -> `authorizeAccess()`; `InteractsWithRecord::resolveRecordPropertyFromLivewire` (guarded by `hasResolvedRecordForRequest`, once per request).
- Livewire method names on Filament update calls (useful for attribution), verified by grep: `mountAction`, `callMountedAction`, `mountTableAction`, `callMountedTableAction`, `mountTableBulkAction`, `sortTable`, `loadTable`, `callSchemaComponentMethod` (form field closures, Select search/options), `__dispatch` (events); property updates like `tableSearch`, `tableFilters.*`, `tablePage`, `data.*` (names of these properties not individually verified).

### 2.3 Where Eloquent queries are issued
| Source | Where (4.x source) |
|---|---|
| List table | `tables/src/Concerns/HasRecords.php`: `getFilteredTableQuery` / `filterTableQuery` / `getTableRecords`. Base is `Resource::getEloquentQuery()` (via `ListRecords::getTableQuery`) + `Table::modifyQueryUsing` scopes; filters, search, sort, group applied; paginate = count query + select (+ eager-load queries) |
| Relationship columns | `Column::applyEagerLoading()` -> `->with()` (section 3); `applyRelationshipAggregates()` -> `withCount/withExists/withAvg/...` (`Columns/Concerns/InteractsWithTableQuery.php`) |
| Row / bulk actions | `getTableRecord($key)` re-queries `->find($key)` with aggregates but **no eager loads** (`HasRecords.php` ~215-240): one query per row action mount/call. Bulk: `HasBulkActions.php:372` applies eager load |
| Global search | `Resource::getGlobalSearchResults` -> `getGlobalSearchEloquentQuery()` (= `getEloquentQuery()`) `->limit()->get()`, then per-record title/details/url/actions closures (`HasGlobalSearch.php`). Runs in the `GlobalSearch` Livewire component, per resource, per search |
| Navigation badges | user-defined `getNavigationBadge()` (default null), typically `Model::count()`; sidebar render |
| Record pages | `resolveRecord` via `getRecordRouteBindingEloquentQuery` (`HasRoutes.php:41`): mount and every update request |
| Widgets | `Widget` uses `CanBeLazy` (`Widget.php:114`); stats/chart/table widgets run own queries; polling widgets issue requests on an interval |
| Relation managers | child Livewire components with their own `table()`; query = owner relationship (`Table::relationship`); lazy possible; tab badges deferrable (`$isBadgeLazyLoaded`) |
| Infolists/forms | read from `$record`; relationship fields (`Select::relationship`) query options/search via `callSchemaComponentMethod`; relationship-backed fields/repeaters load related rows when filled |
| Database notifications | `DatabaseNotifications` Livewire component (poll) - query not read in detail |

## 3. N+1 traps and Filament's automatic mitigation

Automatic (verified in source):
- Dot-notation column (`author.name`): `Column::applyEagerLoading()` -> `$query->with([$relationshipName])` unless already in `getEagerLoads()`. Nested names (`author.company.name`) resolved by `HasCellState::getRelationshipName()` (all parts before the last, stopping at a real attribute). Applied only to visible columns; skipped for group-only tables (`HasRecords::filterTableQuery`).
- Column `->counts()/->exists()/->avg()/->sum()/->min()/->max()` become `withCount/withExists/...` aggregates in the main query. Docs: https://filamentphp.com/docs/5.x/tables/columns/overview ("Counting relationships").
- `SelectColumn` (options relationship) and `Group` do their own `with()`.
- Docs for tables/columns and resource overview say **nothing** about eager loading or N+1 (confirmed by fetch).

Traps (not mitigated):
1. `->getStateUsing(fn ($record) => $record->author->name)` / `->state(fn ...)`: `state()` is an alias of `getStateUsing()` (`HasCellState.php:70/77`). Filament cannot know which relations the closure touches -> lazy load per row. Fix: `Table::modifyQueryUsing(fn ($q) => $q->with('author'))` or a dot-notation column.
2. Accessors / computed attributes touching relations; `description()`, `color()`, `icon()`, `url()`, `badge()`, `formatStateUsing`, `visible()`, row-action `visible/disabled/authorize` closures and policies that query - all evaluated per row.
3. Many-relations (`tags.name`) are eager loaded but large; search/sort on relation columns adds `whereHas` subqueries (`applySearchConstraint` uses `whereHas`).
4. Global search: docs warn "the category and author relationships will be lazy-loaded" unless `getGlobalSearchEloquentQuery()` returns `parent::getGlobalSearchEloquentQuery()->with(['author','category'])`. https://filamentphp.com/docs/5.x/resources/global-search
5. `getEloquentQuery()` is the base for table, record pages and global search ("The resource's list table, record pages, and global search start their queries with the getEloquentQuery() method"): `->with()` there applies everywhere (possible over-fetch). Prefer `modifyQueryUsing` for list-only eager loads. https://filamentphp.com/docs/5.x/resources/overview
6. Navigation badges and widgets with `count()` queries on every page and on every update request that re-renders them: duplicate queries, not classic N+1.
7. Record re-resolution each update request plus relation managers re-querying owner data: identical queries repeated across components in one request.
8. Row-action `getTableRecord($key)` single-record `find` has no eager loads: action closures touching relations lazy-load there.
9. Laravel-level guards: `Model::preventLazyLoading()`, `Model::handleLazyLoadingViolationUsing()`, `Model::automaticallyEagerLoadRelationships()` exist in Laravel 12.x `Model.php` (lines 510/521/532) and `HasRelationships::withRelationshipAutoloading()`. Filament source does not call `preventLazyLoading` (grep over packages: none). Docs fetch for Laravel eloquent-relationships was truncated before those sections, so documented semantics are **[UNVERIFIED]**; only source existence is verified.

## 4. Hooks to observe Livewire

The official Livewire docs (4.x lifecycle-hooks and javascript pages) do **not** document PHP-side `Livewire::listen`, `ComponentHook` or `Livewire::componentHook` (zero mentions). They are public source-level APIs that Filament itself uses:
- `app('livewire')->componentHook(app(ScopedModelPropertiesComponentHook::class))` - `packages/panels/src/FilamentServiceProvider.php:99`; class extends `Livewire\ComponentHook` with `skip()`, `hydrate()`, `call(string $method)`.
- `Livewire::listen('hydrate', fn (Component $component, array $memo) => ...)` - `FilamentServiceProvider.php:122`, `WidgetsServiceProvider.php:28`.

Source: `livewire/src/LivewireManager.php` (`componentHook($hook)` -> `ComponentHookRegistry::register`; `listen($event,$cb)` -> `on()`; `current()` = last of `HandleComponents::$componentStack`; `isLivewireRequest()`), `ComponentHook.php`, `EventBus.php` (`on/before/after/trigger`; `trigger` returns a "finish" callable).

Event names and arguments (Livewire v4 main; `HandleComponents.php`, `HandleRequests.php`):

| Event | Args | Notes |
|---|---|---|
| `request` | `(array $requestPayload)` | once per update HTTP request, before any component; returns finish closure |
| `mount` | `($component, $params, $key, $parent, $htmlAttributes)` | initial render; v3: `($component,$params,$key,$parent)` |
| `hydrate` | `($component, $memo, $context)` | update requests |
| `update` | `($component, $path, $value)` | per property update; returns finish closure |
| `call` | `($root, $method, $params, $componentContext, $returnEarly, $metadata, $idx)` | per action; returns finish closure taking the return value. Wrap with timing to attribute queries to component+method. v3: `($root,$method,$params,$context,$returnEarly)` |
| `render` | `($component, $view, $properties)` | returns finish closure |
| `dehydrate`, `destroy`, `exception`, `renderIsland`, `response` | see source | `response` once per HTTP request |
| `profile` | `($name, $id, [$start,$end])` | **only emitted when `config('app.debug')` is true**; names: mount, hydrate, render, dehydrate, `call{idx}`, `child:{id}` |

`ComponentHook` has overridable `boot/mount/hydrate/update/call/render/renderIsland/dehydrate/destroy/exception` (and `skip()` as used by Filament) with `$this->component`. A hook is instantiated per component; `Livewire::listen` is global.

Other observation points:
- `DB::listen(fn (QueryExecuted $q))`: `->sql, ->bindings, ->time, ->toRawSql()`; `DB::whenQueryingForLongerThan($ms, fn ($connection, $event))` (cumulative per request). https://laravel.com/docs/12.x/database . Source (12.x): `QueryExecuted` also has `connection`, `connectionName`, `readWriteType`; `Connection::beforeExecuting(Closure)` exists (line 1062).
- JS: `Livewire.interceptMessage` / `Livewire.interceptRequest` (v4; `commit`/`request` hooks deprecated), `Livewire.hook()` global. https://livewire.laravel.com/docs/4.x/javascript . Not needed server side.
- Component/trait PHP hooks (`boot, mount, hydrate, updating, updated, rendering, rendered, dehydrate, exception`; trait form `mountTraitName`): https://livewire.laravel.com/docs/4.x/lifecycle-hooks . Only for code you own; not a global observer. The page does not document a `booted` hook.
- Filament: `DispatchServingFilamentEvent` persistent middleware (runs on update requests too), `Panel::bootUsing()`, plugin `boot()`. Event class name **[UNVERIFIED]**.

Recommended capture strategy:
1. Root span: global/web middleware or `RouteMatched`; detect Livewire with `Livewire::isLivewireRequest()` (works with the hashed path).
2. `request` event: read `$requestPayload[*].snapshot` -> `memo.name`, plus `calls[*].method` and `updates` keys: component + methods known before execution (batched requests have several components).
3. `Livewire::listen('hydrate'|'update'|'call'|'render'|'dehydrate')`: push span {component class/name, `getId()`, phase, method}; pop in the finish closure; maintain a stack (`Livewire::current()` as fallback).
4. `DB::listen` reads the stack top for attribution and records time, sql, `toRawSql()`.
5. Register in a service provider `boot()` (as Filament does).
6. Feature-detect signatures: v3 `call` has 5 args, v4 has 7; use variadics.
7. Octane: Livewire has `SupportOctane`; reset your stack per request.

## 5. Attributing a SQL query to Filament Resource/Page/column

Combine two layers:
- **Livewire level** (section 4): gives component class (e.g. `App\Filament\Resources\PostResource\Pages\ListPosts`) plus phase/method. Resource = `$component::getResource()` for resource pages; relation managers expose `getOwnerRecord()`; widgets are their own class.
- **Call-site frames**: in `DB::listen`, `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, ~50)` (expensive: cap depth, sample, or only for slow/repeated queries). Walk from innermost: skip `vendor/laravel/framework`, `vendor/livewire`, `vendor/filament` and your package; the first frame under `base_path('app/')` (e.g. `app/Filament/Resources/...`, `app/Filament/Widgets/...`, `app/Models/...`) is the origin. Also keep the first vendor/filament frame to classify the trigger:
  - `Filament\Tables\Concerns\HasRecords::getTableRecords|getFilteredTableQuery` -> table list query;
  - `InteractsWithTableQuery::applyEagerLoading` -> eager-load; `HasCellState::getState*` / `Column::getState` -> per-cell lazy load (column name via `->getName()` on the frame object only if `DEBUG_BACKTRACE_PROVIDE_OBJECT` is enabled; costly, flagged queries only);
  - `Resource::getGlobalSearchResults` -> global search; `getNavigationItems`/`getNavigationBadge` -> nav badge;
  - `resolveRecord` / `ScopedModelPropertiesComponentHook` -> record re-resolution;
  - widget `getStats`/`getData` -> widget; `callSchemaComponentMethod` -> form field options/closure;
  - `Illuminate\Database\Eloquent\Concerns\HasAttributes::getRelationshipFromMethod` / `Model::getRelationValue` / `lazyLoadRelation` -> **lazy load** marker (names from Laravel source; exact frame names not re-verified). Alternative: `Model::handleLazyLoadingViolationUsing($model, $relation)` if `preventLazyLoading(true)` is set.
- N+1 heuristic: group by normalized SQL (bindings stripped) + origin file:line within the same component phase; flag at count >= threshold, especially single-row `where id = ?` / `where fk = ?` repeated with a lazy-load frame. Eager-load queries have the `where id in (?, ?, ...)` shape and appear once.
- Duplicate-query check across components in one update request (nav badge + widgets + record re-resolve): identical SQL + bindings.

## 6. Laravel 11 vs 12 and compatibility notes

- Filament 3.x: Laravel ^10.45 to ^13, Livewire ^3.5. Filament 4.x: Laravel ^11.28|^12|^13, Livewire ^3.8.7. Filament 5.x: Laravel ^11.28|^12|^13, Livewire ^4.4.8. All accept Laravel 11 and 12 (composer.json files). Docs minimum for 4.x and 5.x: Laravel v11.28+, PHP 8.2+.
- Livewire main: illuminate ^10 to ^13, php ^8.1. Livewire 4 changes affecting a tracing package: hashed update URL `/livewire-{hash}/update`; `setUpdateRoute` callback gets `$path`; JS `commit`/`request` hooks deprecated for `interceptMessage`/`interceptRequest`; array `updating/updated` hook semantics changed; `call` hook has extra `$metadata,$idx` args. Source: https://livewire.laravel.com/docs/4.x/upgrading and source.
- Laravel 12: `DB::listen` and `whenQueryingForLongerThan` documented; `Connection::beforeExecuting` and `Model::automaticallyEagerLoadRelationships` exist in 12.x source. Laravel 12 requires php ^8.2. Laravel 13 exists (v13.39.0) and is listed in all three constraint sets.
- Laravel 11+/12 skeleton configures middleware in `bootstrap/app.php` (`->withMiddleware`) instead of `Http/Kernel.php`; a package should register via its service provider (`Router::pushMiddlewareToGroup('web', ...)` / `Kernel::prependMiddleware`). **[UNVERIFIED in this research; standard Laravel structure]**.
- Package constraints suggestion: `"php": "^8.2"`, `"illuminate/support": "^11.28|^12.0|^13.0"` (or lower if non-Filament users on Laravel 10 matter); Livewire/Filament optional (`suggest`), feature-detect via `class_exists(\Livewire\ComponentHook::class)` and `class_exists(\Filament\Panel::class)`; variadic event signatures.
- Not verified: behavioural differences between Filament 4 and 5 beyond the shared 4.x source read (5.x read for composer.json only). Livewire 3 arities come from a grep of trigger lines in 3.x `HandleComponents.php`.

## Sources
- https://filamentphp.com/docs/4.x/introduction/installation
- https://filamentphp.com/docs/5.x/introduction/installation
- https://filamentphp.com/docs/5.x/upgrade-guide
- https://filamentphp.com/docs/5.x/resources/overview
- https://filamentphp.com/docs/5.x/resources/global-search
- https://filamentphp.com/docs/5.x/tables/columns/overview (and 4.x)
- https://livewire.laravel.com/docs/4.x/upgrading
- https://livewire.laravel.com/docs/4.x/lifecycle-hooks
- https://livewire.laravel.com/docs/4.x/javascript
- https://laravel.com/docs/12.x/database
- https://laravel.com/docs/12.x/eloquent-relationships (fetch truncated before relevant sections)
- https://github.com/filamentphp/filament (4.x: packages/panels/{routes/web.php, src/FilamentServiceProvider.php, src/Panel/Concerns/HasMiddleware.php, src/Http/Middleware/SetUpPanel.php, src/Livewire/ScopedModelPropertiesComponentHook.php, src/Resources/Resource/Concerns/HasGlobalSearch.php}; packages/tables/src/Concerns/HasRecords.php, src/Columns/Concerns/InteractsWithTableQuery.php; packages/support/src/Concerns/HasCellState.php)
- https://github.com/livewire/livewire (main: src/Mechanisms/HandleRequests/{HandleRequests,EndpointResolver}.php, src/Mechanisms/HandleComponents/HandleComponents.php, src/ComponentHook.php, src/LivewireManager.php, src/EventBus.php; 3.x branch for v3 signatures)
- https://github.com/laravel/framework (12.x: Model.php, Connection.php, Events/QueryExecuted.php)

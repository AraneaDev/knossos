# Laravel

Knossos reads a Laravel project's routes, events, jobs, container bindings,
policies and observers from the source, without booting the application. It aims
at the static idioms of Laravel 10, 11 and 12.

A project counts as Laravel when any `composer.json` in the scan requires
`laravel/framework`, or when you list `laravel` under `frameworks` in your
[project configuration](../get-started/project-configuration.md). The PHP worker
then runs the Laravel collectors on top of the generic PHP facts: declarations,
inheritance, calls, construction and types are in the graph either way.

## What you get

| Area         | Recognized source                                                                                                                                                                                                                                                                                                                                                   | Graph facts                                                                       | Confidence                                        |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | ------------------------------------------------- |
| Routes       | `Route::get`, `post`, `put`, `patch`, `delete`, `options`, `any`, `match`, `view` (GET and HEAD), `redirect` and `permanentRedirect` (any verb), with a controller class or class-name string (its `__invoke`), a `[Controller::class, 'method']` array, a `'Controller@method'` string, or a method name inside `Route::controller(...)` or a `'controller'` group | `route` nodes and `routes_to` edges to the handler                                | certain for literals                              |
| Routes       | fluent `->name()` and `->middleware()`, and `prefix`, `name`, `middleware`, `namespace` and `controller` on nested groups, fluent (`Route::prefix('x')->group(...)`) or as an array (`Route::group(['prefix' => 'x', 'as' => 'x.'], ...)`); a group namespace qualifies the `'Controller@method'` strings inside it                                                 | the route's full URI and name, and `uses_middleware` edges                        | certain for literals                              |
| Routes       | `Route::resource` and `Route::apiResource` with a controller class or name, narrowed by `->only()` and `->except()`                                                                                                                                                                                                                                                 | one route per conventional action named `photos.index`, nested resources included | certain for literals                              |
| Roles        | a base class or contract such as `Illuminate\Routing\Controller`, `Model`, `ServiceProvider` or `ShouldQueue`                                                                                                                                                                                                                                                       | a `laravel.*` role                                                                | certain                                           |
| Roles        | the conventional directories (`Http/Controllers`, `Jobs`, `Listeners`, `Policies`, `Models`, `database/migrations` and a few more), wherever they sit                                                                                                                                                                                                               | a `laravel.*` role                                                                | probable                                          |
| Roles        | conservative class-name suffixes                                                                                                                                                                                                                                                                                                                                    | a role                                                                            | lower than the directory rule                     |
| Events, jobs | `SomeEvent::dispatch()`, `event(new SomeEvent)`, `dispatch(new SomeJob)`                                                                                                                                                                                                                                                                                            | `dispatches` edges                                                                | certain                                           |
| Listeners    | the `$listen` map of a service provider                                                                                                                                                                                                                                                                                                                             | `listens_to` edges                                                                | certain, with the listener kept in the attributes |
| Container    | `$this->app->bind`, `singleton` and `scoped` with two `::class` arguments                                                                                                                                                                                                                                                                                           | `binds` edges, with the contract in the attributes                                | certain                                           |
| Policies     | the `$policies` map of a service provider                                                                                                                                                                                                                                                                                                                           | `handles` edges                                                                   | certain, with the policy kept in the attributes   |
| Policies     | the abilities the Gate calls (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`, `before`, `after`) on a class in `Policies/`                                                                                                                                                                                                               | a `laravel.entry_method` role on those methods                                    | probable                                          |
| Observers    | `Model::observe(Observer::class)`                                                                                                                                                                                                                                                                                                                                   | `observes` edges, with the model in the attributes                                | certain                                           |

The same directory rule gives migrations, seeders and factories roles of their
own, and marks the methods Laravel calls on a job, listener, middleware, command
or policy (`handle`, `failed`, the Gate abilities and so on) as
`laravel.entry_method`, since nothing in your code calls them by name.

## Route names

A literal route name passed to `route()`, `to_route()`, `redirect()->route()`,
`URL::route()`, `URL::signedRoute()`, `URL::temporarySignedRoute()` or
`Redirect::route()` becomes an `endpoint` node that carries
the name, with a `calls_endpoint` edge from the enclosing method or function.
The core joins it to the route with that name, so `explain_flow` goes from a
controller's `redirect()->route('orders.show')` to the `show` handler.
`routeIs()` tests the current route and requests nothing, so it gives nothing,
and neither does a name built at run time. A route in `routes/api.php` gets the
`api` prefix by convention, and a route in any other file under `routes/`
has an unknown prefix, because a service provider may load it with one. A
`route()` call in a function outside any class belongs to that function. Blade
templates and Ziggy's `route()` are not read.
See [from request to handler](../concepts/request-to-handler.md).

## Limits

A route whose URI is not a literal is skipped with `LARAVEL_DYNAMIC_ROUTE_URI`.
A declaration the collector cannot read, such as a `match` with a dynamic method
list or a `resource` with a dynamic name, is skipped with
`LARAVEL_DYNAMIC_ROUTE`. A resource whose `only` or `except` list is dynamic
keeps every action, with the same diagnostic.

Knossos does not resolve route macros, providers changed at runtime, container
closures, cached or generated routes, package auto-discovery or any value that
needs the application to run. A binding or a map written in a way it cannot read
is left out rather than guessed.

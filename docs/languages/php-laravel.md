# Laravel

Knossos reads a Laravel project's routes, events, jobs, container bindings,
policies and observers from the source, without booting the application. It aims
at the static idioms of Laravel 10, 11, 12 and 13.

A project counts as Laravel when any `composer.json` in the scan requires
`laravel/framework`, or when you list `laravel` under `frameworks` in your
[project configuration](../get-started/project-configuration.md). The PHP worker
then runs the Laravel collectors on top of the generic PHP facts: declarations,
inheritance, calls, construction and types are in the graph either way.

## What you get

| Area         | Recognized source                                                                                                                                                                                                                                                                                                                                                   | Graph facts                                                                                     | Confidence                                                   |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| Routes       | `Route::get`, `post`, `put`, `patch`, `delete`, `options`, `any`, `match`, `view` (GET and HEAD), `redirect` and `permanentRedirect` (any verb), with a controller class or class-name string (its `__invoke`), a `[Controller::class, 'method']` array, a `'Controller@method'` string, or a method name inside `Route::controller(...)` or a `'controller'` group | `route` nodes and `routes_to` edges to the handler                                              | certain for literals                                         |
| Routes       | fluent `->name()` and `->middleware()`, and `prefix`, `name`, `middleware`, `namespace` and `controller` on nested groups, fluent (`Route::prefix('x')->group(...)`) or as an array (`Route::group(['prefix' => 'x', 'as' => 'x.'], ...)`); a group namespace qualifies the `'Controller@method'` strings inside it                                                 | the route's full URI and name, and `uses_middleware` edges                                      | certain for literals                                         |
| Routes       | `Route::resource` and `Route::apiResource` with a controller class or name, narrowed by `->only()` and `->except()`                                                                                                                                                                                                                                                 | one route per conventional action named `photos.index`, nested resources included               | certain for literals                                         |
| Roles        | a base class or contract such as `Illuminate\Routing\Controller`, `Model`, `ServiceProvider` or `ShouldQueue`                                                                                                                                                                                                                                                       | a `laravel.*` role                                                                              | certain                                                      |
| Roles        | the conventional directories (`Http/Controllers`, `Jobs`, `Listeners`, `Policies`, `Models`, `database/migrations` and a few more), wherever they sit                                                                                                                                                                                                               | a `laravel.*` role                                                                              | probable                                                     |
| Roles        | conservative class-name suffixes                                                                                                                                                                                                                                                                                                                                    | a role                                                                                          | lower than the directory rule                                |
| Events, jobs | `SomeEvent::dispatch()`, `event(new SomeEvent)`, `dispatch(new SomeJob)`                                                                                                                                                                                                                                                                                            | `dispatches` edges                                                                              | certain                                                      |
| Listeners    | the `$listen` map of a service provider                                                                                                                                                                                                                                                                                                                             | `listens_to` edges                                                                              | certain, with the listener kept in the attributes            |
| Container    | `$this->app->bind`, `singleton` and `scoped` with two `::class` arguments                                                                                                                                                                                                                                                                                           | `binds` edges, with the contract in the attributes                                              | certain                                                      |
| Policies     | the `$policies` map of a service provider                                                                                                                                                                                                                                                                                                                           | `handles` edges                                                                                 | certain, with the policy kept in the attributes              |
| Policies     | the abilities the Gate calls (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`, `before`, `after`) on a class in `Policies/`                                                                                                                                                                                                               | a `laravel.entry_method` role on those methods                                                  | probable                                                     |
| Observers    | `Model::observe(Observer::class)`                                                                                                                                                                                                                                                                                                                                   | `observes` edges, with the model in the attributes                                              | certain                                                      |
| Commands     | `$this->artisan('name')` in a test, `Artisan::call('name')`, `$this->call('name')`, `callSilent` and `callSilently` in a command, and an argv `[PHP_BINARY, 'artisan', 'name']`                                                                                                                                                                                     | `calls` edges to the `handle()` of the command whose `$signature` or `$name` declares that name | probable; dropped when no command of the project declares it |

The same directory rule gives migrations, seeders and factories roles of their
own, and marks the methods Laravel calls on a job, listener, middleware, command
or policy (`handle`, `failed`, the Gate abilities and so on) as
`laravel.entry_method`, since nothing in your code calls them by name.

## Laravel 13 conventions

Laravel calls some methods by name, so nothing in your code references them and
a plain reference graph would call them dead. Knossos marks these as
`laravel.entry_method`, with the reason in the classification attributes:

- **Observers.** A class under `Observers/`, or any class a model registers with
  `Model::observe()` or `#[ObservedBy]`, gets `laravel.observer`. Its event
  methods (`retrieved`, `creating`, `created`, `updating`, `updated`, `saving`,
  `saved`, `deleting`, `deleted`, `trashed`, `forceDeleting`, `forceDeleted`,
  `restoring`, `restored`, `replicating`) are entry methods. Other methods of the
  observer stay reportable.
- **Accessors and mutators.** A method returning `Illuminate\Database\Eloquent\Casts\Attribute`
  (`protected function excerpt(): Attribute`), and the legacy `get<Name>Attribute`
  and `set<Name>Attribute` pair on a model. The legacy names are recognised only
  on a class whose inheritance, through any number of project base classes,
  reaches `Model`, the auth `User`, `Pivot` or `MorphPivot`. The same name on
  another class is still reported.
- **Scopes.** A method marked `#[Scope]`, next to the `scopeX` naming that
  already worked.

## Livewire

A project counts as using Livewire when any `composer.json` in the scan requires
`livewire/livewire`, or when you list `livewire` under `frameworks`. Without
that, no Livewire fact or role is produced, even if the project has an
`app/Livewire` directory.

### What is recognised

- **Components.** A class whose inheritance, through project base classes and
  traits, ends at `Livewire\Component` gets `livewire.component`. That covers
  class-based components, abstract bases, and the anonymous classes of
  single-file components (`⚡name.blade.php`) and multi-file components
  (`⚡name/name.php` beside `name.blade.php`).
- **Routes.** `Route::livewire('/uri', Component::class)` is a route node like
  `Route::get`, with `GET|HEAD`, and a `routes_to` edge to the class. A component
  name such as `pages::post.create` stays the route's action label.
- **Hooks.** `mount`, `boot`, `booted`, `hydrate`, `dehydrate`, `updating`,
  `updated`, `rendering`, `rendered`, `render`, `exception`, `placeholder`,
  `rules`, `messages` and `validationAttributes`, the property hooks
  (`updatedTitle`), and the trait hooks (`mountHasSorting` in a used trait
  `HasSorting`). They get `livewire.entry_method` with reason `hook`.
- **Attributes.** A method with `#[On]`, `#[Computed]` or `#[Js]` is an entry
  method with reason `attribute`.
- **Blade actions and expressions.** In a component's view, `wire:click`,
  `wire:submit` and the other `wire:` directives that call a method (not
  `wire:model`, `wire:key`, `wire:loading` and the like), `$wire.method()` calls
  and `{{ $this->name }}` expressions mark the method as an entry method with
  reason `view_action` or `view_expression`, plus the view and line. Modifiers
  and arguments are fine, so `wire:click.prevent="save({{ $post->id }})"` names
  `save`. Livewire magics such as `$refresh`, `$set`, `$toggle` and `$dispatch`
  name nothing. A directive inside a Blade comment, an `@verbatim` block or an
  escaped `@{{ }}` is ignored. A directive on a component tag
  (`<x-button wire:click="notify">`) counts as well.
- **Includes.** `@include` chains are followed, cycles included, so a method
  used in a partial reaches every component whose view includes it.
- **Configuration.** `config/livewire.php` is read for `class_namespace`,
  `view_path`, `component_locations` and `component_namespaces`; a
  `resource_path()` value becomes a project-relative path.

A component's view is the literal view of its `render()` method when every
`return` names the same one, and otherwise the file its class name implies
(`App\Livewire\Admin\UserTable` is `admin/user-table.blade.php` under the
configured `view_path`, `resources/views/livewire` by default).

A name used in a view reaches only the components whose view graph holds that
view. Two components that both have `save` do not share it: the one whose view
never calls it stays reportable, which is how an unused action shows up.

### The warning

When a class-based component renders a view that is not among the scanned
files, the scan result carries

```text
LIVEWIRE_VIEW_UNRESOLVED: App\Livewire\X renders livewire.x, expected at resources/views/livewire/x.blade.php, which is not in the scan; its view actions are not counted.
```

The component and its hooks are still classified, but a method only its view
calls will read as unused until the view is found. Abstract components are
skipped, since they render nothing.

### Not covered

- **Volt** class and functional components written in the older
  `Livewire\Volt` style are not read.
- **`Livewire::addNamespace()` and `Livewire::addLocation()`** called from code
  are not evaluated. Only `config/livewire.php` is read, so a view in a
  directory registered at run time is not joined to its component.
- **Views that are not literals.** `view($name)`, or a `render()` that returns
  different views from different branches, gives no view join and no warning.
  The component, its hooks and its attributed methods are still classified.

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

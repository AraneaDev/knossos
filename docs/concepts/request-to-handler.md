# From request to handler

A frontend that calls `/api/orders/42` and the controller that serves it live in
different files, often in different languages. Knossos joins the two. A request
made in code ends at an `endpoint` node, a route is a `route` node, and a
`resolves_to` edge from the first to the second lets
[`explain_flow`](structure-analysis.md) and
[`impact_analysis`](structure-analysis.md) follow a request from the code that
makes it, through the route, into its handler:

```text
component --calls_endpoint--> GET /api/orders/{} --resolves_to--> GET /orders/{id} => show --routes_to--> OrderController::show
```

The join is made on every scan, after all files are read, so it stays correct
on an incremental scan too: when you move a route, the callers that were not
re-read are matched again.

## What becomes an endpoint

An `endpoint` node is a request made by code. Each caller below gives one, with
a `calls_endpoint` edge from the function or method that makes the call. A
queued request, such as an App Engine task, gives a `dispatches` edge instead,
the same distinction Celery submissions use.

| Language   | Callers                                                                                                                                                               |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| TypeScript | `fetch` and `axios.get`, `post`, `put`, `patch`, `delete`, `head` and `request`, with a literal URL or a template literal, also through an `axios.create` instance    |
| Python     | `requests` and `httpx` calls with a literal or f-string URL, `taskqueue.add` and `taskqueue.Task` from App Engine                                                     |
| Laravel    | `route()`, `to_route()`, `redirect()->route()`, `URL::route()`, `URL::signedRoute()`, `URL::temporarySignedRoute()` and `Redirect::route()` with a literal route name |
| Symfony    | `generateUrl()` and `redirectToRoute()` in a controller, `generate()` on a `UrlGeneratorInterface` or `RouterInterface`                                               |

A URL becomes a template: query string and fragment are dropped, a trailing
slash does not count, and every substitution (`${id}`, an f-string field) is
`{}`. A substitution inside a segment makes that whole segment a parameter.
Methods compare as written, `ANY` matches any method, and a `GET` route also
serves `HEAD`. A method that is not a literal, such as `request({ method: verb,
url })` or `requests.request(verb, url)`, gives an endpoint with method `ANY`.
A missing method keeps the default of the call: `GET` for axios `request`,
`POST` for a task.

A URL whose path starts with something Knossos cannot read, such as a leading
substitution or an axios instance with a missing, absolute or computed
`baseURL`, keeps the part it can read, gets a `uri` that starts with `{}`
(`{}/orders`) and `prefix: unknown`. Such an endpoint can only match by suffix.
A URL made only of substitutions gives no endpoint. A URL with a host in the
call itself is `external`: it is never matched and never reported.

A route's full template is its own path behind the prefixes of its `mounts`
chain: FastAPI's `include_router(prefix=...)`, Flask's
`register_blueprint(url_prefix=...)`, through any number of levels. A router
mounted in two places gives two candidates. A route whose chain cannot be
completed has an unknown prefix. So has a FastAPI or Flask route on a router the
scan cannot name, such as one built inside a function or handed in as a
parameter, and a Laravel route in a file under `routes/` other than `web.php`
and `api.php`, which a service provider may load with a prefix. A Flask
`register_blueprint(url_prefix=...)` replaces the blueprint's own `url_prefix`,
while FastAPI's `include_router(prefix=...)` adds to the router's.

A reference by route name is an endpoint too, keyed by the name. It joins by
name only.

## Grades

Each `resolves_to` edge carries a confidence and a `match` attribute that says
why.

| Confidence | `match`     | Condition                                                                                                                                                      |
| ---------- | ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `certain`  | `exact`     | Both prefixes known, every segment equal, no parameter on either side.                                                                                         |
| `certain`  | `name`      | A route-name endpoint whose name equals a route's, within one framework family.                                                                                |
| `probable` | `exact`     | As `exact`, but the route's prefix comes from a convention, such as Laravel's `api` prefix for `routes/api.php`.                                               |
| `probable` | `parameter` | Both prefixes known, and equal when a route parameter matches one caller segment. A one-segment URL matches an all-parameter route such as `/{slug}` this way. |
| `possible` | `suffix`    | The caller's template ends with the route's full template on a segment boundary. Covers an unknown prefix and a caller prefix such as a `baseURL`.             |

A `{}` segment in the caller matches only a route parameter, never a literal
segment. Only the suffix grade excludes an all-parameter route, and it needs at
least one literal segment in the route. Names match only within one framework
family, so a Laravel name never matches a Symfony route, and a name or route
without a framework never matches.

## How a route is chosen

For each endpoint:

1. Take the highest grade that has any candidate.
2. Within it, prefer the candidate with the most literal segments, so
   `/orders/new` beats `/orders/{id}`.
3. If one candidate is left, write `resolves_to`.
4. If several are left, write no edge and report `ENDPOINT_ROUTE_AMBIGUOUS`.
5. If there are none, report `ENDPOINT_ROUTE_UNMATCHED`.

The edge has `origin: derived`, because no single file states it. Its
`prefix_from` attribute lists the `mounts` edges whose prefixes were composed
into the route's full template, which is how you see where a prefix came from.

## Diagnostics

Both diagnostics carry the owner key `knossos.reconciliation:endpoint-routes`,
so `list_diagnostics` attributes them to the reconciliation and not to a
language scanner.

| Code                       | Severity  | When                                                                                                  |
| -------------------------- | --------- | ----------------------------------------------------------------------------------------------------- |
| `ENDPOINT_ROUTE_UNMATCHED` | `info`    | An endpoint that is not `external` matches no route, and the project has at least one route.          |
| `ENDPOINT_ROUTE_AMBIGUOUS` | `warning` | Several routes tie after the choosing rule. The message lists the canonical names of the tied routes. |

A project with no routes at all, such as a frontend that calls an API living
elsewhere, gets no unmatched diagnostics: there is nothing to match against.

## What changes in flow and impact

`explain_flow` and `impact_analysis` now follow `calls_endpoint` and
`resolves_to`, so you can pass either in `edge_kinds`. Three effects follow from
that.

- An endpoint can now appear as a hub, because callers and routes meet there.
  An endpoint belongs to no file: it is one node for every caller of the same
  URL, so the file-level views (fan-in, the components a changed file holds, the
  files a boundary spans) leave it out, as they do an `external_*` component.
  Two callers of one URL therefore do not depend on each other. They meet only
  at the endpoint, and a change to the route reaches both.
- Boundary policies do not see a request link (caller, endpoint, route). The
  endpoint belongs to no boundary and a policy judges single edges between
  components in boundaries, so a request from one boundary to a route in
  another is not reported. Flow, impact and usage follow it.
- Quality-gate deltas against a snapshot from before this change move once, by
  the edges that were not there. Compare against a fresh baseline.

The `edge_kinds` limit of the MCP tools follows the number of dependency kinds,
so it grew with them.

## Limits

- Hosts, proxies, rewrites and deployment are not read. A call is matched by
  its path, so two services with the same path in one project look like one.
- Django `include()` is not modelled. A route in the module `ROOT_URLCONF`
  names has a known prefix, a route in any other urlconf has an unknown one and
  can only match by suffix. The settings value is read as written, and a
  settings module nested below the scanned root still finds its urlconf.
- Laravel routes in `routes/api.php` get the `api` prefix by convention. A
  prefix changed in `RouteServiceProvider` or `bootstrap/app.php` is not read.
- A Django `re_path` pattern is not interpreted and takes no part in matching.
  The route is marked `regex` and still counts as a route, so a project with
  only such routes still reports unmatched endpoints.
- `require("axios")` instances, the `import google.appengine.api.taskqueue`
  module form, and a local variable that shadows `requests` or `httpx` are not
  read as clients.
- A literal `{}` in a plain string is read as a substitution.
- File-level views (a file's fan-in, the dependents and uses in `file_context`,
  and hotspots) do not show the frontend files that call a backend route file.
  Flow, impact and usage do.
- A mount onto an app imported from another module reads as unknown, and so
  does a Django settings module at `backend.settings` whose `ROOT_URLCONF` is
  `app.urls`.
- FastAPI sub-applications (`app.mount`), `{p:path}` parameters that span
  several segments, NestJS `setGlobalPrefix` and Symfony YAML route imports
  with a prefix are not modelled.
- Symfony generators inherited from a parent class or a trait, or typed on a
  closure parameter, are not recognised. The `Url` and `Redirect` facade checks
  go by the written short name and do not resolve an alias.
- Sessions and clients are out of scope: `requests.Session()` and
  `httpx.Client()` receivers, and PHP HTTP clients, give no endpoint.
- Blade and Twig templates, and Ziggy's JavaScript `route()`, are not read.
- A URL or route name built at run time gives no endpoint.
- The join stays inside one project. It does not cross independently indexed
  projects.

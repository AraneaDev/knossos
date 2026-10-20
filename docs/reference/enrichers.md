# External enrichers

An enricher is a process you install next to Knossos that adds project-specific
facts to a scanned graph: calls through a custom HTTP wrapper, routes registered
by a legacy convention, anything your own tooling knows and the language workers
cannot see. It speaks the [scanner worker protocol](scanner-protocol-v1.md),
receives the native symbols of the scan, and returns nodes and edges that go
through the same replace-and-prune lifecycle as a worker's facts.

Enriched facts are additive and always labelled. An enricher cannot change,
hide or delete a native fact, its facts never count as native in a metric, and
every answer that shows one says which enricher produced it.

## Registering an enricher

Two files take part, and they have different owners on purpose.

The enricher is the trust boundary. Knossos never runs code the repository ships,
but an enricher that imports the project's own modules (a Django URLconf mapper
will) runs repository code with the installation's privileges.

### The installation's `enrichers.json`

The installation defines which enrichers exist and how to start them, in
`enrichers.json` beside `roots.json`. That is `<KNOSSOS_DATA_DIR>/enrichers.json`
(by default `~/.knossos/enrichers.json`). When `KNOSSOS_ROOTS_FILE` points the
roots file elsewhere, `enrichers.json` moves with it, into that file's
directory.

`knossos enricher` manages the file for you, at the location `--db`,
`KNOSSOS_DATA_DIR` and `KNOSSOS_ROOTS_FILE` select, the same way they select
`roots.json`:

```sh
knossos enricher add endpoints --timeout=30 --env=ENDPOINTS_MODE=strict \
    --kinds=function,method -- python3 /opt/knossos-enrichers/endpoints.py
knossos enricher list
knossos enricher remove endpoints
```

- `add` checks the new entry by the same rules a scan reads the file with, and
  refuses a name that is already defined unless you pass `--replace`. A refusal
  exits 2 and leaves the file as it was. Everything after `--` is the command,
  so its own flags reach it unchanged. `--env` can be repeated.
- `--env=NAME=VALUE` puts the value in your shell history and in the `ps`
  output of anyone on the machine while the command runs. For a secret, add the
  enricher without it and write the value into the file by hand.
- `list` prints each enricher with its command, timeout, input kinds and the
  names of its `env` variables, never their values, followed by any problems in
  the file. `--json` prints `{"enrichers": [...], "problems": [...]}`. It exits
  1 when the file has problems.
- `remove` exits 1 when no enricher has that name.

`add` and `remove` refuse to touch a file that has problems, because rewriting
it would drop the entries a scan skips. Fix those first, or edit the file
yourself. Every write replaces the file atomically with mode 0600, since `env`
may carry a credential. When neither `--db`, `KNOSSOS_DATA_DIR` nor
`KNOSSOS_ROOTS_FILE` named the location, `add` and `remove` say so, because
the file they changed may not be the one your server reads.

You can also write the file by hand. This is what the `add` above produces:

```json
{
    "enrichers": [
        {
            "name": "endpoints",
            "command": ["python3", "/opt/knossos-enrichers/endpoints.py"],
            "timeout_seconds": 30,
            "env": { "ENDPOINTS_MODE": "strict" },
            "input_node_kinds": ["function", "method"]
        }
    ]
}
```

| Field              | Required | Meaning                                                                                 |
| ------------------ | -------- | --------------------------------------------------------------------------------------- |
| `name`             | yes      | Lowercase letter first, then up to 63 of `a-z`, `0-9`, `_` and `-`. Unique in the file. |
| `command`          | yes      | The argv to start, a non-empty list of non-empty strings. No shell is involved.         |
| `timeout_seconds`  | no       | 1 to 120, default 30. Bounds the whole run, shutdown included.                          |
| `env`              | no       | Extra environment variables, an object of strings.                                      |
| `input_node_kinds` | no       | The native node kinds sent to the enricher as input. Empty or missing sends none.       |

The process starts with the same allow-listed environment as a language worker
(`PATH`, `HOME`, the locale and temporary-directory variables, and a few
platform ones), plus your `env`. Nothing else from the server's environment
reaches it. Its standard error is captured and never passed through.

The file is read tolerantly. A broken entry is skipped and reported, and the
valid entries still run. A file that is not valid JSON, or not shaped
`{"enrichers": [...]}`, defines no enrichers for that scan, and the facts of the
enrichers a project requests are kept rather than removed (see
[Failure and carried-over facts](#failure-and-carried-over-facts)).

### The project's `enrichers` key

A project asks for enrichers by name, in its `knossos.json`:

```json
{
    "version": 1,
    "enrichers": ["endpoints"]
}
```

The project only ever names an enricher. A value that is not a valid name, such
as a command line or a path, makes the configuration invalid. A repository can
therefore request an enricher, never supply one, so scanning a repository never
runs code that repository ships. A requested name the installation does not
define is skipped with a warning, and the scan goes on.

## The protocol

An enricher is a worker as far as the wire goes: newline-delimited JSON-RPC 2.0
on standard input and output, protocol version `1.0`, output schema `1.0`. The
[protocol reference](scanner-protocol-v1.md) holds the framing, the limits and
the lifecycle. Two things differ.

**The `enrichment` capability.** The manifest your enricher returns from
`initialize` must list `enrichment` in `capabilities`. Knossos requires it
before it sends anything, so a language worker started by mistake fails cleanly
instead of being fed native nodes. Language workers never declare it. The
`languages` list must not be empty; any value will do, since an enricher scans
no files of its own.

**One `scan` request, with `native_nodes`.** Knossos sends a single `scan`
request whose params are the project root and the native nodes:

```json
{
    "root": "/home/you/shop",
    "native_nodes": [
        {
            "id": "symbol_6a01...",
            "kind": "function",
            "qualified_name": "backend.app.urls.search",
            "file": "backend/app/urls.py",
            "line": 6,
            "end_line": 7
        }
    ]
}
```

Only nodes of the kinds in `input_node_kinds` are sent. `id` is the node's
stable id in the graph, and it is how your edges refer to a native node. `file`
is project-relative. `file`, `line` and `end_line` can be `null` for a node with
no declaration of its own.

Answer with one or more `scan/contribution` notifications, then the `scan`
result (an empty object will do). Knossos then sends `shutdown`. Several
contributions are merged into one. The `owner_key` you send is replaced: every
enriched fact is owned by `enricher:<name>`.

Decoding is strict. One malformed node or edge fails the whole run, and the
facts from the enricher's last good run are kept.

## What an enricher may contribute

Every fact goes through a validation pass before it reaches the graph. A fact
that fails a check is dropped with a warning, and the rest of the contribution
is kept.

**Nodes.** A node kind must start with `ext:` and have something after it, such
as `ext:endpoint` or `ext:rest_call`. Native kinds are refused. Your `local_id`
is rewritten to `ext:<name>/<local_id>`, so it cannot collide with a native id
or another enricher's. An enriched node's language is `ext:<name>`. Sending the
same `local_id` twice with the same kind and name keeps one node; with another
kind or name, the second one is dropped.

**Edges.** Each end must be one of your own nodes (by `local_id`) or a native
node from `native_nodes` (by `id`). An edge may use any existing edge kind, plus
`represents`, except `contains`: nesting an enriched node under a native class
or file would make it count as a native member. An edge between two native
nodes that exactly repeats a native edge (same source, target and kind) is
dropped, and the native fact stands. Edges may start or end at native nodes,
and they are still yours: labelled, owned by the enricher and pruned with its
output.

**Evidence.** Every node and edge needs evidence (`path`, `start_line`,
`end_line`), and the path must be a file this scan discovered. A diagnostic that
cites an undiscovered file keeps its message and loses the citation.

**Origin and confidence.** The
[contribution schema](../../schemas/scanner/v1/contribution.schema.json) requires
`origin`, `confidence`, `evidence` and `attributes` on every node and edge.
Send `"origin": "enricher"`, and an `attributes` object, empty when you have
nothing to add. Every enriched fact ends up with origin `enricher` whatever you
declare. Confidence `certain` is reserved for native facts: a declared `certain`
is lowered to `probable`, and `possible` stays `possible`.

**Attributes.** Your attributes are kept. Knossos adds `enricher` (the name),
`enricher_scan_id` and `enricher_produced_at` (the scan and the time of the run
that produced the fact) to every node and edge, and strips `scanner` and
`scanner_local_id` from your node attributes, since the reconciler sets those.

**Diagnostics.** Your own diagnostics are kept, each under your code with a
prefix of its own (see [Diagnostics](#diagnostics)) and with `error` lowered to
`warning`, so an enricher can neither imitate a native code nor fail a quality
gate's error budget.

### `represents` links

A `represents` edge says "this enriched record stands for that native symbol".
It is how a flow crosses from an enricher's record into native code. Every check
below must hold, or the link is dropped and the rest is kept:

- the source is one of this enricher's own `ext:` nodes;
- the target is a native node of this scan, of kind `method`, `function` or
  `route`;
- the evidence cites the target's own declaration: its file, and a start line
  within the target's `line`..`end_line`;
- the source has no other `represents` link, so one record cannot fan out into
  several symbols.

A link that held when it was produced is checked again on every scan that
carries it over, against the native nodes of that scan. A renamed or moved
target drops it.

## How enriched facts show up

Answers about particular components include enriched facts, labelled with
origin `enricher` and the `enricher` attribute: `explain_flow`,
`impact_analysis`, `list_usages` and the component lookups.

- **Flow** follows `represents` from an enriched record to the native symbol
  and continues along that symbol's native edges. Every hop carries `step`:
  `identity` for a `represents` hop, `relation` for every other. An identity
  hop's explanation reads "X stands for Y", so it cannot be mistaken for a call.
- **Impact** on a native symbol walks back across its incoming `represents`
  links, so the enriched records and the callers that reach it through them show
  up as impacted. Impact hops carry the same `step` field.
- **`min_confidence: certain`** leaves every enriched fact out, since none is
  `certain`.

Everything that measures or judges the architecture counts native facts only:

- the node, relationship and diagnostic counts of `architecture_summary`, and
  the node and relationship totals of `export_agent_brief`;
- file fan-in, wherever it shows: the dashboard's fan-in list, complexity
  hotspots and component counts, churn, and the dependents a brief lists;
- `architecture_health`: hubs, hotspots, degrees and dead-code candidates (an
  enriched node is never a candidate, and an enriched edge never makes a native
  one look used);
- `dependency_cycles`;
- `check_architecture` and the boundary matrix: no policy judges native code by
  an enricher's guess, `represents` included;
- snapshots, and so `snapshot_diff`, `review_diff`, `quality_gate` budgets and
  `architecture_trends`.

The diagnostics an enricher owns (its own reports, the warnings for dropped
facts and those about its runs) stay out of those counts too: the
`error_diagnostics` and `warning_diagnostics` budgets, the gate's SARIF
results, the snapshot and trend figures and the summary's diagnostic count.
`list_diagnostics` still lists them. An enricher whose output changes with no
code change, or that fails, never shows up as drift and never fails a gate.

## Profiles

One codebase often ships in several configurations, such as two brands of one
application. An enricher can say which configuration an edge belongs to, and
`explain_flow` then never composes a path that no single configuration could
take.

**The attribute.** Put `profile` in an edge's `attributes`: a name, or a
non-empty list of names for an edge that is valid in several profiles. Each
name is 1 to 64 letters, digits, `_`, `.` or `-`. A list is kept deduplicated,
in your order. An edge without `profile` is unprofiled: its profile is unknown,
not wrong, and every path may cross it. An invalid value is removed, the edge
is kept as unprofiled, and the scan reports `ENRICHER_PROFILE_INVALID`. Names
are not checked against any declared list, so a typo shows up as a path bound
to a name you did not expect.

**Only enricher edges count.** Native edges, derived `implemented_by` steps and
`represents` links without a `profile` are all unprofiled. Shared native code
therefore never breaks a path's binding, and never changes it either. Profiles
apply to edges only, never to nodes.

**How a path is bound.** Each path keeps the set of profiles it is still valid
in. It starts unbound, and the first profiled edge it crosses binds it to that
edge's profiles. Every later profiled edge narrows the set to the profiles both
share. When they share none, that branch is cut off: the search does not follow
the edge, and counts the branch. An unprofiled edge leaves the set as it is.

**Pinning a profile.** Pass `profile` to `explain_flow` (`--profile=NAME` on
the CLI) to start every path bound to that one profile. A path then follows only
unprofiled edges and edges whose profiles include it. Without the argument, the
first profiled edge binds each path, as above.

**What the answer says.** Each path carries `profile`: the one name its own
hops bind it to, a sorted list when they leave it valid in several, or `null`
when no profiled edge bound it. `unprofiled_hops` lists the indexes, from 0, of
the hops whose profile is unknown, so you can see how much of the path the
profiles actually vouch for. Routes over the same components are shown as one
path, through the strongest of them. Its `profile` stays the one its own hops
prove, and `also_in` lists, sorted, the other profiles in which the same
components connect through different edges. A route that no profiled edge bound
adds nothing to `also_in`, and it is `[]` when there is nothing to add.

The response carries `profile_filter`: `requested` is the pinned profile or
`null`, and `pruned_branches` counts the partial paths a profile conflict cut
off. The search does not continue past a conflict, so it counts the branches it
cut, not the finished routes they might have led to. When the count is above 0,
the summary says so, for example "3 branches were cut off by a profile
conflict." With `both_directions`, the count belongs to the direction the answer
comes from. When the answer is the reversed one, `forward_pruned_branches`
counts the branches the forward search cut off, and the summary mentions them
too when there are any. Impact, usages, metrics, gates and snapshots ignore
profiles.

**An example.** The rest call in brand A's Vue component is tagged
`"profile": "brand_a"`, and it reaches a Django view through a `represents`
link. In brand B, the same view also calls an endpoint tagged
`"profile": "brand_b"`. A flow from brand A's rest call binds to `brand_a`,
passes the unprofiled `represents` link and the view's native code, and stops
before the brand-B call: that branch adds 1 to `pruned_branches`. A flow pinned
to `brand_b` never takes brand A's rest call at all. An edge tagged
`["brand_a", "brand_b"]` stays open to either.

## When enrichers run

Enrichers run on explicit scans only: the MCP `scan_project` tool, the CLI
`knossos scan` (unless you pass `--no-enrichers`) and `move_project`. Every
explicit scan runs them, even when no file changed, because Knossos cannot hash
the sources an enricher reads for itself. Enrichers run after the language
workers, each one once, and see only native nodes, never another enricher's
output, so their order does not matter.

Background scans never start an enricher: `refresh_if_stale`, the rescans the
plugin's hooks trigger, `knossos watch` and the shared watcher. They carry each
enricher's facts over unchanged, so enriched facts can lag native ones until the
next explicit scan. `enricher_produced_at` on every enriched fact says when it
was produced.

Changing an enricher's `name`, `command` or `input_node_kinds` in
`enrichers.json`, adding one or removing one changes the scanner set, so the
next scan is not taken from the no-change fast path. `timeout_seconds` and `env`
do not, since the facts do not depend on them.

## Failure and carried-over facts

An enricher never fails or aborts a scan, and never makes a rescan refuse to
commit. Native results commit normally whatever an enricher does.

When an enricher fails, or a scan does not run it, the facts from its last good
run are carried over: rebuilt from the graph and validated again against the
native nodes of this scan. An edge whose native end is gone is dropped, and so
is a `represents` link that no longer holds, each with a warning. Carried-over
facts keep the `enricher_scan_id` and `enricher_produced_at` of the run that
produced them, and the diagnostic for the scan repeats that time.

An enricher's facts are pruned, rather than kept, when the project no longer
requests it, or when `enrichers.json` loads cleanly and does not define it. If
the file has any problem, every requested name it does not define is carried
over, since the file cannot say whether the name is gone or merely rejected.
Pruning is a choice, not a failure.

## Diagnostics

Every diagnostic below is reported with source `enricher`, through
`list_diagnostics` like any other. Facts dropped by validation are warnings. An enricher's own `ENRICHER_REPORTED:*`
diagnostics are not re-emitted when its facts are carried over, so they disappear
on the next background scan while the facts stay.

| Code                          | Severity | Meaning                                                                                                                |
| ----------------------------- | -------- | ---------------------------------------------------------------------------------------------------------------------- |
| `ENRICHER_NATIVE_KIND`        | warning  | A node kind is not in the `ext:` namespace. The node is dropped.                                                       |
| `ENRICHER_CONFLICTING_ID`     | warning  | A `local_id` was reused for a node of another kind or name. The later node is dropped.                                 |
| `ENRICHER_UNKNOWN_ENDPOINT`   | warning  | An edge end is neither one of the enricher's nodes nor a native node of this scan. The edge is dropped.                |
| `ENRICHER_FORBIDDEN_KIND`     | warning  | A `contains` edge. It is dropped.                                                                                      |
| `ENRICHER_DUPLICATES_NATIVE`  | warning  | An edge repeats a native edge exactly. It is dropped, and the native fact stands.                                      |
| `ENRICHER_UNDISCOVERED_FILE`  | warning  | A node's or edge's evidence cites a file the scan did not discover. The fact is dropped.                               |
| `ENRICHER_REPRESENTS_INVALID` | warning  | A `represents` link failed one of its checks. The link is dropped.                                                     |
| `ENRICHER_PROFILE_INVALID`    | warning  | An edge's `profile` is not a valid name or list of names. The attribute is removed and the edge is kept as unprofiled. |
| `ENRICHER_REPORTED:<code>`    | as sent  | A diagnostic the enricher reported itself, with `error` lowered to `warning`.                                          |
| `ENRICHER_NOT_INSTALLED`      | warning  | The project requests a name the installation does not define. It is skipped and its facts are removed.                 |
| `ENRICHER_NOT_RUN`            | info     | A background scan did not run the enricher. Its previous facts are kept, and the message says when they were produced. |
| `ENRICHER_FAILED`             | warning  | The run failed. Its previous facts are kept, and the message names the reason and when they were produced.             |
| `ENRICHER_CONFIG_INVALID`     | warning  | `enrichers.json` could not be read, or an entry in it was skipped. The message names the entry.                        |

The reason a failed run's message names is one of these:

| Reason                     | What happened                                                                        |
| -------------------------- | ------------------------------------------------------------------------------------ |
| `missing_command`          | The command could not be started.                                                    |
| `no_enrichment_capability` | The manifest does not declare `enrichment`.                                          |
| `timeout`                  | The run took longer than `timeout_seconds`.                                          |
| `protocol_error`           | A version mismatch, a frame that is not valid JSON-RPC, or a malformed contribution. |
| `exit_nonzero`             | The process exited with a nonzero status before it answered.                         |
| `exited_early`             | The process exited cleanly, with status 0, before it answered.                       |
| `cancelled`                | The scan was cancelled while the enricher ran.                                       |
| `config_invalid`           | `enrichers.json` has problems, so the name may be undefined or rejected.             |
| `internal_error`           | Knossos itself could not handle the enricher. The message says what went wrong.      |

## A minimal enricher in Python

The enricher below knows one URL and the Django view that serves it. For that
view it contributes an `ext:endpoint` record and a `represents` link that cites
the view's declaration, so a flow from the endpoint continues into whatever the
view calls. A real enricher reads such facts from your sources or your own tool
instead of a table.

```python
#!/usr/bin/env python3
"""A minimal Knossos enricher: one ext:endpoint per known URL, linked to its view."""
import json
import sys

# What this enricher knows. A real one reads it from your sources or your own tool.
ENDPOINTS = {"/task/search/": "backend.app.urls.search"}


def send(message):
    sys.stdout.write(json.dumps(message) + "\n")
    sys.stdout.flush()


def facts(native_nodes):
    by_name = {node["qualified_name"]: node for node in native_nodes}
    nodes, edges = [], []
    for url, view_name in ENDPOINTS.items():
        view = by_name.get(view_name)
        if view is None or view["file"] is None:
            continue
        # Cite the view's own declaration: a represents link must.
        evidence = {"path": view["file"], "start_line": view["line"], "end_line": view["line"]}
        local_id = "endpoint:" + url
        nodes.append({
            "local_id": local_id,
            "kind": "ext:endpoint",
            "canonical_name": url,
            "display_name": url,
            "origin": "enricher",
            "confidence": "probable",
            "evidence": evidence,
            "attributes": {},
        })
        edges.append({
            "kind": "represents",
            "source": local_id,
            "target": view["id"],
            "origin": "enricher",
            "confidence": "probable",
            "evidence": evidence,
            "attributes": {},
        })
    return nodes, edges


for line in sys.stdin:
    request = json.loads(line)
    method, request_id = request.get("method"), request.get("id")
    if method == "initialize":
        send({"jsonrpc": "2.0", "id": request_id, "result": {
            "id": "example.endpoints",
            "version": "0.1.0",
            "protocol_version": "1.0",
            "output_schema_version": "1.0",
            "languages": ["ext"],
            "file_extensions": [],
            "capabilities": ["enrichment"],
        }})
    elif method == "scan":
        nodes, edges = facts(request["params"].get("native_nodes", []))
        if nodes or edges:
            send({"jsonrpc": "2.0", "method": "scan/contribution", "params": {
                "owner_key": "endpoints", "nodes": nodes, "edges": edges, "diagnostics": [],
            }})
        send({"jsonrpc": "2.0", "id": request_id, "result": {}})
    elif method == "shutdown":
        send({"jsonrpc": "2.0", "id": request_id, "result": {}})
        break
```

Install it in `enrichers.json` with `input_node_kinds: ["function"]`, add
`"enrichers": ["endpoints"]` to the project's `knossos.json`, and run
`knossos scan`. `explain_flow` from `/task/search/` to a function the view calls,
such as `backend.app.search.search_orders`, then shows the identity hop into the
view, followed by the view's call to it.

`tools/scanner-conformance --require=enrichment -- python3 endpoints.py` checks
the lifecycle: the manifest, an empty scan and the shutdown. Anything the script writes to
standard output must be a protocol frame; write logs to standard error.

# MCP tool reference

This file is generated from the live `ToolService` definitions; edit the source schemas, not this file.

## `server_info`

Where this server may read and what it is. Call this first in an unfamiliar setup, or whenever a path is rejected: it returns the allowed roots, the roots file to extend to add a project, the data directory, and whether the server runs in a container (where host paths are not its paths).

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `diagnose_runtime`

Check the runtimes, scanner workers, protocol, database, and migrations. Use when a scan fails for no obvious reason. Slower than server_info because it starts each language worker.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `list_projects`

Start here to find a project_id. Lists scanned projects with freshness and graph size so you can pick the right project_id before any other call.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `limit` | integer | no | minimum=1; maximum=100; default=50 | none |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 | none |
| `include_roots` | boolean | no | default=false | none |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `scan_project`

Build or refresh a project's architecture graph. Run this first for a new project, or when a query reports the graph is missing or stale.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `path` | string | yes | minLength=1 | Absolute path under a configured allowed root. |
| `name` | string | no | minLength=1 | none |
| `mode` | string | no | default="auto"; enum=auto, full, incremental | none |
| `max_files` | integer | no | minimum=1; maximum=100000 | none |
| `max_file_bytes` | integer | no | minimum=1; maximum=100000000 | none |
| `worker_timeout_ms` | integer | no | minimum=1000; maximum=120000 | How long a language worker may stay silent during one request, in milliseconds; every file it reports and every heartbeat restarts the wait, up to 120000 per request. Omit to use the project's knossos.json setting, or the built-in default (30000) when it is unset. |
| `snapshot_retention` | integer | no | minimum=0; maximum=20 | How many historical snapshots to keep. Omit to use the project's knossos.json setting, or the built-in default (5) when it is unset. |
| `boundaries` | array | no | maxItems=50 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `list_snapshots`

See a project's scan history. Use to find an older snapshot id to diff against or to check when it was last scanned.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=20 | none |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `snapshot_diff`

See what changed architecturally between two scans. Use after a rescan to review added/removed components and relationships instead of eyeballing a code diff.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `from_snapshot` | string | yes | minLength=1 | none |
| `to_snapshot` | string | no | minLength=1; default="active" | none |
| `max_changes` | integer | no | minimum=1; maximum=1000; default=25 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `quality_gate`

Check architecture budgets against a baseline in CI. Use to fail a build on regressions (new cycles, boundary breaks) rather than reviewing them by hand.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `baseline_snapshot` | string | yes | minLength=1 | none |
| `budgets` | object | yes | none | none |
| `policies` | array | no | maxItems=50 | none |
| `sarif` | boolean | no | default=false | none |
| `propose_baseline` | boolean | no | default=false | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_trends`

See how architecture metrics moved over recent scans. Use for release notes or to spot slow structural drift. Caches each snapshot's figures in the graph database; a very large snapshot is a `partial` point with a `reason`.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `limit` | integer | no | minimum=2; maximum=20; default=10 | none |
| `release_from` | string | no | minLength=1 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `find_component`

Locate a component by name when you are unsure of its exact canonical path, or by the file that declares it. Returns ranked candidates. Use before inspect_component when the name is ambiguous.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `name` | string | yes | minLength=1 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=20 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `inspect_component`

Get the full dossier for one component (its roles, boundary, containment, relationships, and evidence) in a single call. Faster than opening and cross-referencing several files by hand.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `component` | string | yes | minLength=1 | none |
| `max_relationships` | integer | no | minimum=1; maximum=100; default=25 | none |
| `max_children` | integer | no | minimum=1; maximum=100; default=25 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `list_usages`

List every usage site of a symbol with file:line evidence: one row per occurrence. Use instead of grepping for callers; where impact_analysis gives the transitive set, this gives the exact call sites.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `symbol` | string | yes | minLength=1 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `limit` | integer | no | minimum=1; maximum=500; default=100 | none |
| `exclude_tests` | boolean | no | default=false | Leave out usages from test code. |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_summary`

Get a one-call overview of the codebase by language, node kind, and relationship kind. Use to orient yourself in an unfamiliar project before drilling in.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=50 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `export_agent_brief`

Render a compact markdown orientation brief (boundaries, entry points, key hubs) sized for a CLAUDE.md/AGENTS.md section, so future agent sessions start pre-oriented without tool calls.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=1000; maximum=20000; default=4000 | Brief size budget; whole sections are omitted (and reported) to fit. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `file_metrics`

Find the largest or longest files. Use to spot refactor targets without shelling out to wc/find.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `path_contains` | string | no | minLength=1; maxLength=1000 | none |
| `language` | string | no | minLength=1; maxLength=100 | none |
| `sort_by` | string | no | default="line_count"; enum=path, line_count | none |
| `order` | string | no | default="desc"; enum=asc, desc | none |
| `limit` | integer | no | minimum=1; maximum=100; default=50 | none |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `untested_code`

List the files, or the classes and functions, no test reaches through the graph: where a change goes out with nothing checking it. Static, so a test reaching code through a framework or a callback is not seen.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `granularity` | string | no | default="files"; enum=files, components | files lists whole files no test reaches, largest first; components lists each class, function and method. |
| `path_prefix` | string | no | minLength=1; maxLength=1000 | Only list what sits under this project-relative path. |
| `limit` | integer | no | minimum=1; maximum=100; default=50 | none |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 | none |
| `max_nodes` | integer | no | minimum=1; maximum=200000; default=200000 | Most components the walk from the tests visits. |
| `timeout_ms` | integer | no | minimum=1; maximum=60000; default=5000 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `file_context`

One file's architectural context in one short answer: its boundary and the declared rules that bind it, how many files depend on it (and the closest few), the tests that reach it, and its latest commits. Call it before editing a file you have not read about, instead of grepping for its callers.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `path` | string | yes | minLength=1 | The file, relative to the project root or absolute under it. |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `list_diagnostics`

The scan diagnostics of the active graph (compiler and worker errors, warnings, notes) by file and line, with the scanner that recorded each. Filter by severity or path prefix.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `severity` | string | no | enum=error, warning, info | none |
| `path_prefix` | string | no | minLength=1 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=100 | none |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `list_annotations`

List durable annotations recorded on components, each with its author (user or model) and a component_status saying whether its component is present in the active graph or missing (renamed or moved; a missing one has a suggested_component when exactly one component added since a retained snapshot last held its name matches it by kind and short name, else null; suggestions are computed over the rows on the returned page), optionally filtered by component or kind.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `component` | string | no | minLength=1 | none |
| `kind` | string | no | enum=intended_boundary, confirmed_dead, false_positive, intentional, note, check | none |
| `limit` | integer | no | minimum=1; maximum=100; default=100 | none |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `explain_flow`

Answer 'how does A reach B?' Traces evidence-backed static paths between two components, more reliable than grepping call sites across layers.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `from` | string | yes | minLength=1 | none |
| `to` | string | yes | minLength=1 | none |
| `max_depth` | integer | no | minimum=1; maximum=12; default=6 | none |
| `max_paths` | integer | no | minimum=1; maximum=20; default=5 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=3000 | none |
| `both_directions` | boolean | no | default=false | When from reaches to by no route, look from to to from as well; the answer then says reversed. |
| `profile` | string | no | none | Follow only paths valid in this profile (an enricher edge attribute, such as a brand): an enricher edge tagged only with other profiles is not followed, and unprofiled edges always are. Without it, the first profiled edge a path crosses binds that path. |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `impact_analysis`

Before editing a symbol, find everything that depends on it. Answers 'what breaks if I change this?' by following real static references, so it is more complete than grepping for callers.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `symbol` | string | yes | minLength=1 | none |
| `max_depth` | integer | no | minimum=1; maximum=8; default=6 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=100 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |
| `seed_members` | boolean | no | default=true | Start from a class's or module's members too, so a caller of a static method counts; each dependant found that way names the member as via_member. |
| `rings` | boolean | no | default=false | Add rings: the dependants counted one hop out, two, and three or more, tests counted apart, by path confidence. |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `dependency_cycles`

Find circular dependencies. Use before a refactor to see which modules are tangled, instead of tracing imports by hand.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `limit` | integer | no | minimum=1; maximum=100; default=20 | none |
| `max_nodes` | integer | no | minimum=1; maximum=200000; default=200000 | none |
| `max_edges` | integer | no | minimum=1; maximum=500000; default=500000 | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |
| `include_self_loops` | boolean | no | default=false | Include single-symbol self-recursion; excluded by default because recursion is not an architectural cycle. |
| `within_type` | boolean | no | default=false | Also list cycles whose members all sit in one class, interface, trait or enum, after the others, each marked within_type with its type; single-symbol recursion stays out. |
| `include_facades` | boolean | no | default=false | Keep the edges a re-export facade adds: re_exports edges and imports between a module and its own ancestor (a parent re-exporting its children while they import shared items back through it). Left out by default, so a real cycle between siblings stands out. |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_health`

Rank the structural hotspots, hubs, and likely-dead code. Use to decide where cleanup or extra test coverage pays off most.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `limit` | integer | no | minimum=1; maximum=100; default=20 | none |
| `max_nodes` | integer | no | minimum=1; maximum=200000; default=200000 | none |
| `max_edges` | integer | no | minimum=1; maximum=500000; default=500000 | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |
| `include_external` | boolean | no | default=false | Include external/unresolved symbols (builtins, vendor targets) in hubs and hotspots. |
| `include_tests` | boolean | no | default=false | Include test-role components in hubs and hotspots. |
| `candidate_confidence` | string | no | default="possible"; enum=probable, possible | Report only dead-code candidates at this confidence or above; probable leaves out framework-invoked members. |
| `candidate_offset` | integer | no | minimum=0; maximum=100000; default=0 | Skip this many dead-code candidates, to page past the first page; pagination.next_offset says where the next page starts, also when max_chars cut this one short. |
| `candidate_limit` | integer | no | minimum=0; maximum=1000; default=0 | How many dead-code candidates one page lists; 0 means the value of limit. At most 100, or 1000 with candidates_only. Set it to page through the candidates without paging the hubs and hotspots too. |
| `candidates_only` | boolean | no | default=false | Answer with the dead-code candidates alone, each as component, kind, path, line, reachability, confidence and reason_code, plus their tallies: no hubs, hotspots or histogram are computed or sent, and candidate_limit may go to 1000. |
| `path_prefix` | string | no | maxLength=1000 | Only list dead-code candidates under this project-relative path; the candidate counts then describe that part. |
| `include_annotated` | boolean | no | default=false | Also list the candidates a false_positive or intentional annotation hid, each with its annotation and hidden_by_annotation. |
| `include_untyped_receiver` | boolean | no | default=false | Also list the candidates whose only ground for doubt is a call by their name on a receiver the scan could not type (reason_code untyped_receiver), after every other candidate; left out by default and counted in bounds.excluded_untyped_receiver. |
| `include_examples` | boolean | no | default=false | Also judge example code (examples/, example/, samples/, demo/ outside a source root, docs/**/examples/) as dead-code candidates; left out by default and counted in bounds.excluded_example_components. |
| `candidate_timeout_ms` | integer | no | minimum=1; maximum=60000; default=5000 | Time budget for finding dead-code candidates across the whole project, separate from timeout_ms, which bounds the hub walk. When it runs out, bounds.candidate_truncation_reasons holds time_limit. |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `check_architecture`

Verify declared boundary rules still hold. Use to confirm a change did not introduce a forbidden cross-boundary dependency. Without `policies`, checks the ones the project declares in knossos.json.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `policies` | array | no | maxItems=50 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `limit` | integer | no | minimum=1; maximum=100; default=100 | none |
| `max_edges` | integer | no | minimum=1; maximum=500000; default=500000 | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `suggest_location`

Decide where new code for a feature belongs. Ranks existing boundaries by lexical and dependency fit so a new file lands in a cohesive place.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `feature_description` | string | yes | minLength=1; maxLength=2000 | none |
| `limit` | integer | no | minimum=1; maximum=20; default=5 | none |
| `max_members` | integer | no | minimum=1; maximum=50000; default=20000 | none |
| `max_edges` | integer | no | minimum=1; maximum=500000; default=500000 | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |
| `ranking_mode` | string | no | default="deterministic"; enum=deterministic, semantic_if_available | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `change_impact`

Blend static blast radius with recent Git churn to prioritize review. Use when you want impact ranked by risk, beyond the plain reachable set.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `symbol` | string | yes | minLength=1 | none |
| `since_days` | integer | no | minimum=1; maximum=3650; default=90 | none |
| `max_commits` | integer | no | minimum=1; maximum=5000; default=500 | none |
| `max_depth` | integer | no | minimum=1; maximum=8; default=4 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=100 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `changed_files_impact`

Map changed files to the components they affect, to scope review or tests to what a change touches. Pass either files, or working_tree: true (optionally with base_ref) to let Git supply them.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `files` | array | no | maxItems=50 | Explicit changed paths (repo-relative). Mutually exclusive with working_tree. |
| `working_tree` | boolean | no | default=false | Take the change set from Git instead of files. Required to use base_ref. |
| `base_ref` | string | no | minLength=1; maxLength=200 | Git ref to diff the working tree against. Requires working_tree: true. |
| `max_depth` | integer | no | minimum=1; maximum=8; default=4 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=100 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `test_impact`

Map a change set to the test files that statically exercise it, ranked by distance. Use to run the relevant tests first in an edit-test loop; the list is a lower bound, so the full suite still has to run. A `launches` hop (a test driving a script in another process) costs no depth; when the search stops at max_depth with dependants unread, bounds.truncation_reasons names depth_horizon.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `files` | array | no | maxItems=50 | none |
| `working_tree` | boolean | no | default=false | none |
| `base_ref` | string | no | minLength=1; maxLength=200 | none |
| `max_depth` | integer | no | minimum=1; maximum=12; default=4 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=100 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `review_diff`

One-call architectural review of a change set: blast radius, boundary-policy violations touching the change, quality-gate delta vs the last snapshot, and cycles the change participates in. Defaults to the working tree; policies/budgets default to knossos.json.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `base_ref` | string | no | minLength=1; maxLength=200 | none |
| `files` | array | no | maxItems=50 | none |
| `policies` | array | no | maxItems=50 | none |
| `budgets` | object | no | none | none |
| `baseline_snapshot` | string | no | minLength=1 | none |
| `max_depth` | integer | no | minimum=1; maximum=8; default=4 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=100 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_context`

Assemble a bounded, task-shaped evidence bundle (summary + likely location + impact + dossiers) for a coding task in one call. Use at the start of a task to load just-enough context cheaply.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | compact (default) trims evidence to a preview; full returns all evidence. |
| `project_id` | string | yes | minLength=1 | none |
| `task_description` | string | no | maxLength=2000 | none |
| `files` | array | no | maxItems=50 | none |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | none |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1500 | none |
| `include_source` | boolean | no | default=false | Inline bounded source snippets (≤40 lines each) for the included dossiers, read from the working tree. |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `export_diagram`

Render the current graph as Mermaid or PlantUML source. Use to embed an up-to-date architecture diagram in docs without a renderer.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `format` | string | no | default="mermaid"; enum=mermaid, plantuml | none |
| `boundary` | string | no | minLength=1 | none |
| `edge_kinds` | array | no | maxItems=23 | none |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible | none |
| `direction` | string | no | default="LR"; enum=LR, TB | none |
| `max_nodes` | integer | no | minimum=1; maximum=400; default=200 | none |
| `max_edges` | integer | no | minimum=1; maximum=1000; default=500 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `list_boundaries`

List the architecture boundaries and sample members. Use to learn how the codebase is partitioned before navigating it.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `source` | string | no | enum=explicit, inferred | none |
| `limit` | integer | no | minimum=1; maximum=100; default=50 | none |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `search_architecture`

Search components by name, attribute, role, or the text of a note on them, with structured filters. Use when you know a trait of what you want but not its exact name.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full | full returns all evidence; compact a preview. |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 | Result size budget; evidence is trimmed first. |
| `refresh_if_stale` | boolean | no | default=true | Rescan a stale graph first when it fits the latency budget. |
| `project_id` | string | yes | minLength=1 | none |
| `query` | string | yes | minLength=1 | none |
| `kinds` | array | no | maxItems=20 | none |
| `roles` | array | no | maxItems=20 | none |
| `boundary_ids` | array | no | maxItems=20 | none |
| `confidences` | array | no | maxItems=20 | none |
| `limit` | integer | no | minimum=1; maximum=100; default=20 | none |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 | none |
| `files` | boolean | no | default=false | Also list the project files whose path holds every term, and when the query names a file no component matches, what that file declares. |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `annotate_component`

Record, extend, confirm, move or remove your own (model) annotation on a component; it survives rescans and never changes a user note. false_positive and intentional remove the component from dead-code candidates. Preview by default; pass execute to apply.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 | none |
| `component` | string | yes | minLength=1 | none |
| `kind` | string | yes | enum=intended_boundary, confirmed_dead, false_positive, intentional, note | none |
| `value` | string | no | maxLength=2000; default="" | none |
| `evidence_paths` | array | no | maxItems=5 | Files your note rests on besides the component's own, scanned or not (a CI script counts); it is marked stale when one changes. |
| `remove` | boolean | no | default=false | none |
| `replace` | boolean | no | default=false | Replace a different model note stored here; without it such a write is refused and the stored note returned, so you can extend it instead. |
| `confirm` | boolean | no | default=false | You checked a stale note and it still holds: rest it on its files as they are now, keeping its text and cited files. Takes no value, evidence_paths, remove or replace. |
| `move_to` | string | no | minLength=1; maxLength=1000 | Move your note from component, a name the graph lost (list_annotations says missing, often with a suggested_component), to this component, keeping its text and the basis it was written against, so it reads stale when its component's code changed; check it and confirm it. Takes no value, evidence_paths, remove, replace or confirm. |
| `execute` | boolean | no | default=false | none |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `remove_project`

Delete a project and its stored graph. Preview by default; pass execute to actually remove. Use to clean up projects you no longer query.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 | none |
| `execute` | boolean | no | default=false | none |

Annotations: read-only `no`; destructive `yes`; idempotent `no`; open-world `no`.

## `move_project`

Carry a moved checkout's notes to the project its new root scans as, then remove the old project. Preview by default. Use when a scan warns that an orphaned project matches.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `from` | string | yes | minLength=1 | none |
| `to` | string | yes | minLength=1 | none |
| `execute` | boolean | no | default=false | none |

Annotations: read-only `no`; destructive `yes`; idempotent `no`; open-world `no`.

## `export_notes`

Write every annotation of the project at path to knossos-notes.json in its root (or file). Use to move notes to another machine, share them, or back them up.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `path` | string | yes | minLength=1 | none |
| `file` | string | no | minLength=1 | none |

Annotations: read-only `no`; destructive `no`; idempotent `no`; open-world `no`.

## `import_notes`

Import a knossos-notes.json into the project path scans as, later edit winning. Preview by default.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `path` | string | yes | minLength=1 | none |
| `file` | string | no | minLength=1 | none |
| `execute` | boolean | no | default=false | none |

Annotations: read-only `no`; destructive `yes`; idempotent `no`; open-world `no`.

## `cleanup_stale_scans`

Remove failed, cancelled, or abandoned scan records. Preview by default. Use for housekeeping when the scan history is cluttered.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 | none |
| `older_than_hours` | integer | no | minimum=1; maximum=8760; default=24 | none |
| `execute` | boolean | no | default=false | none |

Annotations: read-only `no`; destructive `yes`; idempotent `yes`; open-world `no`.

## `maintain_database`

Check integrity, or run a checkpoint, optimize, vacuum or backup of the graph store. Use for routine upkeep or before an upgrade.

| Input | Type | Required | Constraints/default | Description |
| --- | --- | --- | --- | --- |
| `action` | string | yes | enum=integrity, checkpoint, optimize, vacuum, backup | none |
| `execute` | boolean | no | default=false | none |
| `backup_name` | string | no | minLength=8; maxLength=127 | A plain filename ending in .sqlite, with no directory part; the backup is written inside the server's data directory. |

Annotations: read-only `no`; destructive `no`; idempotent `no`; open-world `no`.

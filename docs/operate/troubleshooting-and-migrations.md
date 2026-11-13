# Troubleshooting and migration guide

## First-response workflow

Start with the packaged runtime and structured output:

```sh
docker run --rm knossos:dev doctor --json
```

If scanning fails, keep the source mount read-only, enable JSON output, and
capture the stable diagnostic prefix from stderr. Consult the [fault recovery
matrix](recovery-matrix.md) before deleting or rebuilding data. A worker error
normally preserves the last active snapshot; a corrupt derived contribution
cache is rebuilt by the next full scan.

For stale results, compare the stored scanner and configuration fingerprints,
then request one explicit full scan:

```sh
docker run --rm --network none \
  --mount type=bind,source=/absolute/project,target=/workspace,readonly \
  --mount type=volume,source=knossos-data,target=/data \
  knossos:dev scan /workspace --mode=full --json
```

Do not repeatedly force full mode as a substitute for investigating a stable
diagnostic. Normal operation should use `auto`, which verifies fingerprints and
selects safe incremental work.

### What an incremental scan reuses

An incremental scan produces the same graph a full scan of the same files
would. It rescans every changed file, and every cached file that read a changed
file while it was scanned: an importer, a file that resolved a re-export, a
file that used a dependency's declarations, or a module that probed a path that
has since appeared. It follows those readers in turn, so a reader of a reader
is rescanned too. A TypeScript file is also rescanned when the global
declarations its program sees change, such as a script or an augmentation an
edit elsewhere imports into the program, and a TypeScript file added or
deleted rescans the whole language. A worker that does not report which files
it read has its whole language rescanned whenever any of that language's
inputs change.

You do not need to clear anything by hand when a worker changes. Each cached
result is keyed on a hash of the files of the worker that produced it, so
updating a worker invalidates its cached results on the next scan. There is no
limit on how many inputs a scan records for this, so a large `node_modules`
tree is tracked in full.

Full mode is not a way around a broken worker. When a worker fails during a
rescan and the graph already holds facts for its language, the scan aborts with
`WORKER_DEGRADED_INCREMENTAL` in every mode, `--mode=full` included, and the
graph stays as it was. Fix the worker (run `knossos doctor`) and rescan. When
the failed language holds no facts in the graph, for example because its worker
already failed on the first scan, there is nothing to protect: a `--mode=full`
rescan proceeds, degrades that language to a diagnostic, and updates every
other language.

## Common symptoms

| Symptom or code                 | Meaning                                                                                                                                                                                                                                                                            | Safe action                                                                                                                                                                      |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `KNOSSOS_SCAN_BUSY`             | Another writer owns the project scan lease; the message names its pid and how long it has held it.                                                                                                                                                                                 | A scan waits up to 30 seconds for that writer before it reports this. Wait for it, or stop its process; a lease whose process is gone is taken over by the next scan.            |
| `KNOSSOS_DATABASE_BUSY`         | A write, such as a note, met another process's write lock on the graph database, usually a scan committing. The CLI and the MCP tools give the same code and message.                                                                                                              | Nothing was written. Retry once the scan has finished.                                                                                                                           |
| `KNOSSOS_SCAN_CANCELLED`        | The client or signal cancelled work.                                                                                                                                                                                                                                               | Retry; the prior active snapshot remains available.                                                                                                                              |
| `KNOSSOS_SCAN_SNAPSHOT_CHANGED` | A file changed or was removed while the scan was running.                                                                                                                                                                                                                          | Rerun once writes to the tree have stopped; the message names the file. No graph row was written, so the previous graph is intact.                                               |
| `KNOSSOS_SCAN_SNAPSHOT_CHANGED` | A discovered file stopped being readable while the scan was running.                                                                                                                                                                                                               | Waiting does not help: make the named path a readable file again, then rerun. The message wording tells this from a rewrite.                                                     |
| `KNOSSOS_UNSAFE_PATH`           | Root, symlink, ignore, count, or byte limits rejected input, or the project configuration is invalid (a `knossos.json` that is not valid JSON or breaks its schema). The CLI and the MCP tools give the same code.                                                                 | Correct the path or `knossos.json`; do not expand allowed roots blindly.                                                                                                         |
| `KNOSSOS_STORAGE_ERROR`         | SQLite is full, unwritable, or corrupt. The CLI prints the driver message; an MCP tool says only that the database failed.                                                                                                                                                         | Free capacity, run integrity, and restore an atomic backup if required.                                                                                                          |
| `GIT_HEAD_TREE_UNAVAILABLE`     | A scan warning: git could not list the tree of the scanned commit, and the warning gives the reason, such as a timeout. The graph is complete. The freshness check cannot answer from git for that snapshot, so it walks the project, and reports `unverified` above 20,000 files. | Fix what the reason names and rescan. Which files a scan reparses does not depend on this listing.                                                                               |
| `WORKER_BINARY_STALE`           | A scan warning: a compiled worker that ran in this scan was not built from the source beside it, usually after a `git pull`. The graph holds what the older binary found.                                                                                                          | Rebuild the worker (`tools/install`, or `cargo build --release --locked` and copy the binary into the worker's `bin/`), then rescan. `knossos doctor` reports the same mismatch. |
| Worker diagnostic prefix        | A scanner crashed, timed out, or violated its protocol/output limits.                                                                                                                                                                                                              | Inspect stderr diagnostics and the affected language file; other snapshots remain intact.                                                                                        |
| MCP `-32003`                    | The client sent a request before `notifications/initialized`.                                                                                                                                                                                                                      | Fix client lifecycle framing; send `notifications/initialized` first.                                                                                                            |
| MCP `-32600`, `id: null`        | A request id was not an integer or a string (`null` included).                                                                                                                                                                                                                     | Send integer or string ids; MCP requires one of the two.                                                                                                                         |
| Server drops when idle          | The host closed a stdio connection that sat silent between calls.                                                                                                                                                                                                                  | None; `serve` pings every 25s. Ensure the client tolerates server `ping` requests.                                                                                               |

## Why a scan failed: `scan-failures.jsonl`

A scan that fails leaves a line in `scan-failures.jsonl`, beside the database in
the data directory. It keeps the last 200 lines, one JSON object each, oldest
first:

```json
{
    "at": "2026-11-03T22:27:26+00:00",
    "root": "/work/app",
    "started_by": "pane",
    "class": "killed",
    "message": "…",
    "elapsed_ms": 4100,
    "signal": 9
}
```

`started_by` is `pane`, `mcp`, `refresh`, `turn`, `watch` or `cli`. `class` is:

| class           | meaning                                                                                                                                         |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `killed`        | A scan or a worker ended on a signal. 9 and 15 usually mean the host ran low on memory (earlyoom sends 15); any other number is named as it is. |
| `lock`          | Another scan held the graph for the whole wait.                                                                                                 |
| `worker`        | A language worker failed on its own. Run `knossos doctor` and the worker named in the message.                                                  |
| `other`         | Anything else, including a PHP fatal such as memory exhaustion, with its message.                                                               |
| `timed-out`     | The hook wrapper stopped a scan at its bound (5 minutes by default).                                                                            |
| `brief-timeout` | The session brief hook ran out of time, with who held the graph when that is known.                                                             |

`knossos doctor` lists the failures from the last 24 hours under
`scan.failures`, newest first, and reports `none recorded` when the file is
empty or missing. Read the file itself with `tail -n 5 scan-failures.jsonl`;
a line that is not valid JSON is skipped by the doctor and safe to delete.

## Idle stdio connections

Some MCP hosts close a stdio connection that produces no traffic for around a
minute, which surfaces as the server disappearing between tool calls rather
than as an error. `knossos serve` therefore wakes on a 25-second interval and
emits a JSON-RPC `ping` whenever the connection has been idle, keeping the
transport warm. Only protocol revisions that still define `ping` are pinged,
and a client that has not yet said which revision it speaks is pinged too.

Each ping carries a fresh non-null id (`knossos-keepalive-N`) that the client
echoes back. Responses that arrive without a matching in-flight request
(including these keepalive replies) are ignored rather than treated as
protocol violations, so a client that answers late or not at all does not
break the session.

## Versioned database migration

Knossos migrations are forward-only, ordered, and recorded in
`schema_migrations`. Before replacing an image:

1. Record the current immutable image digest and run `doctor --json`.
2. Create and externally retain an atomic SQLite backup using
   `maintain-database backup --execute`.
3. Start the new image against a copy or staging volume first. Startup applies
   pending migrations transactionally and records their versions.
4. Run doctor, a representative scan, and an architecture summary before
   promoting the volume.
5. If verification fails, stop the new process and restore the pre-upgrade
   backup with the previous image. Never copy a live WAL/database pair by hand.

The first scan of each project after upgrading from a release that did not
record which files each cached result read rescans every file, even in `auto`
or `incremental` mode, and takes as long as a full scan. Scans after it reuse
the cache again.

The full quality profile automates this clean install, idempotent upgrade, and
verified rollback sequence through `tools/release-lifecycle`. Schema downgrades
are deliberately unsupported; rollback restores the complete pre-migration
backup. Bundle format and scanner SDK compatibility are versioned independently
and reject unsupported versions before mutating active data.

## Protocol and configuration migration

- Regenerate and review [CLI](../reference/cli.md) and [MCP](../reference/mcp-tools.md)
  references whenever command options or tool schemas change.
- Keep `knossos.json` on its declared schema version. Unknown keys and future
  versions fail closed; see [project configuration](../get-started/project-configuration.md).
- Third-party workers must pass the [scanner SDK](../reference/scanner-sdk.md) conformance
  runner before their protocol or output schema version is adopted.
- Exported graph bundles are immutable transfer artifacts, not database
  backups. Import validates their format, checksums, limits, and identities
  before one atomic activation.

# Performance budgets

Knossos ships a deterministic generated benchmark corpus and enforced budgets
for cold scans, incremental scans, representative queries, peak process-tree
resident memory, and SQLite size.

Run the benchmark in the pinned quality image:

```sh
docker build --target quality -t knossos:quality .
docker run --rm \
  --mount type=bind,source="$PWD/coverage",target=/opt/knossos/coverage \
  --entrypoint tools/benchmark knossos:quality
```

The `release` lane of the full profile runs the same command. Results are
written to `coverage/benchmarks/report.json`, or to the path you pass with
`--report=FILE` or as the command's second argument; `--budgets=FILE`, or the
first argument, holds the run to another budget file. The `quality-reports`
artifact CI uploads comes from the `coverage` lane, so it does not carry this
report. Any exceeded budget makes the command exit `1`, and so does a budget
file with no sizes, since a run that measured nothing proves nothing. Invalid
benchmark configuration or an execution failure exits `2` or higher: every size
needs `files_per_language` as a whole number of at least 1 and each limit as a
JSON number of at least 0, and a missing or quoted one is named.

Peak RSS is read from `/proc`. Where `/proc` exists, a peak of 0 is a failed
measurement and fails the run. Where it does not, the command prints that the
peak was not measured.

## Corpus and measurements

Each size generates equal PHP, TypeScript, and Python dependency chains from a
fixed template. The report records a SHA-256 digest over sorted relative paths
and contents, making corpus drift reviewable. No generated corpus is checked in
or retained after the run.

For every size, the runner uses an isolated SQLite database and records:

- a full cold scan;
- a one-file edit followed by an incremental scan;
- an `architecture-summary` query;
- peak RSS for the CLI and its scanner-worker process tree; and
- the resulting SQLite database size.

Each cold and incremental result also carries `stages_ms` (reported as
`cold_stages_ms` and `incremental_stages_ms`) for configuration, discovery,
incremental planning, each language scanner, classification and boundary
analysis, and reconciliation. These in-process timers identify the responsible
subsystem without changing protocol output or requiring a profiler extension.
When a budget fails, read them first.

Budgets live in [`benchmarks/budgets.json`](../../benchmarks/budgets.json):

| size   | files per language | cold scan | incremental scan | query | peak RSS | SQLite |
| ------ | -----------------: | --------: | ---------------: | ----: | -------: | -----: |
| small  |                  6 |       10s |              10s |    1s |   512 MB |  12 MB |
| medium |                 30 |       20s |              15s |    1s |   600 MB |  24 MB |
| large  |                100 |       40s |              20s |    1s |   700 MB |  48 MB |

With three languages, the profiles generate 18, 90 and 300 source files. Limits
are intentionally high enough for shared CI runners but low enough to catch
hangs, lost incremental behavior, runaway workers, and gross storage
regressions. They are regression limits, not expected performance.

Timing and RSS values vary with hardware, load, filesystem, and cold runtime
caches. Compare trends from equivalent pinned runners. Tightening a budget
requires a successful report from representative CI; relaxing one requires the
before/after artifact and a reviewed explanation.

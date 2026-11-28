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

## Scenarios

A scenario is a fixed corpus of its own with one limit, under `scenarios` in
the same file. A rescan scenario edits the corpus's one production file after a
cold scan and scans again, five times, and holds the median incremental scan to
`incremental_scan_seconds`. A round that did not parse the files the edit
reaches fails the run, so a scenario cannot pass by timing a smaller rescan. An
on-request scenario times one on-request query five times after a cold scan
and holds the median to `query_seconds`; a round that answered less than its
corpus gives fails the run. The report lists each round under `scenarios`.

| scenario                               | corpus                                                      | incremental scan |
| -------------------------------------- | ----------------------------------------------------------- | ---------------: |
| `test_fidelity_rescan_one_file`        | one TypeScript class, five test files with 20 doubles of it |               8s |
| `test_fidelity_rescan_one_file_php`    | one PHP class, five test files with 20 doubles of it        |               2s |
| `test_fidelity_rescan_one_file_python` | one Python class, five test files with 20 doubles of it     |               4s |
| `test_fidelity_rescan_one_file_rust`   | one Rust trait, five test files with 20 mocks of it         |               3s |

| scenario                        | corpus                                                     | query |
| ------------------------------- | ---------------------------------------------------------- | ----: |
| `audit_tests_on_request_medium` | the `medium` profile with a test file per service, in git  |    3s |
| `check_fixtures_medium`         | the `medium` profile with three DTOs and 500 fixture files |    6s |

`test_fidelity_rescan_one_file` measures what test fidelity adds to a rescan:
the edit makes the production file and all five test files parse again
(`parsed_files` is 6 in every round) and every double judged.

Its enforced limit follows the size profiles' headroom, because the runner
that enforces it is a shared CI runner, not the machine it was first measured
on. `small` is the profile nearest the scenario's corpus (18 files against 6),
and its incremental limit is 3.89 times what one development machine measured
for it (10s against 2.57s). The scenario measured 1.91s in the same run, so its
limit is 1.91s × 3.89 = 7.43s, rounded up to a whole second as the size limits
are: 8s.

When test fidelity was added, it was accepted against the scenario on commit
`e9e156c2`, the commit before it, on one machine: 1.89s there and 1.90s with
it, within the 110% of base (2.079s) it was held to. Each of those two figures is the
median of three benchmark runs, and each run's figure is itself the median of
its five rounds. That comparison was a one-off acceptance measurement and is
not enforced; the 8s limit is.

`test_fidelity_rescan_one_file_php` measures the same for PHP, with PHPUnit
and Mockery doubles. The PHP worker parses the edited class alone
(`parsed_files` is 1 in every round): the five test files are reused, and
their twenty doubles are judged again against the changed class. Its limit
follows the same headroom: in one run `small` measured 2.68s against its 10s
(3.73 times) and the scenario 0.49s, so 0.49s × 3.73 = 1.83s, rounded up: 2s.
It was accepted against commit `de719356`, the base of PHP test facts, where
the PHP worker reported none: 0.492s there and 0.508s with them, within the
110% of base (0.541s) it was held to, each figure a median of three runs of
five rounds.

`test_fidelity_rescan_one_file_python` measures the same for Python, with
spec'd `unittest.mock` doubles, `patch.object()` and `patch()`. The corpus sits
at the corpus root, since the Python worker reads its source roots from the
scanned root. Each test file reads the class's module to resolve what it
patches, so the edit parses the class and all five test files again
(`parsed_files` is 6 in every round). Its limit follows the same headroom: in
one run `small` measured 2.69s against its 10s (3.71 times) and the scenario
0.82s, so 0.82s × 3.71 = 3.04s, rounded up: 4s. Against commit `9dd4b250`,
the base of Python test facts, where the Python worker reported none, it
measured 0.717s there and 0.809s with them, each figure a median of three runs
of five rounds, with the worker compiled from source on every run as a fresh
checkout does (the worker starts with `-B`, so it writes no bytecode). That is
112.8% of base, which was accepted against the 110% the plan held it to
(Ruling P22; gap G4-16). The difference is the worker's start: compiling the
test-facts modules adds about 70ms, while scanning the six files in the worker
takes less time than at base. Every install now compiles the worker's
checked-hash bytecode with the host's `python3` (`PythonBytecode`, the user's
decision U2), and the gap sweep measured it again on one machine, alternating
three trees with their own dependencies, four runs each of five rounds:
`9dd4b250` 0.753s, the sweep's head compiled from source 0.881s (117.0%), and
the same head with its installed bytecode 0.758s, 100.6% of base. The Python
worker's own stage (`scanner_python`) took 306ms, 400ms and 286ms.

`test_fidelity_rescan_one_file_rust` measures the same for Rust, with
`MockLedger` doubles that mockall's `#[automock]` generates for one trait,
configured through `expect_*` and `returning`. Like the Python corpus it sits
at the corpus root, where the Rust worker finds its Cargo manifest. Each test
file imports its mock from the trait's module, so the edit parses that module
and all five test files again (`parsed_files` is 6 in every round). A Rust
test file also reads every file of its package, for the macro-shadow check
(gap G5-05); here that adds no file, as `src/lib.rs` reads nothing the edit
changes. Its limit follows the same headroom: in one run `small` measured
2.75s against its 10s (3.64 times) and the scenario 0.68s, so
0.68s × 3.64 = 2.47s, rounded up: 3s. Against commit `8e91b83a`, the base of
Rust test facts, where the Rust worker reported none, two batches measured
0.670s there and 0.685s with them (102%), and 0.662s and 0.692s (104.5%);
with the macro-shadow answers memoised per file, 0.674s and 0.703s (104.3%).
Each is within the 110% of base it was held to, and each figure is a median
of three runs of five rounds, with each commit's worker built by
`cargo build --release --locked` on the same toolchain.

A cold scan by the Rust worker alone, over three Cargo projects of 106, 27
and 33 files, took 1.42s at that base and 1.70s with test facts (120%; the
106-file single package 0.69s and 0.85s). Before the macro-shadow answers
were memoised it took 2.16s: each standard assertion macro a test called
recorded every module and file of its package as a read again, so the cost
grew with calls times package files.

The on-request scenarios time what the on-request tools add to a project of
the `medium` profile's size. `tools/generate-on-request-corpus.php` writes that
profile byte for byte, then what each tool reads.

`audit_tests_on_request_medium` adds one test file per service and language,
each doubling the service's dependency with `vi.spyOn`, PHPUnit's `createMock`
or `patch.object`, and commits the corpus to git. It then edits the first two
services in every language, so `audit-tests --tier=all --scope=git-diff
--base-ref=HEAD` computes every on-request rule. Every round must list at least
the 24 items the corpus gives: 14 `OVER_MOCKING`, 9 `STALE_SINCE` and one
scan-tier `MOCKS_SUBJECT`. `check_fixtures_medium` adds a PHP class, a
TypeScript interface and a Python dataclass, and 500 JSON and YAML fixtures of
them, every tenth missing a field. Each round must check all 500 and report the
50 drifts.

Their limits follow the `small` profile's query headroom, since each times one
query: `small` measured 0.092s against its 1s (10.87 times). On one
development machine with ext-ffi loaded, as the quality image loads it, the
two scenarios measured 0.226s and 0.478s in the same run, so their limits are
0.226s × 10.87 = 2.46s and 0.478s × 10.87 = 5.20s, rounded up: 3s and 6s.
Without ext-ffi, on the same machine, `audit_tests_on_request_medium` measured 0.122s, within
its limit. `check_fixtures_medium` measured 11.96s, over it. Without FFI, the
file opener starts a helper process for each file it reads, so as not to block
on a FIFO, and 500 fixture files then cost 500 processes. `knossos doctor`
reports the missing extension as `filesystem.opener`. Run the benchmark in the
quality image, as above, or with ext-ffi loaded and `ffi.enable=true`.

When on-request tools were added, the size profiles and the rescan scenarios
were measured on commit `6d4233c3`, the commit before them, and with them, on
one machine without ext-ffi. Each figure is the median of three benchmark runs,
and the two commits' runs alternated. No figure moved past noise: `small`,
`medium` and `large` cold scans took 102%, 98.5% and 99.5% of base, their
incremental scans 101%, 99.7% and 99.6%, and the four rescan scenarios 96.6%
(TypeScript), 102% (PHP), 101% (Python) and 103% (Rust). The workers also send
new optional attributes: field facts, enum cases, visibility and `async`, and
in TypeScript more `reads` entries. A cold scan of the Knossos tree (2056
files) cost each worker 100.7% (PHP), 100.5% (TypeScript), 103.5% (Python) and
102.4% (Rust) of base. Two more Cargo projects of 27 and 33 files cost the Rust
worker 96.4% and 96.2% (medians of 15 alternating scans). Each worker figure
is a median of alternating scans, three per commit unless said, with ext-ffi
loaded on both sides.

The test-findings reads that `architecture_summary` makes (the snapshot facts'
`NOT EXISTS` conditions) were measured on the `large` profile at `e9e156c2`, the
commit before the test-fidelity sweep, and at the sweep's head, on one machine
with ext-ffi loaded on both sides, alternating the two commits. Each figure is
the whole `architecture-summary` command (`query_seconds` of the benchmark
report), so it holds the process start as well as the query:

| Commit              | Runs | `query_seconds` per run      | Median |
| ------------------- | ---- | ---------------------------- | ------ |
| `e9e156c2` (base)   | 4    | 0.102, 0.102, 0.1123, 0.1122 | 0.107s |
| sweep head (Task 4) | 3    | 0.1021, 0.0917, 0.1121       | 0.102s |

The head took 95% of the base, so the query is not slower. The same runs'
cold scans took 23.5 to 23.9s on both sides. The `large` profile's graph is
small (an 8 MB database), so this shows no regression at that size; it does not
measure a graph near the 300,000-row archive cap.

Timing and RSS values vary with hardware, load, filesystem, and cold runtime
caches. Compare trends from equivalent pinned runners. Tightening a budget
requires a successful report from representative CI; relaxing one requires the
before/after artifact and a reviewed explanation.

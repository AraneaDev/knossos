# Coverage policy

Run the reproducible coverage profile with:

```sh
tools/quality-container full
```

Inside the pinned quality image, `tools/coverage` is the shorter coverage-only
entrypoint. The container wrapper mounts `coverage/` back into the checkout;
CI uploads that directory as the `quality-reports` artifact.

## Running in parts

`tools/coverage` with no argument runs the suite under pcov and then the
reports. The two halves can also run on their own, which is how CI splits the
suite over several jobs:

| command                            | does                                                                 |
| ---------------------------------- | -------------------------------------------------------------------- |
| `tools/coverage run [--shard=I/N]` | the PHPUnit run only; with `--shard`, only the test files of shard I |
| `tools/coverage merge RAW_DIR`     | gathers the data of every shard under `RAW_DIR` into `coverage/`     |
| `tools/coverage report`            | the reports and every floor below, over what `coverage/` holds       |

`tools/phpunit-shard` picks the files of a shard, heaviest first, each to the
shard with the least measured time so far, by the times in
`tests/phpunit-shard-weights.json`. The split depends only on the file names and
that file, so every runner computes the same one. The merge refuses unless every shard of the same N finished, left PHP
coverage data, and every test file ran in exactly one shard, the one it was
assigned to. A `*Test.php` with no runnable test, such as an abstract
base class, is not expected in any shard. The JavaScript and Python data merge by
union, so splitting cannot change those figures. For PHP, which lines count as
executable depends on load order: PHP folds a class constant into the code when
its class is already loaded, leaving no executable line. The merge therefore
counts a line only when every shard that loaded its file reports it, and as
covered when any of them hit it, so a different split cannot add an uncovered
line.

## Enforced floors

The floors live in `coverage-budgets.json`, and the number there is the
number that is enforced.

| Runtime                  | Line floor | Branch floor | Also enforced                       |
| ------------------------ | ---------: | -----------: | ----------------------------------- |
| PHP core and PHP scanner |      96.6% |          n/a | nine component floors, see below    |
| TypeScript/JavaScript    |        96% |        88.8% | 97.3% functions, 96% statements     |
| Python scanner           |      96.4% |      tracked | branch data is in the total         |
| Rust scanner             |      93.2% |          n/a | `cargo llvm-cov --fail-under-lines` |
| Claude Code mod          |      96.9% |        88.2% | 94.2% functions, 95.4% statements   |

PHP uses PCOV, which records executable-line coverage but not branch coverage.
The JavaScript V8 report therefore carries the explicit ratcheted branch floor,
and Python branch data is collected and included in coverage.py's enforced
total. Rust is measured by `cargo llvm-cov`. A well-covered runtime cannot hide
another, because all four gates must pass.

PHP also enforces a floor per component, so a well-covered area cannot carry a
neglected one:

| component             |  floor |
| --------------------- | -----: |
| `bundle-git-watch`    |  94.2% |
| `discovery-config`    |  97.2% |
| `maintenance-runtime` |  97.3% |
| `php-scanner`         |  96.3% |
| `query-analysis`      |  96.9% |
| `reconciliation`      | 98.49% |
| `scanner-runtime`     |  96.8% |
| `storage`             |  98.0% |
| `transport`           |  96.8% |

Floors may only move upward unless a reviewed risk exception is documented with
before/after evidence.

The file is checked before any floor runs. Every floor must be a JSON number
from 0 to 100. Every PHP component the run measures needs a floor, and every
component floor must name a component the run measured. A missing key, a quoted number such as
`"95"` or a value out of range stops the profile with exit 1 and names the key.
Each tool these floors are handed to reads a missing value as no floor at all,
so without the check a deleted line would pass every run. `tools/coverage
report --budgets=FILE` and `php tools/pcov-report.php --root=DIR
--budgets=FILE` hold a run to another file, which is how the tests plant a
broken budget without touching this one.

`skipped_tests` in the same file is the most PHPUnit tests a coverage run may
skip. A test that skips itself, because a tool is missing or the process runs as
root, passes without asserting anything, so the run counts the skipped tests in
its JUnit logs and refuses above the budget, naming every one. The merge counts
them over the shards (`--budgets=FILE` names another file), and an unsharded run
counts them with `php tools/skipped-tests.php`. The budget is 4, the count
measured on `main` at 391ea306 in CI's coverage shards. Three of them, in
`RepositoryCheckTest`, `DocumentationTest` and `GitIgnoreTest`, ask git which
files it ignores, which needs the checkout the image does not carry; the fourth,
in `GitProcessRunnerHardeningTest`, needs root, and the suite runs as the
`knossos` user.
Make a skipped test run rather than raise the budget.

[Quality gates](quality.md#profiles-and-lanes) explains why coverage is one lane. The
Claude Code mod (`hooks/lib`, `hooks/mod`) is measured by its own vitest suites
(see [how the mod is built](mod-internals.md#the-tests)) in the same profile, and
held to the `mod` floors in the table above: `tools/coverage` hands them to vitest
from the same budgets file, and a shortfall fails the run like any other floor.

`php tools/documentation-check.php` compares every number these tables and the
skipped-test budget above restate with `coverage-budgets.json`, so a floor
raised in the file without the page (or the other way round) fails the
documentation check.

The only first-party exclusions are `src/Application.php` and
`src/Runtime/Version.php`. The first is a CLI composition/dispatch adapter whose
invoked commands and services are covered through the CLI, MCP, and service
tests; the second holds only the version constant release-please bumps. Vendor code, installed
dependencies, generated reports, and test fixtures are outside the measured
first-party source sets. New exclusions require a documented rationale and a
reviewed configuration change.

## Reports

- PHP: `coverage/php/summary.json` plus console per-file and per-component line
  reports.
- TypeScript/JavaScript: `coverage/js/lcov.info`,
  `coverage/js/cobertura-coverage.xml`, and `coverage/js/index.html`.
- Python: `coverage/python/cobertura.xml` and
  `coverage/python/html/index.html`.
- Rust: `coverage/rust/coverage.json`, `coverage/rust/lcov.info`, and
  `coverage/rust/html/index.html`.

Add regression tests at the lowest useful layer, then run `composer test` for
fast feedback and the container coverage profile before pushing. `composer test`
runs the single PHPUnit suite (`vendor/bin/phpunit`); the coverage profile runs
it under the pcov prepend. Tests should assert behavior at protocol and safety
boundaries; touching a line without an observable assertion is not sufficient.
Threshold reductions or exclusions must include the before/after report and an
explicit risk rationale.

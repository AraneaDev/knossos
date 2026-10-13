# Agent evals

The eval asks Claude Code the same structural questions about pinned open-source
repositories three ways: without Knossos (`baseline`), with its MCP tools
(`tools`), and with its whole plugin (`full`). It measures which parts change
the answers, the cost and the number of turns. It is dev-only and never runs in CI.

## Before you start

The eval needs `php`, `rg` and `git` on the `PATH`, and a `claude` that is
logged in. Run `tools/install` from this checkout first: it materialises the
plugin in `.plugin`, and the `full` arm loads whatever is installed there, not
the source tree. `prepare` warns when the installed plugin version differs from
`version.txt`, so a stale install does not go unnoticed.

Each `continue` copies `~/.claude/.credentials.json` into the eval's own config
directory before its batch. Claude Code may refresh the OAuth token inside that
copy, and a refresh can rotate the token, which can leave the original logged
out. Log in again if that happens.

Sessions run read-only `Bash` commands (`rg`, `grep`, `ls`, `cat`, `head`,
`tail`, `wc`, `git log`, `git show`) as your own user, in a clone
under the eval root. The allowlist keeps them on task; it is not a sandbox.
Every arm also denies the options that would turn those commands into writers
or launchers: `git log` and `git show` with `--output` (or an abbreviation of
it, which git accepts) or `--ext-diff`, and `rg` with `--pre` or `--hostname-bin`. The deny
rules are wildcard patterns on the command line, so they cannot see through
quoting or a shell construct that assembles the option at run time. None of
the denied options has a short alias, so bundling flags does not get around
them. `git grep` is not allowed, because its `-O` launches a pager and can be
bundled; `rg` does the same searches.
Treat the allowlist as a guard against wandering, not as a boundary.

## Running one

```sh
node tools/eval/run.mjs start                 # prints a run id
node tools/eval/run.mjs prepare <run-id>      # clones, scans, seeds the isolated config
node tools/eval/run.mjs continue <run-id>     # one batch of 4 sessions
node tools/eval/run.mjs status <run-id>
node tools/eval/run.mjs ablate <run-id>       # after the first pass: one arm per used tool
node tools/eval/report.mjs <run-id>
```

`prepare --no-scan` clones the repositories and seeds the config without
scanning them, which is enough for a `baseline`-only run. A full `prepare`
writes each repository's scan time to `prepare.json` in the run directory, and
the report shows the total.

`continue` runs one small batch and appends each session to the ledger as soon
as it finishes. It stops early, with exit code 3, once the five-hour usage
window reaches 85% or a request is rejected; a session cut off that way is run
again by the next `continue`. Change the batch with `--batch` and the
threshold with `--max-utilization`. Exit code 3 means only that the window is
spent: wait for it to reset and run `continue` again. Exit code 1 is an error,
and 2 a usage mistake.

`ablate` adds a `full-minus-<tool>` arm for each tool the `full` arm used often
enough. It refuses while any `baseline`, `tools` or `full` session is still
pending.

To spot-check another model on a smaller scale, start a second run, for
example with Opus:

```sh
node tools/eval/run.mjs start --model opus --arms baseline,full --repeats 1
```

## Provenance

`start` records the Knossos commit, whether the checkout had local changes
(changes under `.superpowers/` and `tools/eval/corpus/` do not count), and the
version of the installed plugin. Every ledger row records the commit too, and
the report header shows all three. `continue` refuses to add sessions when the
commit or the plugin version has changed since `start`, because rows from two
builds must not be pooled. Pass `--force-provenance` to continue anyway.

## Environment overrides

| variable                   | default                                   | what it moves                                    |
| -------------------------- | ----------------------------------------- | ------------------------------------------------ |
| `KNOSSOS_EVAL_ROOT`        | `/var/tmp/knossos-eval`                   | clones, isolated config and home, the scan graph |
| `KNOSSOS_EVAL_RUNS`        | `~/.claude/superpowers/Knossos-MCP/evals` | runs, ledgers, transcripts, reports              |
| `KNOSSOS_EVAL_CLAUDE`      | `claude`                                  | the Claude Code binary                           |
| `KNOSSOS_EVAL_PLUGIN_DIR`  | `.plugin` in this checkout                | the plugin the `full` arm loads                  |
| `KNOSSOS_EVAL_REAL_HOME`   | your home directory                       | where the credentials are copied from            |
| `KNOSSOS_EVAL_SOURCE_ROOT` | this checkout                             | the repository whose commit a run records        |

The tests point all of them at temporary directories and a fake `claude`.

Sessions run with their own `CLAUDE_CONFIG_DIR` and `HOME` under
`/var/tmp/knossos-eval` (change it with `KNOSSOS_EVAL_ROOT`), so no user rules,
plugins, MCP servers or memory reach them, and each transcript's `init` event is
checked for leaks. The root stays outside your home directory, because Claude
Code loads `CLAUDE.md` files and `.claude` directories from every ancestor of
its working directory; the run refuses a root whose ancestors carry either, and
drops every inherited `CLAUDE*` and `ANTHROPIC*` variable. Runs, ledgers,
transcripts and reports go to `~/.claude/superpowers/Knossos-MCP/evals/`.

## Writing a task

Tasks live in `tools/eval/corpus/tasks/<repo>/<id>.json`. The answer key is
written and checked by hand: `node tools/eval/keygen.mjs <task-id>` lists
text-search candidates, and after the key is written it records where
Knossos disagrees. Knossos never writes a key.

## Tiers and calibration

A task may set `"tier": "hard"`; a task without the field is `easy`. The easy
tier holds one-hop questions, which both Sonnet and Opus answer at F1 near
1.0 with or without Knossos, so on its own it can show cost and speed but not
correctness. The hard tier holds questions one grep does not answer:
transitive impact out to entry points, a flow through a route, a queue, an
event or a container binding, and callers of a method whose name many other
types share.

`start --tier hard` (or `easy`) plans only that tier; without the flag a run
plans every task. A report for a run holding both tiers prints the summary and
the comparisons once per tier.

A hard candidate earns its place in a calibration run:

```sh
node tools/eval/run.mjs start --tier hard --arms baseline,full --repeats 1
node tools/eval/run.mjs prepare <run-id>
node tools/eval/run.mjs continue <run-id> --batch 2 --concurrency 1
node tools/eval/calibrate.mjs <run-id>
```

`calibrate.mjs` keeps a task when the average of its `baseline` and `full`
median F1 is at most 0.85. The rule reads both arms so a task one arm fumbled
by chance does not bias the next run toward the other. Delete each dropped
task's file and record why. Before a main run, the hard tier needs at least 15
tasks and at least 5 in each category it uses; write another round for the
thin shapes and calibrate those first. Calibration sessions are never reused
in the main run.

A run is checked against the tasks it planned, so adding hard tasks leaves
every earlier run open for `continue`, `status` and `report`.

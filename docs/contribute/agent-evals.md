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
`tail`, `wc`, `git log`, `git grep`, `git show`) as your own user, in a clone
under the eval root. The allowlist keeps them on task; it is not a sandbox.

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

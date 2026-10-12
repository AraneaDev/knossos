# Agent evals

The eval asks Claude Code the same structural questions about pinned open-source
repositories three ways: without Knossos (`baseline`), with its MCP tools
(`tools`), and with its whole plugin (`full`). It measures which parts change
the answers, the cost and the number of turns. It is dev-only and never runs in CI.

## Running one

```sh
node tools/eval/run.mjs start                 # prints a run id
node tools/eval/run.mjs prepare <run-id>      # clones, scans, seeds the isolated config
node tools/eval/run.mjs continue <run-id>     # one batch of 4 sessions
node tools/eval/run.mjs status <run-id>
node tools/eval/run.mjs ablate <run-id>       # after the first pass: one arm per used tool
node tools/eval/report.mjs <run-id>
```

`continue` runs one small batch and appends each session to the ledger as soon
as it finishes. It stops early, with exit code 3, once the five-hour usage
window reaches 85% or a request is rejected; a session cut off that way is run
again by the next `continue`. Change the batch with `--batch` and the
threshold with `--max-utilization`.

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

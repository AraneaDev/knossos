# The routing skill

The [plugin](plugin.md) carries two pieces that orient a session, and they do
different jobs. The [session brief](session-brief.md) tells a session what the
repository looks like at the moment it starts. The skill, `knossos:graph`
(`skills/graph/SKILL.md`), tells it which questions to bring back to the graph
for the rest of that session, and which to answer the ordinary way.

Nothing invokes the skill by name. Claude Code loads a skill when its
description matches the question in front of it, so what arms it is the pointer
line the brief always emits:

```text
Ask before grepping for structure: the `knossos:graph` skill.
```

The brief always prints that line, past its character budget if it must (see
[budgets](session-brief.md#budgets)): a brief that dropped it to fit would leave
the skill unarmed for the whole session.

## What it routes to the graph

Six shapes of question, each with the tool that answers it:

| About to                                     | Ask instead                                |
| -------------------------------------------- | ------------------------------------------ |
| grep for callers of a symbol                 | `list_usages`                              |
| guess what a change breaks                   | `impact_analysis`                          |
| read several files to trace a request path   | `explain_flow`                             |
| decide where a new file belongs              | `suggest_location`                         |
| eyeball a diff for architectural risk        | `review_diff`                              |
| work out what a class is and what it touches | `find_component`, then `inspect_component` |

Each row is a question an agent would otherwise answer by reading files and
inferring from them, which pays for the same derivation once per session and
returns a claim with nothing behind it. The graph returns a file and a line.

Every project-scoped tool needs a `project_id`. The brief has already put one in
context; `list_projects` returns it when the brief did not run.

## What it keeps away from the graph

The second half of the skill matters as much as the first, because a tool that
gets called for the wrong question wastes a call and hands back the wrong shape.
It names five cases and why each one belongs elsewhere:

- **Exact string or regex search**: a config key, an error message, a TODO. The
  graph indexes structure, so use grep.
- **Reading a file about to be edited.** Edit matches against exact bytes, so
  the file has to be read in full.
- **Runtime values or behaviour.** Scanning never executes anything, so the
  graph cannot say what a variable holds.
- **Confirming an edge labelled `probable` or `possible`.** Those are leads to
  check against the source.
- **Anything structural while the verdict is `STALE`, `UNVERIFIED`, `NO GRAPH`
  or `NOT SCANNED`.** Run `scan_project` first, or accept an answer that may
  describe a codebase that no longer exists.

## Writing a fact back

`annotate_component` is the one way a session leaves something for the next one,
and annotations are keyed by canonical name, so they survive a rescan. The skill
sets two standing rules, both to be followed before the session gives its
answer:

- When a Knossos answer left something out and the session found it by further
  queries, grep or reading, it records what the answer missed, so the next
  session starts from the note instead of digging again.
- When a result it used carries a model note marked `stale`, it settles that
  note: confirms it with `confirm: true` when it still holds, corrects and
  re-records it when it partly holds, and removes it when it no longer holds. A
  stale note by a person is theirs, so the session only says it may be out of
  date.

The skill also says what not to write. A note restating what the graph already
encodes, or describing the current branch, buries the one that records why a
boundary is process-enforced rather than conventional.

The tool previews by default and needs `execute: true` to write. `kind` is
`note` for a free-form fact; `intended_boundary`, `false_positive`,
`intentional` and `confirmed_dead` each change how other read surfaces behave,
so they mean only what they say. [Agent integration](../agents/agent-integration.md#component-annotations)
covers the mechanics.

## Where it lives, and when a change to it takes effect

The skill is `skills/graph/SKILL.md` in the repository. The Codex plugin's copy,
`plugins/knossos/skills/knossos/SKILL.md`, is generated from it by
`php tools/skill-copies.php`, and the quality gate refuses a stale copy, so edit
the source and regenerate. `install-agent-plugin --execute` copies it into `.plugin/`, so a change to
`SKILL.md` reaches a session only after that command runs again; see
[updating the plugin](plugin.md#update).

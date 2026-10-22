---
name: graph
description: Use when you need to know what depends on something, what a change would break, where a new file belongs, how a request reaches a given component, or who calls a function, in a repository that Knossos has scanned. Answers come from a static graph with file and line evidence instead of from reading the source tree. Also covers recording durable architectural facts for later sessions.
---

# Asking the graph instead of reading the tree

Knossos scans a repository once and answers structural questions from a graph
where every fact points at a file and a line. The point is to stop re-deriving
the same structure by reading the same files.

## Route these questions to the graph

| About to                                     | Ask instead                                                |
| -------------------------------------------- | ---------------------------------------------------------- |
| grep for callers of a symbol                 | `list_usages`, exact call sites with line numbers          |
| guess what a change breaks                   | `impact_analysis`, dependants with confidence and evidence |
| read several files to trace a request path   | `explain_flow`                                             |
| decide where a new file belongs              | `suggest_location`                                         |
| eyeball a diff for architectural risk        | `review_diff`                                              |
| work out what a class is and what it touches | `find_component`, then `inspect_component`                 |
| read a file's callers before editing it      | `file_context`, boundary, rules, dependents, tests         |
| grep for a term no symbol is named after     | `search_architecture`, which also searches note text       |

A `certain` row from `list_usages` is a fact, not a lead: the list holds every
static call the graph resolved, each marked `test` or not, and the summary says
what it cannot see (calls through a string or dynamic dispatch). Grepping for
the same symbol afterwards repeats work the graph already did.

Every project-scoped tool needs a `project_id`. The session brief puts it in
context already. If it is not there, `list_projects` returns it.

## Do not route these

Reaching for the graph here wastes a call and returns the wrong shape:

- Exact string or regex search, a config key, an error message, a TODO. Use grep.
- Reading a file you are about to edit. Edit needs the exact bytes, so use Read.
- Anything about runtime values or behaviour. The graph is static and never
  executes anything, so it cannot tell you what a variable holds.
- Confirming an edge labelled `probable` or `possible`. Those are leads to check
  in the source, not facts.
- Anything structural while the session brief says `STALE`, `UNVERIFIED`,
  `NO GRAPH` or `NOT SCANNED`. Run `scan_project` first, or accept that the
  answer may describe a codebase that no longer exists.

## Writing a fact back

`annotate_component` is the only way one session leaves something for the next.
Notes survive rescans, because annotations are keyed by canonical name rather
than by node id, and they come back with every later result that names the
component.

**The rule:** when a Knossos answer left something out and you found it by
further queries, grep or reading, record what it missed before you move on. That
the code holds the fact is no reason to skip it: the graph could not give it to
you, so the next agent will dig for it the same way, and a note is how the next
result carries it. A fresh session with the same question then starts from
your note instead of digging again. The session brief states this as a
standing instruction: writing one is part of the task, not a side effect to ask
about, so do it before you give your answer. Write nothing only when the answer was complete and your lookups
merely confirmed it.

**The second rule:** when a result you used carries a note marked `stale`, settle
it before you give your answer, the same way. `stale` means a file the note rests
on changed after it was written, so the note may no longer be true, and nobody
else will look: a stale note left alone stays stale, and once most notes read
stale none of them is trusted. You are already looking at that component, which
makes you the cheapest one to check it. Read the code the note describes, then:

- still true: re-record it with `annotate_component`, the same text and the same
  `evidence_paths`, which rests it on the files as they are now and clears
  `stale`. Leaving out `evidence_paths` drops the files it cited.
- partly true: correct the text and re-record it.
- no longer true: remove it (`remove: true`).

This holds for every stale model note on a component you looked up, not only
the ones you wrote. A stale note by a person (`author: user`) is theirs: say in
one line that it may be out of date, and leave it.

Write the fact on the component it is about, `kind: note`, and pass the files
you relied on as `evidence_paths`, so the note is marked stale when one of them
changes. A component Knossos did not resolve takes a note too, under its full
name: that it could not find it is the gap the next session would hit. Write the fact, not the question. If you already have a note there,
extend it rather than replace it: the preview shows its text, and a write that
would drop it is refused unless you pass `replace`.

A `check` annotation is the user asking you to look at a note again, its value
the reason: verify the note against the code and rewrite it, and your next note
on that component clears the request.

Keep a note brief: one or two sentences, the evidence in `evidence_paths`, not
in the text. A path reads best as a one-line arrow chain:

```text
File::delete -> FileDeleted (model event) -> DeleteFileInStorage::handle (queued)
```

Use a short multi-line ASCII sketch only when the path branches, and keep it
within the 1000 characters a result shows. No Mermaid or Markdown: nothing that
shows a note renders them.

`list_annotations` marks a note `component_status: missing` when no
component carries its name any more, after a rename or a move: write it again
on the new name and remove the old one. A note by a person (`author: user`) is
theirs: never rewrite or remove it, and never write through the pane's own
command (`knossos annotate`).

Worth writing:

- `BulkEditView::post`, "calls whatever BulkEditSerializer.validate_method returns, a function in documents.bulk_edit"
- `ToolCatalog`, "the extension seam is here; `ToolService` is the dispatcher and should not grow arms"
- `ProcessScannerClient`, "the worker boundary is process-enforced, not convention; in-process calls will pass tests and fail in production"

Not worth writing, and actively harmful because they bury the ones above:

- Anything the Knossos answer already gave you, or that one read of the file it
  pointed to settles. The next session gets the same answer and can read the
  same file.
- Anything about the current task, branch, or PR. Notes have no expiry.
- Restatements of what the graph already encodes, "this class implements that
  interface". The graph knows.
- Speculation, or anything you have not verified.

Mechanics: the tool previews by default. Pass `execute: true` to actually write.
`kind` is `note` for free-form facts. The other kinds change how other read
surfaces behave, so use them only for their actual meaning:
`intended_boundary` marks a deliberate placement, `false_positive` stops a
component being re-flagged because the graph was wrong, `intentional` stops it
because the finding is true and meant (a parked route, a test-only helper),
`confirmed_dead` records verified unused code.

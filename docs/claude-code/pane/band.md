# The band above the prompt

After a turn that edited files, one line above the prompt says what the turn changed and how far that reaches.

## The band

![After the turn, with the pane shut: the band above the prompt reads knossos, 1 file, 3 dependents, 20 tests, with details and hide](../../images/claude-code/band.png)

After a turn that edited files, one line above the prompt sums up the turn:

```text
knossos · 2 files → 37 dependents · 4 tests · as of 12s ago · reaching core, http +1
```

Read it left to right:

| part                      | what it counts                                                         |
| ------------------------- | ---------------------------------------------------------------------- |
| `2 files → 37 dependents` | the files the turn changed, and the files that depend on them          |
| `1 deleted`               | files the turn deleted, when there are any                             |
| `1 policy violation`      | boundary-policy violations the turn introduced; the band turns red     |
| `4 tests`                 | the test files that reach the change                                   |
| `as of 12s ago`           | how old the figures are; it keeps counting while the band is on screen |
| `reaching core, http +1`  | the two boundaries holding the most dependents, then how many more     |

When the project declares boundaries, only declared ones are named. The
boundaries come last, so a narrow terminal cuts them first.

While a scan runs, the band says `knossos · scanning…` and keeps the last
figures with their age (`· last: … · as of 2m ago`). A scan that fails says
`knossos · scan failed`, with the reason when there is one and the age of the
figures it kept.

`[ details ]` opens the pane. `[ hide ]` hides the band for the rest of the
session.

When the project's root is not allowed, the band names it instead of figures
(`knossos · not allowed: my-project`). The command that allows it does not fit
a band, so `[ copy ]` copies it whole:

```sh
KNOSSOS_ROOTS_FILE='/home/me/.knossos/roots.json' knossos allow-root '/home/me/my-project' --execute
```

`[ details ]` opens the pane, which offers to run it for you after asking.

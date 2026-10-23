# Live updates

How the pane and the band keep up with the code: the live watcher, the drifted
files, the toasts for new cycles and violations, and what the pane shows before
there is a graph.

## Drifted files

When files changed since the snapshot, the `drifted` tile counts them and `d`
lists them under the tabs, on any tab: each marked `+` added or `−` deleted,
with its boundary, the first twenty by path and how many more. `o` opens a
file's detail as the snapshot holds it, `e` opens the file. `d` again, or a
tab, hides them.

```text
Drifted since the snapshot                                 3
›  hooks/lib/changes.ts                            hooks
 + hooks/lib/files.ts
   src/Query/DashboardService.php                  core
```

## Toasts for new cycles and violations

When a scan brings a dependency cycle or a policy violation the graph did not
hold before, the mod says so in a toast, once per cycle or violation a
session:

```text
knossos: a new dependency cycle of 3: Router → Handler → Store → Router
knossos: a new policy violation (domain-isolation): Order → Db
```

A toast compares two snapshots of the same project, never the first graph a
session sees. A scan that brings more than three says the rest as one line:
`knossos: and 4 more new cycles or violations; the Issues and Cycles tabs list
them`. When the graph before the scan listed fewer cycles or violations than it
counted, only the count that grew is said. The `notifications` setting turns
these off.

## No graph yet

With no figures to draw, the pane says which of three things is the case:

| heading                     | meaning                                                                                                                       |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `Reading the graph…`        | a load is on its way, the first one or one after a load that failed                                                           |
| `Could not read the graph`  | knossos did not answer, or answered with an error, and no load is on its way; it asks again after 2, 5, then every 15 seconds |
| `No architecture graph yet` | knossos answered that it never scanned this project                                                                           |

A knossos that is still starting with the session often misses the first load.
The pane keeps saying `Reading the graph…` while it asks again, so a cold start
never reads as an error. Here, and on every other line that says something is
on its way (a blast radius, a branch comparison, a search, a note being
recorded), a spinner turns until the answer lands.

Only the last offers `q: ask Claude to scan it`. Your press submits:

```text
Scan this project with Knossos (scan_project), then give me a short summary of its architecture.
```

The press checks again before it sends anything, so a habitual `q` during a
slow first load sends nothing. The pane looks for the graph again at the end
of every turn until it finds one.

When the project's root is not allowed, the pane offers `a: allow root`
instead. That only asks: it shows the root it would allow, with `y: allow` and
`n: cancel`. Only `y` runs `knossos allow-root <root> --execute`, against the
roots file the installation names, and then scans the edits the refusal held
back. A grant that fails says why and can be asked again. When the plugin was
installed with no roots file baked in, it says to reinstall with
`knossos install-agent-plugin --execute`.

## The live watcher

Once a session has the dashboard of an allowed, scanned project, the mod starts
`knossos watch <project> --shared` and keeps it for the session's life. The
watcher polls the project and rescans what changed, whoever changed it. Each
scan it finishes reloads the pane.

- **One watcher per project.** Sessions that share a data directory share one
  watcher per project. The first leads; the others follow, scan nothing, and
  reload their panes when the leader's scan moves the graph. A crashed leader
  never blocks the project: the next follower takes over. The details are in
  [watch mode](../../operate/watch-mode.md#shared-mode).
- **Only an existing project.** It watches only a scanned project inside an
  allowed root, and never creates a database.
- **Stopping.** The watcher ends with the session and with a reload of the
  mod, and stops itself once the process that started it is gone. One that
  ends on its own is started again after 30 seconds, then 60, then 90. After
  three restarts in a row it stays off; the count starts over once a watcher
  comes up and leads or follows. A container install runs the watcher in a
  container of its own, attached to the docker client, so it stops when the
  client is stopped or goes away.
- **Stuck scans.** Each scan may run five minutes. Past that it is stopped,
  and killed three seconds later. A follower reports the leader as stuck when
  its heartbeat is more than a minute old.
- **The turn's brief.** The brief after a turn still runs. With a watcher
  running it waits for the watcher to take in the turn's last edits, then
  reads them from the graph instead of scanning again.

Between scans the watcher compares file stats instead of hashing every file,
and fingerprints the whole tree once a minute or when a stat moved. Each scan
runs in a process of its own, so the memory it peaks at goes back when it ends.

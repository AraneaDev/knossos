# Project and database maintenance

Maintenance operations are local, bounded, and explicit. Project removal,
stale-scan cleanup, checkpoints, optimization, vacuum, and backups default to a
dry run. Only the integrity check runs immediately because it is read-only.

Preview and then remove a persisted project:

```sh
knossos remove-project project_... --json
knossos remove-project project_... --execute --json
```

A project's id is its root path, so a checkout that moves (to a new home
directory, a new machine layout) scans as a new, empty project, and its notes
stay behind on the old id. The first scan of the new root says so when an old
project with the same directory name is gone from disk and at least half of
the components its notes name exist in the new tree. The warning names the
command that carries them over:

```sh
knossos move-project /old/path/project /new/path/project --json
knossos move-project /old/path/project /new/path/project --execute --json
```

`move-project` takes the old root or the old project id. It moves every
annotation, from either author, to the project the new root scans as,
scanning it first when needed, and then removes the old project. When both
sides hold a note on the same component, kind and author, the later edit is
kept and the other is listed in the result. The command also swaps the old
root for the new one in `roots.json`. The `move_project` MCP tool does the
same move but never edits `roots.json`, since that file decides what the
server may read: it refuses a new root outside the allowed roots and names the
`allow-root` command to run instead. Both refuse while the old root still
exists, because then the new path is a second checkout rather than a move.
Scan history, trends and quality-gate baselines start over with the new
project.

Preview abandoned, failed, or cancelled scans older than a threshold, then
remove only records that are not referenced by the active graph:

```sh
knossos cleanup-stale-scans project_... --older-than-hours=24 --json
knossos cleanup-stale-scans project_... --older-than-hours=24 --execute --json
```

Database-wide commands are:

```sh
knossos maintain-database integrity --json
knossos maintain-database checkpoint --execute --json
knossos maintain-database optimize --execute --json
knossos maintain-database vacuum --execute --json
knossos maintain-database backup --backup-name=before-upgrade.sqlite --execute --json
```

`vacuum` rebuilds the file and returns the free pages that deleting a project or
ageing out a retained snapshot left behind, then truncates the write-ahead log.
It reports `reclaimed_pages`, `freed_bytes` and `log_truncated`; when a
concurrent reader blocks the truncation, `log_truncated` is `false` and the file
has not shrunk yet.

Backups use SQLite `VACUUM INTO`, remove transient writer leases from the copy,
and are atomically published beneath the database directory's `backups/`
folder. Without `--backup-name` the file is called
`knossos-<UTC date>-<time>.sqlite`. A backup name must be a plain `.sqlite`
filename; directory components and overwrites are rejected. Open a backup with Knossos or SQLite and run
`PRAGMA integrity_check` before relying on it for recovery.

The equivalent MCP tools are `remove_project`, `move_project`,
`cleanup_stale_scans`, and `maintain_database`. The three deletion tools carry destructive annotations and
require `execute: true` to mutate state. Maintenance takes advisory writer
leases for every bounded project before checkpoint, optimize, vacuum, or backup work;
an active scan therefore causes a safe busy error instead of concurrent
maintenance.

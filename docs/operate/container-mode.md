# Container mode

How do I run an installed Knossos on a host that has Docker or Podman but not
PHP, Node and Python, and what can a container reach? This page covers the
installer's container mode. To run the image yourself with `docker run`, see
[Running in Docker](container.md).

## What it is for

The [installer](../get-started/installation.md#install-from-a-release) picks
container mode when the host lacks the native runtimes and a container runtime
answers. You can also ask for it with `--mode=container`. It needs Docker or
Podman with its daemon running, `curl`, `sh`, and `sha256sum` or `shasum`. It
needs no PHP, Node or Python on the host.

You install the same way and use the same `knossos` command, the same MCP
registration and the same plugin. The difference is where the code runs:
inside a container of the release image, `ghcr.io/araneadev/knossos:<version>`,
which the installer pulls, checks (it must start and pass `doctor`), and
records in `install.json` by digest.

Not tested yet: macOS (Docker Desktop) and Podman. The launcher is written for
both, and every check was run against Docker on Linux only. Two things are
untried there and are where to look first if calls are refused. The launcher
compares each mount source that `docker inspect` reports with the physical
path it asked for, and a runtime that reports a bind source in its own form
would make every call fail with `does not have the mounts that were checked`.
And a path holding a double quote is passed to `--mount` as a quoted CSV
field, which only Docker has read so far.

## How a call works

Every `knossos` call you or the plugin make goes through the launcher, a shell
script on the host. It works out the project root from the current directory
and the allow-list, then runs the command in the container for that project:

- **One container per project root.** It is started by the first call that
  needs it and reused by every call after it. Two projects get two containers,
  and two installations never share one.
- **Stopped after 30 idle minutes.** Idle means no call for 30 minutes and no
  call still running in it, so a running MCP server or watcher keeps it
  alive. A process that a command leaves behind does not. A stopped container
  is removed, and the next call starts a new one. A call that arrives in the
  moment the container stops fails once; run it again.
- **`knossos stop`** stops the container of the project you are in, or of
  `knossos stop <project directory>`. `knossos stop --all` stops every
  container of this installation. Both run on the host and start nothing.
  Stopping a container ends every call running in it: a scan stops part way
  (the last complete graph stays), and a running `knossos serve` or watcher
  ends, so Claude Code shows the server as disconnected. Two stops at once both
  succeed, since a container that is already gone is what a stop asks for.
- **The network is off**, and the container runs as your user and group, with
  `HOME=/tmp`.

Two directories belong to an installation, and they never overlap:

- **The install directory**, `~/.local/share/knossos` (or
  `$XDG_DATA_HOME/knossos`, or what you gave the installer with
  `--install-dir`). It holds everything the host reads, runs or trusts: the
  install record, the update helper, the plugin, the allow-list, the settings,
  the idle markers and the host's own temporary files. The launcher has its
  path baked in and never looks it up in the environment.
- **The data directory**, `~/.knossos` unless you chose another. It holds the
  graph and nothing else: every container can write it, and the host reads
  nothing in it.

The project, the data directory and the directory of a roots file you named are
mounted at their physical paths, the same inside as outside, so a path means
the same thing in both and the project keeps the id a native install would
give it. Through a symlink, the path inside is the physical one, not the one
you typed: `~/work`, a link to `/data/work`, is `/data/work` in the container.

An update or a rollback changes the image `install.json` names. The next call
starts a container from it. See [updating](updating.md#in-container-mode).

## What a container can reach

| Where                                                             | In the container              |
| ----------------------------------------------------------------- | ----------------------------- |
| The project root                                                  | read-only                     |
| The graph directory, `<data dir>`                                 | read-write                    |
| The install directory, `<install dir>`                            | read-only, a mount of its own |
| The directory of a roots file you named with `KNOSSOS_ROOTS_FILE` | read-only                     |
| Everything else on the host                                       | not mounted                   |

The install directory holds the allow-list (`roots.json`), the settings
(`settings/enrichers.json` and `settings/pane-autostart.json`), the plugin,
the install record (`install.json`), the update helper (`container-update`),
`update-check.json`, `containers/` (idle markers and graph records) and `tmp/`.
It lies in no read-write mount, so no container can change it or rename
anything around it.

### What this protects

- **Your project stays as it is.** It is mounted read-only, so nothing in a
  container can plant a git hook, an editor task or an agent setting in it. A
  project inside the data directory is refused, since every other container
  could write it there, and so is a data directory that holds anything
  Knossos did not put there (see [refusals](#refusals)).
- **The host's own files stay out of reach.** The install directory lies
  outside every data directory and is read-only to every container. The
  launcher checks at every call that it is the directory the installer made
  and that no graph directory of this installation holds it, and before it
  starts a container or runs the update helper, that no running Knossos
  container of another installation can write it.
- **The host never reads or runs a file a container can write.** The launcher,
  the plugin's hooks and the update helper take their input from the install
  directory only, and its own temporary files go there too, never to `TMPDIR`.
  Nothing in a graph directory is read, run or trusted. The one exception is
  the scratch copy of a file you asked a command to write, handled as
  [described below](#commands-that-work-differently).
- **A graph directory holds graph data only when a container starts with it.**
  Before any container starts with a graph directory, the launcher checks that
  it holds only what Knossos puts there, so no install directory of another
  installation, and nothing else of yours, is in its read-write mount at that
  moment. A container that already runs keeps its mount: an install directory
  put into its graph while it runs is caught by the installer, which asks the
  runtime about running containers, except in two cases. One is an install
  directory made there in the instant between that check and a container's
  start; the other is a container that is not Knossos's own (the launcher asks
  only about Knossos containers).
- **No network** inside the container.

### What it does not protect

- **One graph, shared.** Every project of one installation shares one graph
  directory, which every container can read and write. A hostile project could
  corrupt the graph data, notes and scan results of the others, and through
  them put text in front of your agent, in a session brief or an answer. It
  cannot run code on the host through the graph directory. Treat the graph as
  trusted by every project of the installation. Two installations that use one
  graph directory share it the same way: the data can be corrupted, and no
  code can run.
- **A container can keep new containers from starting.** Anything it leaves
  in the graph directory that Knossos does not put there makes the next
  container start for that graph refused, until you remove it (see
  [refusals](#refusals)). The host reads none of it.
- **Files you name are visible while the command runs.** A file you pass to a
  command from outside every mount, such as `--policies=<path>`, is copied into
  a scratch directory in the graph directory for the call. Another container
  can read it there and can swap it. An output file is staged there too, so
  another container can choose the bytes the host writes to the path you named. The file
  a command writes there has a random name, so no container can put a FIFO in
  its way before the command opens it; a file the command reads there (an
  input or a notes file in the way) can still be swapped for one, which
  blocks that command until you stop it (Ctrl-C reaches it). The host itself
  never opens either.
- **An enricher you register must not read or run anything from the graph
  directory.** The enricher runs in the container of the project being
  scanned, and any project's container can write the graph directory. The
  launcher refuses to add an enricher whose command names a path there, but it
  can only see a path given as a word of its own. It cannot see one built
  inside a shell command or an environment value. See
  [external enrichers](../reference/enrichers.md#in-container-mode).
- **The MCP tool `export_notes` cannot write into the project**, which is
  read-only. It answers that the project is read-only in container mode. Run
  `knossos export-notes` from a terminal instead.
- **A second path argument is not mounted.** `knossos move-project <old> <new>`
  gives the container the project it runs in, and the second path, the new
  location, is not mounted there. In container mode you name the old root by
  its absolute path, and the new one must be an existing directory.
- **Signals.** The runtime's exec client does not pass a signal on, so the
  launcher catches SIGINT, SIGTERM and SIGHUP itself and sends the same signal
  to the command in the container. Ctrl-C on a scan stops it there and the
  launcher exits with 130, SIGTERM with 143, and the same holds for
  `knossos serve`. SIGKILL cannot be caught: the launcher dies, the command
  keeps running in the container, and the exec client it started keeps its
  stdin open. A scan then runs to its end; `serve` ends when whoever started it
  closes its stdin. A signal that was already ignored when the launcher
  started, as SIGINT is for a job a script sends to the background, cannot be
  caught either.

## Refusals

The launcher refuses a layout in one line, before it starts or reuses a
container. Messages begin with `knossos:`.

- **A project, data directory or roots-file directory that is `/`, your home
  directory or a parent of it.** For a project:
  `the project root <path> is your home directory, which container mode does not mount; run knossos from inside a project.`
  (`is the filesystem root`, `contains your home directory`). For the data
  directory:
  `KNOSSOS_DATA_DIR (<path>) is your home directory, which container mode does not mount; point KNOSSOS_DATA_DIR at a directory of its own.`
  Run `knossos` from inside a project, and keep the data directory in a
  directory of its own, as `~/.knossos` is. A root that your allow-list lists
  and that is too wide is skipped, and the narrower folder you are in is used.
- **A data directory, install directory or launcher inside a project.**
  `the project root <path> contains the Knossos data directory <dir>, which a container must never be able to write; ...`,
  and the same for `the install directory <dir>` and for the launcher's
  directory. A project's files come from wherever it was cloned from, so
  nothing the host trusts may be part of one. Run from a project that does not
  contain it, or install again with `--data-dir`, `--install-dir` or
  `--bin-dir` outside the project.
- **A data directory inside another graph directory.**
  `KNOSSOS_DATA_DIR (<path>) lies in the graph directory <dir>, which a container can write; pick a data directory outside <dir>.`
  A container of that installation could swap a directory there for a link, so
  nothing is created or mounted through it.
- **A data directory that holds other things.** Every container can write the
  graph directory, so a new one must be empty, not exist yet, or hold only
  what Knossos puts in one, in the shape Knossos makes it (the database and its
  files, `scan-failures.jsonl` and `mcp-serve.log` as plain files, and
  `watch/`, `backups/` and `.knossos-files.*` holding plain files only; see
  [installation](../get-started/installation.md#a-data-directory-that-holds-other-things)).
  `KNOSSOS_DATA_DIR (<path>) holds <entry>, which is not something Knossos puts in a graph directory, and every container would be able to write all of it; choose an empty directory with KNOSSOS_DATA_DIR (or --data-dir when you install).`
  A native install's data directory is refused the same way, since the host
  runs its release and trusts its roots list and settings:
  `KNOSSOS_DATA_DIR (<path>) holds <entry>, which a native Knossos install keeps in its data directory and the host runs or trusts, ...`.
  The check runs the first time a directory is used as a graph; the installer
  checks the one you install into. Another installation's graph directory
  holds only graph data, so it passes, and the two installations share it.
- **A graph directory that came to hold other things.** Before every
  container start, the launcher checks the graph directory again:
  `the graph directory <dir> holds <entry>, which Knossos does not put there in that form, so no container is started with it: ... Remove <dir>/<entry> and run the command again.`
  A container of that graph, or another program, put it there. Nothing in it
  is read by the host. A container that is already running keeps working.
- **An install directory that a read-write mount holds.** The launcher refuses
  an install directory that lies in a graph directory of this installation, or
  in the read-write mount of a running container of another installation,
  before it starts a container or runs the update helper:
  `the install directory <dir> lies in <mount>, which container <name> of another Knossos installation can write; stop that container (docker stop <name>) and run the installer again with --install-dir set to a directory of its own, outside every Knossos data directory (the default is ~/.local/share/knossos).`
  A data directory that contains the install directory is refused too:
  `KNOSSOS_DATA_DIR (<path>) contains the install directory <dir>, which a container must never be able to write; ...`.
- **A project inside a graph directory.**
  `the project root <path> lies in the graph directory <dir>, which every container of that graph can write, so it would not stay read-only; keep projects outside the Knossos data directory, or pick another --data-dir.`
- **A roots file or a file to write or read inside a graph directory.**
  `KNOSSOS_ROOTS_FILE (<path>) lies in the graph directory <dir>, which a container can write; ...`,
  and for an `--out`,
  `<path> lies in the graph directory <dir>, which a container can write; write it somewhere else.`
  The host never opens a file there by a name a container could have changed.
  `knossos stop` applies the same check to the roots file. `TMPDIR` is never
  refused, because the host keeps its temporary files in `<install dir>/tmp`.
- **A folder that was once a graph directory.** Each graph directory the
  launcher has used is recorded in `<install dir>/containers/graph-<hash>`,
  and every refusal that names one says which record file it is. If that
  folder no longer is a graph directory, remove the record file to lift the
  refusal.
- **A roots file inside the project.**
  `KNOSSOS_ROOTS_FILE (<path>) lies inside the project root <root>, whose files the scanned project controls; unset it to use the list the installer keeps in <install dir>/roots.json.`
- **`HOME` unset.**
  `container mode needs HOME to point at your home directory, to keep it out of every container; set HOME and try again.`
  `stop`, `self-update` and `claim` run without it.
- **A path with a comma, a bar or a newline.**
  `a path with a comma, a bar or a newline cannot be mounted: <path>`
  Rename the directory or move the project.
- **`--db` outside the graph directory.**
  `--db names <path>, outside the graph directory <dir>; in container mode the database lives there, so point KNOSSOS_DATA_DIR at its directory instead.`
- **The runtime is not running.**
  `the docker daemon is not running; start it and try again.` A daemon that
  answers `info` but not `ps` refuses a container start and the update helper
  too, since the launcher cannot then tell whether another installation's
  container can write the install directory:
  `could not ask docker which containers run, ...`. Or
  `the image <image> is gone from this host: run knossos self-update to fetch it again, or re-run the installer.` The launcher never pulls an image behind
  your back.
- **A changed install directory.**
  `the install directory <dir> is not the one the installer made this launcher for (it was replaced, copied or restored); run the installer again with --install-dir=<dir> to repair it: your roots and enrichers are kept.`
  The launcher compares the inode it recorded at install time and trusts
  nothing in a directory put in its place. A backup you restored, or a copy
  made by a move to another disk, is fine once the installer has run again
  for it.
- **An update while a call starts.**
  `Knossos was updated while this call started its container, so that container was removed; run the command again.`
  The call read the record of the image before the update, so its container
  is removed rather than used.

A refused call creates nothing: the data directory, the directory of a roots
file, the graph record and the scratch directory are made only once every
check of the call has passed, its files included. What can still be refused
after that is the start of a container (the two checks before a container
starts, above) and the runtime itself, such as a daemon that stops.

## Commands that work differently

A few commands are carried out by the launcher on the host, because the thing
they change must be out of a container's reach.

- **`knossos allow-root`, `knossos claim` and `knossos move-project`** change
  the allow-list, `<install dir>/roots.json`. The launcher edits it, never a
  container. A `roots.json` in the graph directory is ignored, because every
  container can write it.
- **`knossos enricher add` and `knossos enricher remove`** change the enricher
  list, `<install dir>/settings/enrichers.json`, in a short run of the image
  that is given that one directory read-write and nothing else. `enricher list`
  and every scan read it read-only. Put the script an enricher runs in
  `<install dir>/settings`.
- **`knossos autostart <path> --set=on|off`** changes
  `<install dir>/settings/pane-autostart.json` the same way, with the project
  root read-only beside it so the command can find its repository.
  `knossos autostart <path>` without `--set` reads it in the project's
  container.
- **`knossos install-agent-plugin`** is refused:
  `This is a container install: its plugin is managed from the host. Run knossos self-update there, or re-run the installer.`
- **`knossos self-update` and `knossos stop`** run on the host. See
  [updating](updating.md).

A command that writes a file you name cannot write into the read-only project
from inside the container. The command writes into a scratch directory in the
graph directory, and the host moves the file to the path you named once the
command has succeeded. These options are handled this way:

- `export-bundle --output=FILE`
- `export-agent-brief --out=FILE`
- `export-notes --file=FILE` and `notes-export --file=FILE`, which write
  `knossos-notes.json` when no `--file` is given

Name the file as `--option=PATH`, as the CLI itself reads it. Before it reads
a byte, the host renames the file into `<install dir>/tmp`, where no container
can rename, replace or link it, and refuses a result that is not a plain file
with one link. So a container of the same graph can choose the bytes of the
file (its own bytes, the shared-graph case above), but it cannot make the host
write the bytes of another file, follow a link, or copy more than the limit.
This needs the install directory on the graph directory's filesystem: when it
is not, the output is refused, and the message says to put the install
directory there (`--install-dir`) or to use a data directory on its
filesystem. The destination must
be a plain file or a new one, in a directory that exists, not a link and not
inside the graph directory. A call writes one file.

The file may be at most 1024 MB. `KNOSSOS_OUTPUT_LIMIT_MB`, a whole number of
MB, changes the limit. A result over it is refused and nothing is written. Even
so, a command can succeed and the host still refuse its file. The launcher
then says why and exits with an error, and does not print the command's own
success line.

The other direction works too. A file a command reads that lies outside every
mount, for example `--budgets`, `--policies`, `import-notes --file` or the file
`import-bundle` is given, is copied into the scratch directory for the call.
Files inside the project are read through its read-only mount.

## When it does not start

- `knossos doctor` has an `installation` line that says
  `container <version> from <image>, installed in <dir>`, so you can see which
  image runs.
- Check that the runtime answers: `docker info`.
- `knossos stop --all` and a new call start a fresh container.
- Run the installer again with the same `--install-dir` (and `--data-dir`) to
  repair the installation. It keeps the allow-list, the settings and the graph
  records, writes the launcher again with the current inode of the install
  directory, and takes a new `--bin-dir` if you give one. That also repairs a
  deleted launcher and an install directory you restored from a backup.

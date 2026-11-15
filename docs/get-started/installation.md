# Installation

You install Knossos once and point it at as many projects as you like. There
are three ways in: the installer, which downloads a release and needs no
checkout; a checkout, for developing Knossos; and Docker by hand. All of them
end with an MCP server registered in your client and a data directory holding
the graph.

## Install from a release

Run the installer from the project you want to scan first:

```sh
cd /absolute/path/to/your-project
curl -fsSL https://github.com/AraneaDev/knossos/releases/latest/download/install.sh | sh
```

It needs `curl` and `sh`, and one of two things on the host:

- **Native mode**: PHP 8.3 or newer with the JSON, mbstring, PDO, PDO SQLite
  and tokenizer extensions (the PHP worker parses with tokenizer), Node 24 or newer, Python 3.11 or newer and Git, which is
  what `knossos doctor` checks, and `tar` and `sha256sum` or `shasum` to unpack
  and check the release. The release runs on the host. Release tarballs exist
  for Linux and macOS, on x86_64 and arm64.
- **Container mode**: Docker or Podman with its daemon running. The release
  runs in a container, one per project, and the host needs none of the
  runtimes above. See [container mode](../operate/container-mode.md).

### What the installer decides

Without `--mode`, it uses native mode when everything native mode needs is
there: each runtime at its minimum version, the PHP extensions, Git, `tar` and
a checksum tool. A host that would fail `knossos doctor` never gets native
mode. When something is missing and `docker` or `podman` answers `info`, it
uses container mode. Docker is tried before Podman. It prints what is missing
and says it is using container mode:

```text
This host cannot run Knossos natively. Missing:
  - the PHP extension(s) mbstring, pdo_sqlite (not loaded by php)
Using container mode: docker is available.
```

When something is missing and no container runtime answers, it stops before
it writes anything, names each missing item, and says what you can do:
install them, or install Docker or Podman and run it again with
`--mode=container`. `--mode=native` on such a host stops the same way.

Pass options after `sh -s --`, or download the script and run it:

```sh
curl -fsSL https://github.com/AraneaDev/knossos/releases/latest/download/install.sh | sh -s -- --mode=container --dry-run
```

| Option                     | Meaning                                                                                                                                                                      |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--mode=native\|container` | Skip the detection. A forced mode that the host cannot run stops with the reason.                                                                                            |
| `--version=V`              | Install this release instead of the latest.                                                                                                                                  |
| `--data-dir=DIR`           | The data directory. Default `~/.knossos`. See [what it writes](#what-it-writes) for what goes where in each mode.                                                            |
| `--install-dir=DIR`        | Container mode: the install directory. Default `$XDG_DATA_HOME/knossos`, else `~/.local/share/knossos`. Run the installer again with the same one to repair an installation. |
| `--bin-dir=DIR`            | Where the `knossos` launcher goes. Default `~/.local/bin`.                                                                                                                   |
| `--no-plugin`              | Skip the Claude Code plugin and the MCP registration.                                                                                                                        |
| `--no-roots`               | Do not allow the current directory.                                                                                                                                          |
| `--dry-run`                | Say what would happen and change nothing.                                                                                                                                    |
| `--force-clean`            | Remove the entries of the other mode that stand in the way (see [the pitfalls](#installation-pitfalls)). Never the graph itself.                                             |

The environment variables of the installer:

| Variable                   | Default                                | Meaning                                                                                               |
| -------------------------- | -------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `KNOSSOS_DATA_DIR`         | `~/.knossos`                           | The data directory, when `--data-dir` is not given.                                                   |
| `KNOSSOS_INSTALL_DIR`      | `~/.local/share/knossos`               | Container mode: the install directory, when `--install-dir` is not given. Read by the installer only. |
| `KNOSSOS_ROOTS_FILE`       | native: `<data dir>/roots.json`        | The roots file, when `--data-dir` is not given.                                                       |
| `KNOSSOS_BIN_DIR`          | `~/.local/bin`                         | Where the launcher goes.                                                                              |
| `KNOSSOS_ALLOW_ROOT`       | the directory you ran the installer in | The project to allow.                                                                                 |
| `KNOSSOS_MCP_NAME`         | `knossos`                              | The name of the registered server.                                                                    |
| `KNOSSOS_MCP_SCOPE`        | `user`                                 | `claude mcp` scope: local, user, project.                                                             |
| `KNOSSOS_RELEASE_BASE`     | the GitHub releases of Knossos         | A releases URL, or a directory holding the tarball and `SHA256SUMS`.                                  |
| `KNOSSOS_IMAGE`            | `<repository>:<version>`               | Container mode: the image to install, for example one built here.                                     |
| `KNOSSOS_IMAGE_REPOSITORY` | `ghcr.io/araneadev/knossos`            | Container mode: where release images come from.                                                       |

Piped from `/` or your home directory, with no terminal, the installer allows
no project, because nothing says which one you meant. It tells you, and
`knossos allow-root <path> --execute` adds one later. In container mode it
never allows `/`, your home directory or a directory above it, even from a
terminal or through `KNOSSOS_ALLOW_ROOT`, because container mode never mounts
them; it says so and grants nothing. It does not scan the
project it allows: start Knossos there (`/knossos` in a session, or `knossos`
in the folder) and that scans it.

### What it writes

In native mode, under the install directory (`~/.knossos` unless you chose
another):

- `versions/<version>`, the unpacked release, and `current`, a link to it.
  After an update the previous version stays beside it, for
  [a rollback](../operate/updating.md#roll-back).
- `install.json`, the record of the mode, the version and where the launcher is.
- `plugin/`, the Claude Code plugin, whose hooks call the launcher.
- `roots.json`, the allow-list, and the graph database beside it.

In container mode, the install directory and the data directory are two
directories that never overlap. The install directory is
`$XDG_DATA_HOME/knossos` when `XDG_DATA_HOME` is an absolute path, else
`~/.local/share/knossos`, or the one you name with `--install-dir` (or
`KNOSSOS_INSTALL_DIR`, which only the installer reads). Every container can
only read it, and it holds everything the host trusts:

- `install.json`, which names the image by digest, the data directory and the
  bin directory.
- `container-update`, the update helper, out of the image last switched to.
- `plugin/`, the plugin, written out of the image.
- `roots.json`, the allow-list, which only the launcher changes.
- `settings/`, the enricher list and the pane setting, which only a short run
  of the image started by the launcher changes.
- `containers/`, the launcher's idle markers and graph records, `tmp/`, its
  temporary files, and `update-check.json`.

The data directory holds the graph and nothing else. Every container can write
it, so it must be new, empty, or hold only what Knossos puts there; see
[a data directory that holds other things](#a-data-directory-that-holds-other-things).

The installer refuses an install directory that every container would see
too much through, or that a container could write: `/`, your home directory
or a directory above it, the bin directory or a directory around it, a
directory in or around the data directory, one inside the directory you run
the installer from, one below a directory that holds a Knossos graph database,
one in a graph directory it recorded, one that a running Knossos container
can write, and one that already holds anything Knossos does not keep there.
Each message says what to change.

Running the installer again with the same install directory repairs that
installation in place, and keeps its allow-list, its settings and its graph
records. That is the fix for a deleted launcher, a new `--bin-dir` (the old
launcher is left where it was, and the installer says so), and an install
directory you copied or restored from a backup, which the launcher refuses
until the installer has pinned it again.

In both modes, the launcher is one file, `~/.local/bin/knossos` by default, and
it is what the MCP registration and the plugin's hooks call. If its directory
is not on your `PATH`, the installer prints the line to add. It registers the
server with `claude mcp add` at user scope and installs the plugin with
`claude plugin marketplace add` and `claude plugin install`. Without the
`claude` CLI it prints the command to run later.

The installer changes nothing until the download is checked and the new
release has passed `knossos doctor`, run against a throwaway data directory.
A failed install leaves the previous release working. The checksum comes from
the same origin as the tarball: it guards against a corrupted download and is
not a signature. Releases are not signed yet.

Restart Claude Code afterwards, or reload its MCP servers. Then
[scan your first project](first-scan.md). To change or remove the release
later, see [updating](../operate/updating.md).

## Install from a checkout

This is the path for developing Knossos. Run the installer from the project
you want to scan first:

```sh
cd /absolute/path/to/your-project
/absolute/path/to/knossos/tools/install
```

It does the following, in order, and is safe to re-run:

1. Checks that `php`, `node` and `python3` exist.
2. Installs the PHP and TypeScript worker dependencies if they are missing, and
   builds the Rust worker when `cargo` is on the path.
3. Runs `doctor`. Warnings are printed and do not stop the install.
4. Creates the data directory (`~/.knossos`) and a roots file in it, seeded with
   the directory you ran the installer from. Run it again from another project
   and that project is appended.
5. Scans the directory you ran it from, so the server can answer straight away.
   `KNOSSOS_SEED_SCAN=0` skips this step.
6. Registers the server with Claude Code at user scope, through `claude mcp add`.
   Without the `claude` CLI it prints the JSON to paste into your client.

These environment variables apply to the installer and to the server it registers:

| Variable               | Default                 | Meaning                                   |
| ---------------------- | ----------------------- | ----------------------------------------- |
| `KNOSSOS_DATA_DIR`     | `~/.knossos`            | Holds the database and the roots file.    |
| `KNOSSOS_ROOTS_FILE`   | `<data dir>/roots.json` | The allow-list file itself.               |
| `KNOSSOS_ALLOW_ROOT`   | the current directory   | The project that seeds the roots file.    |
| `KNOSSOS_MCP_NAME`     | `knossos`               | The name of the registered server.        |
| `KNOSSOS_MCP_SCOPE`    | `user`                  | `claude mcp` scope: local, user, project. |
| `KNOSSOS_SEED_SCAN`    | `1`                     | `0` skips the initial scan.               |
| `KNOSSOS_MEMORY_LIMIT` | `1G`                    | The PHP memory limit of the core.         |

`KNOSSOS_MEMORY_LIMIT` is not read by the installer. The CLI, the MCP server and
the HTTP server all read it when they start. The core sets its own memory limit,
so a scan behaves the same whatever your `php.ini` says. Without the variable it
runs at `1G`, or at your `php.ini` value when that is higher or `-1`. A value
you set is used as given, even when it is lower than `1G`. It takes a PHP size
such as `512M` or `2G`, or `-1` for no limit. An invalid value is ignored, and
so is a limit PHP refuses; `knossos doctor` reports either as an error on the
`php.memory_limit` check, which also shows the effective limit and where it came
from: `default`, `KNOSSOS_MEMORY_LIMIT` or `php.ini`.

Restart Claude Code afterwards, or reload its MCP servers. Then
[scan your first project](first-scan.md). To get the session brief and the
architecture pane in Claude Code, also install
[the plugin](../claude-code/plugin.md).

## One installation, every project

### One data directory

`KNOSSOS_DATA_DIR` holds the graph database, and the roots file too unless
`KNOSSOS_ROOTS_FILE` names one elsewhere. Pin it, in the MCP registration and
in any shell you type `knossos` from. `tools/install` defaults it to
`~/.knossos` and writes it into the registration it creates.

Unpinned, it reads `~/.knossos/knossos.sqlite` when that file exists, which is
where `tools/install` puts the graph. Without one it falls back to
`<cwd>/.knossos`, and that fallback is per-caller.
The consequences are quiet:

- Two servers, one registered with the variable and one without, build **two
  graphs of the same project**. Both answer. Neither mentions the other.
- `knossos` typed inside a project addresses `<project>/.knossos`, a graph the
  server never reads, so a scan you just ran can leave the server's copy stale.
- `knossos allow-root` writes the roots file beside whichever database it
  derived, so a grant can land in a file the running server never reads. The
  command says which file it wrote and whether the location was named or
  derived; `server_info` reports the one actually in force.

The fallback exists so that `knossos scan .` works on a fresh checkout with no
configuration. It is a hazard only when a _server_ is also involved, which is
when the variable should be set.

The repository ships no `.mcp.json` for the same reason. A project-scoped
registration inherits no environment, so it would always be the unpinned case.

### Granting projects

The allow-list lives in a **roots file** that the server re-reads on every
request, so granting another project needs no restart and no re-registration:

```json
{ "roots": ["/absolute/path/one", "/absolute/path/two"] }
```

It lives at `KNOSSOS_ROOTS_FILE`, or at `<KNOSSOS_DATA_DIR>/roots.json`. The
installer registers the server with no `--allow-root` argument, so the
registration stays correct as projects are added.

To add a project, start Knossos in it (`/knossos` in a session, or `knossos` in
the folder): that adds the project's toplevel to the file. A session that only
begins adds nothing. In a container, or when you want to write the file
yourself, run `knossos allow-root /absolute/path` to preview the change and add
`--execute` to write it. Editing the file by hand works too.

In container mode the list is `<install dir>/roots.json`, which no container
can write, and `knossos allow-root` and `knossos claim` change it on the host.

`--allow-root=PATH` and `KNOSSOS_ALLOWED_ROOTS` are unioned with the file. Use
them for a root that has to survive without a mutable file, and the file for
everything else.

Two tools help from inside a session:

- **`server_info`** returns the roots in force, where each came from, whether
  each exists, the roots file to extend, and whether the server runs in a
  container. Call it first in an unfamiliar setup, or whenever a path is
  rejected.
- **`diagnose_runtime`** checks runtimes, scanner workers, protocol, database
  and migrations, for when a scan fails for no visible reason. It is slower,
  because it starts each language worker.

A rejected path reports the roots in force and the file to add it to.

## Docker by hand

If you would rather run the image yourself than let the installer do it,
Docker is the reproducible distribution and needs no host PHP or Node. Releases
publish the image as `ghcr.io/araneadev/knossos:<version>`, and from a checkout
you build it:

```sh
docker build --target runtime -t knossos:dev .
docker run --rm knossos:dev doctor --json
```

[Running Knossos in Docker](../operate/container.md) has the MCP configuration,
where to mount each project, compose files, volumes and the HTTP profile. The
installer's own container mode, which does the mounting for you, is on
[container mode](../operate/container-mode.md).

## Native stdio (repository checkout)

`tools/install` runs the registration for you. To do it by hand, register the
server once for your user and let the roots file decide which projects it may
read:

```sh
claude mcp add knossos --scope user \
    -e KNOSSOS_DATA_DIR="$HOME/.knossos" \
    -e KNOSSOS_ROOTS_FILE="$HOME/.knossos/roots.json" \
    -- /absolute/path/to/checkout/tools/mcp-serve
```

`tools/mcp-serve` wraps `bin/knossos serve` and logs its start, signals, stderr
and exit status to `mcp-serve.log` in the data directory. A host that reports
only "server disconnected" leaves you that log to read.

Every path in the registration is absolute. A relative path resolves against
the client's working directory, so the same registration would reach a
different graph from each checkout. Without the data directory, `tools/mcp-serve` falls back to `<checkout>/.knossos` and
builds a second graph beside the installed one. Nothing warns about it: both
servers answer, each from its own database.

### The allow-list is a security boundary

It is the only thing between the server and the rest of the filesystem, so
`serve` needs at least one root. When the flag, the environment variable and the
roots file are all empty, it exits with `KNOSSOS_INVALID_ARGUMENT`. Grant the
narrowest tree that works.

Anything able to write the roots file can widen what Knossos reads, which is why
the grant is a file you inspect and no MCP tool writes it. Only the installers,
`knossos allow-root --execute`, `knossos claim --execute` and
`knossos move-project --execute` write it. `/knossos` runs `claim` when you
start it in a folder, and `claim` never grants `/` or your home folder without
asking. Keep it owned by the user running the server.
If the boundary must be fixed, omit the file and pass `--allow-root` only.

## Codex and other MCP clients

Registering Knossos with Codex, its Codex plugin, and the `mcpServers` entry
for any other stdio client are on [Codex and other MCP clients](../agents/clients.md).

## Installation pitfalls

### Refusals from the installer

Each of these stops the installer before it changes anything. Messages begin
with `error:`.

| Message                                                                                                                                      | Cause and what to do                                                                                                                                                                                            |
| -------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `install what is listed above and run the installer again, or install Docker or Podman ...`                                                  | Native mode lacks something (a runtime, a PHP extension, Git, `tar` or a checksum tool), and no container runtime answers. Install what the list names, or install Docker or Podman and use `--mode=container`. |
| `install what is listed above and run the installer again, or re-run it with --mode=container: docker is available`                          | You passed `--mode=native` on a host that lacks something native mode needs. Install it, or use `--mode=container`.                                                                                             |
| `the data directory <dir> holds <entry>, which is not something Knossos puts in a data directory, ...`                                       | Container mode gives every container the data directory read-write, so it must not hold anything of yours. Move the entry out, or choose an empty directory with `--data-dir` or `KNOSSOS_DATA_DIR`.            |
| `the install directory <dir> is your home directory or contains it, ...`, `... is or contains the bin directory ...`                         | Every container is given the install directory read-only. Choose a directory of its own with `--install-dir`, or leave the default.                                                                             |
| `the install directory <dir> lies in the data directory <dir>, ...`, `... lies in <dir>, which holds a Knossos graph ...`                    | A container can write a data directory, and the install directory holds what the host runs. Choose an install directory outside every data directory.                                                           |
| `the install directory <dir> lies in <mount>, which the running Knossos container <name> can write; ...`                                     | A running container, of any installation, has that directory read-write. Choose an install directory outside it.                                                                                                |
| `the install directory <dir> holds <entry>, which is not something Knossos keeps there, ...`                                                 | The directory is not empty and is not an install directory. Choose an empty or new one with `--install-dir`.                                                                                                    |
| `docker is installed, but its daemon is not running (docker info failed): start it and run the installer again`                              | The binary exists and the daemon does not answer. Start the daemon.                                                                                                                                             |
| `container mode needs Docker or Podman, with its daemon running; neither was found`                                                          | You passed `--mode=container` on a host without either.                                                                                                                                                         |
| `<dir> holds a native install (...); remove those entries or pick another --data-dir, or re-run with --force-clean ...`                      | You are installing container mode over a native install in the same data directory, or the reverse. The message lists the entries. Pick another `--data-dir`, or `--force-clean` removes exactly those entries. |
| `checksum mismatch for <file> (expected ..., got ...); nothing was changed`                                                                  | The download does not match `SHA256SUMS`. Run the installer again. If it repeats, do not install that file.                                                                                                     |
| `knossos doctor failed on the new release; the previous release, if any, is untouched`                                                       | The release does not run on this host. The output above the message says why.                                                                                                                                   |
| `could not pull <image>; nothing was changed`                                                                                                | Container mode could not fetch the image. Check the network and that the version exists.                                                                                                                        |
| `<path> is a directory; move it away and run the installer again`                                                                            | The launcher's path is taken by a directory. Move it, or pass another `--bin-dir`.                                                                                                                              |
| `the directory of the knossos launcher <dir> lies inside the Knossos data directory ...` (container mode)                                    | A container can write the data directory, so the launcher may not live in it. Pass `--bin-dir` outside it.                                                                                                      |
| `<dir> contains the Knossos data directory ...`, `... the Knossos install directory ...`, or `... the directory of the knossos launcher ...` | The project you ran the installer in contains one of them. Run it from another directory, or move `--data-dir`, `--install-dir` or `--bin-dir` out of the project.                                              |
| `--force-clean removes entries only in a data directory named by its physical path, ...` (container mode)                                    | The data directory goes through a symbolic link, which a container could have planted. Check it is the directory you mean and pass its physical path.                                                           |
| `<dir> is the graph directory of the container install in <install dir>, ...` (native mode)                                                  | A native install would run what that installation's containers could plant there. Remove that container install first, or pick another `--data-dir`.                                                            |
| `--install-dir is for container mode; ...`                                                                                                   | A native install keeps its release in the data directory. Leave `--install-dir` out.                                                                                                                            |
| `HOME is not set; pass --data-dir`                                                                                                           | `HOME` is unset. Pass `--data-dir`, `--bin-dir` and, in container mode, `--install-dir`.                                                                                                                        |
| `the data, install and bin directories must not contain a newline or another control character`                                              | Rename the directory.                                                                                                                                                                                           |

`--dry-run` shows the mode, the version, the directories and what would be
granted, and changes nothing.

### Other pitfalls

- **No PHP or Composer on the host, and no container runtime.** The release
  installer needs one of the two, and `tools/mcp-serve` from a checkout needs
  PHP and Composer. Install Docker or Podman and run the installer again, or
  use the image by hand.
- **An unqualified `docker build`.** It builds the last stage of the
  Dockerfile, the CI `quality` stage, not the server. Always pass
  `--target runtime`. Releases publish the image as
  `ghcr.io/araneadev/knossos:<version>`, with version tags only and no
  `latest`; a local build is for a checkout.
- **`-t` on Docker stdio.** Use `-i` and never `-t`: terminal framing corrupts
  the NDJSON stream. A socket permission error from Docker means the user
  starting your client cannot reach Docker; fix that before you start Codex or
  Claude Code.
- **An unpinned data directory.** Keep `KNOSSOS_DATA_DIR` and
  `KNOSSOS_ROOTS_FILE` explicit and absolute; see
  [one data directory](#one-data-directory). The installer pins both in the
  registration it writes.
- **Different paths inside the container.** Mount each allowed project
  read-only at the same absolute path inside the container, and pass that path
  to `--allow-root`. Otherwise the host paths in MCP requests match nothing in
  the container. Container mode does this for you.
- **Git's `dubious ownership`.** The image runs as `www-data`, so a checkout
  owned by another user trips Git's ownership check. Scanning still works, but
  the Git-backed answers degrade. Set `KNOSSOS_GIT_SAFE_DIRECTORY` to the
  mounted project's absolute path, and Knossos passes it to every git call as
  `-c safe.directory=`. The Claude Code plugin's container hooks do this for
  you.

For the refusals container mode itself gives at run time (a project in your
home directory, a data directory inside a project), see
[container mode](../operate/container-mode.md#refusals).

### A data directory that holds other things

In container mode, every container can write the data directory, so a
directory that holds anything of yours is refused, by the installer and by the
launcher when `KNOSSOS_DATA_DIR` names a new one. `~/code`, for example, would
give every scanned project write access to each repository in it, git hooks
included. Accepted is a directory that does not exist yet, an empty one, or
one that holds only what Knossos itself puts in a data directory, each in the
shape Knossos makes it:

- `knossos.sqlite`, with `-wal`, `-shm`, `-journal` and `.migrate.lock` beside
  it, each a plain file
- `scan-failures.jsonl`, with its `.lock` and temporary copies, and
  `mcp-serve.log`: plain files
- `watch/` and `backups/`: directories that hold plain files only
- `.knossos-files.*`, the launcher's scratch directories, which hold plain
  files only

A file, a link, or another directory under one of these names is refused too.

The entries a native install keeps there (`install.json`, `versions/`,
`current`, `plugin/`, `previous-version`, `update-check.json`, the self-update
lock, and the roots list and settings beside the graph: `roots.json`,
`enrichers.json` and `pane-autostart.json`) are refused as well: the host runs
or trusts them. The installer names them and offers `--force-clean`, which
removes exactly them. In container mode the allow-list and the settings live
in the install directory, so after a switch from native mode, add your
projects again with `knossos allow-root`.

Two installations can share a data directory, as two projects share one: each
keeps its own install directory, and the containers of either can change the
graph data of both, but no code runs on the host from the graph. The launcher
also checks a graph directory again before every container start, so what is
not graph data there (another installation's install directory included) is
never given to a container read-write.

## Native runtimes

You need PHP 8.3 or newer with JSON, mbstring, PDO, PDO SQLite and tokenizer, Node 24 or newer,
Python 3.11 or newer, Composer 2 and Git. Each is a floor. `doctor` reports a
version below it and does not cap the ones above. Install the locked
dependencies without running project scripts:

```sh
composer install --no-interaction
composer --working-dir=workers/php install --no-interaction
npm --prefix workers/typescript ci --ignore-scripts
php bin/knossos doctor --json
```

A native MCP registration, without the installer:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "/absolute/knossos/bin/knossos",
            "args": ["serve", "--allow-root=/absolute/project"],
            "env": { "KNOSSOS_DATA_DIR": "/absolute/knossos-data" }
        }
    }
}
```

Cargo 1.82 or newer is optional. With it, `tools/install` builds the Rust worker
and Rust scanning works. Without it, `doctor` reports `worker.rust` as `skipped`
and every other language keeps working.

The Rust worker binary is git-ignored, so `git pull` updates its source but not
the binary, and Rust scans keep running the old code until you rebuild it. Run
`tools/install` again after every pull. `doctor` reports a binary built from
other source than the checkout as a `worker.rust` warning: the binary embeds a
hash of `workers/rust/src`, `Cargo.toml`, `Cargo.lock` and `build.rs`, and
`doctor` compares it with the same hash of the files on disk. The warning
leaves the report healthy. To rebuild by hand, run
`cargo build --release --locked` in `workers/rust` and copy
`target/release/knossos-rust-worker` into `workers/rust/bin/`.

`doctor` verifies the effective runtime, the workers, the protocol, the
database, the migrations and whether the data directory is writable. Its
`installation` line says what is running: `source checkout at <path>`,
`native <version> at <path>`, or
`container <version> from <image>, installed in <install dir>`. CI runs on
Linux. On Windows, use Docker Desktop or WSL2; native Windows is untested.
`change_impact` still returns static impact when a scanned root is not a Git
repository.

## Operating safely

- Scanning never installs dependencies, executes project code or boots a
  framework.
- Preconfigure every allowed root before you hand the server to an agent.
- Prefer read-only source mounts and `--network none`.
- Back up the database when agents annotate components. The graph rebuilds
  from a scan, but annotations live only in that database, and bundles leave
  them out. `knossos maintain-database backup --execute` writes a copy under
  `backups/`; see [maintenance](../operate/maintenance.md).
- Call `scan_project` with `mode: auto`. Force `full` to verify a result, or
  after changing analyzer code outside the packaged release.

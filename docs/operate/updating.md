# Updating Knossos

How do I move an installed Knossos to a newer release, undo that, and what does
the update notice send over the network? This page covers a release installed
with `install.sh`, in native or container mode, and a checkout.

## Update to the latest release

```sh
knossos self-update
```

It looks up the newest release, installs it beside the current one, checks it,
and switches to it:

```text
Updated Knossos from <old> to <new>.
A running Claude Code session keeps the previous release loaded until you restart it.
```

When you are already on the newest release it says
`Knossos <version> is already the latest version.` and changes nothing.

In native mode it downloads the tarball for your platform and checks it against
the release's `SHA256SUMS`. The new release must answer `version` with its own
version before anything points at it. A failure at any step puts the link, `install.json`
and the launcher back as they were.

`--version=V` installs that release instead of the newest, including an older
one. `--json` prints the result as JSON. `--check`, `--rollback` and
`--version` cannot be combined.

### What gets replaced

- **The release**: the code, in `versions/<version>` in native mode, or the
  image in container mode.
- **The launcher**: `~/.local/bin/knossos` is rewritten from the new release,
  and keeps pointing at the same install directory.
- **The plugin**: rewritten in place, in the directory Claude Code already has
  registered, so the registration stays valid. An install made with
  `--no-plugin` has no plugin, and stays that way.

What does not change is the graph. Your scans, notes and annotations stay in
the data directory. A new release applies its own pending migrations to the
database the first time it opens it, and `--rollback` does not undo them: an
older release on a newer database opens it, and `knossos doctor` reports the
difference as a warning on the migrations check.

Restart Claude Code after an update. The MCP server and a running watcher keep
the code they started with, and so does a running session.

### In container mode

The update is carried out by the launcher on the host, never inside a
container, because code in a container cannot replace its own image. It pulls
`ghcr.io/araneadev/knossos:<version>`, checks that the image starts and passes
`doctor`, and records it in `install.json` by digest. Then it stops the running
containers of this installation, so the next call starts one from the new
image, and rewrites the launcher and the plugin out of that image. It needs the
Docker or Podman daemon running. One previous image is kept for a rollback and
the one before it is removed.

Release images carry version tags only, never `latest`: an update pulls the
version it found.

## Check without updating

```sh
knossos self-update --check
```

It looks up the newest release and says whether it is newer, and changes
nothing:

| Exit status | Meaning                                                                                                |
| ----------- | ------------------------------------------------------------------------------------------------------ |
| `0`         | You are on the latest release: `Knossos <version> is the latest version.`                              |
| `10`        | A newer release exists: `Knossos <latest> is available (you have <current>): run knossos self-update.` |
| other       | The lookup failed, and the message says why.                                                           |

With `--json` the answer is an object with `current`, `latest` and `update`.

## Roll back

```sh
knossos self-update --rollback
```

Every update keeps the release it replaced, and `--rollback` switches back to
it: `Rolled back from <version> to <previous>.` It needs no network in native
mode. Without a previous release it says
`There is no previous version to roll back to`. In container mode it also needs
the previous image to still be on the host, and if it is gone it says so and
tells you to install a version with `knossos self-update --version=V`.

A second `--rollback` goes forward again, to the release you rolled back from,
as long as that one is the kept previous release.

## The update notice

At the start of a session the [session brief](../claude-code/session-brief.md)
carries one more line when a newer release is known:

```text
knossos <new> is available (you have <old>): run knossos self-update
```

The brief never waits for the network. It reads a small cached answer,
`update-check.json` in the install directory, which a background call refreshes
after the brief is out. A checkout never shows the line.

### What the daily check sends

The background call is `knossos self-update --check --quiet`, started by the
session hook, detached, bounded in time and silent on every failure. It looks
at most once a day. The attempt is recorded before the request is made, so an
offline host does not try again at every session start.

A look is one GET request to GitHub's releases API,
`https://api.github.com/repos/AraneaDev/knossos/releases/latest`, with two
headers added to what any HTTP client sends:

- `User-Agent: knossos-self-update`
- `Accept: application/vnd.github+json` (in native mode with
  `application/octet-stream` added)

No cookie, no authorization, no referer, and nothing about you, the host or the
project. GitHub sees the request and your IP address, as with any request. The
answer's release tag is the only thing kept.

Turn it off with `KNOSSOS_NO_UPDATE_CHECK=1` in the environment Claude Code
runs the hooks in. Then the hook makes no request and the brief shows no
notice. `knossos self-update` and `knossos self-update --check` typed by you
still work.

`KNOSSOS_RELEASE_BASE` points the lookup and the downloads at another origin: a
releases URL, or a directory that holds the tarballs and `SHA256SUMS`.

## What the checksums guarantee

Every release carries a `SHA256SUMS` file, and the installer and `self-update`
check the tarball against it. That guards against a corrupted or truncated
download. It comes from the same origin as the tarball, so it does not protect
against a compromised origin. Releases are not signed yet.

## Update a checkout

A checkout is updated with git, and `knossos self-update` refuses with
`This is a source checkout: update it with git pull.`

```sh
git pull
tools/install
```

Run `tools/install` again after every pull: it installs new worker
dependencies and rebuilds the Rust worker, whose binary is git-ignored. Then
rewrite the plugin with the data directory you installed it with, as
[the plugin guide](../claude-code/plugin.md#update) describes.

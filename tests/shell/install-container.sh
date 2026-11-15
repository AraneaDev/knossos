#!/bin/sh
# install.sh in container mode, and self-update of a container install, on a
# PATH that holds no php, node or python3 and a fake docker
# (tests/shell/fixtures/fake-docker) that records its argument lists. No
# container runtime, no registry and no network is needed; a stub claude
# records the plugin and MCP registration. Every run uses temp install and bin
# directories with a space in their names, and names its directories
# explicitly, so whatever KNOSSOS_DATA_DIR and KNOSSOS_ROOTS_FILE the caller
# exported change nothing.
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT="$HERE/../.."
INSTALL_SH="${KNOSSOS_TEST_INSTALL:-$ROOT/install.sh}"
failures=0
WORK=$(mktemp -d)
WORK=$(CDPATH='' cd -P -- "$WORK" && pwd -P)
trap 'rm -rf "$WORK"' EXIT

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; failures=$((failures + 1)); }

# A PATH with the system tools the installer, the launcher and the helper use,
# a fake docker and a stub claude, and nothing else: no php, node or python3.
BARE="$WORK/bare path"
mkdir -p "$BARE"
for tool in sh env uname mktemp date cut sed cat rm mkdir mv ln cp dirname sort tail head wc chmod ls find grep tr awk id readlink sha256sum shasum cksum touch sleep kill; do
    found=$(command -v "$tool" 2>/dev/null || true)
    case $found in /*) ln -s "$found" "$BARE/$tool" ;; esac
done
cp "$HERE/fixtures/fake-docker" "$BARE/docker"
chmod +x "$BARE/docker"
cat > "$BARE/claude" <<'CLAUDE'
#!/bin/sh
line='call'
for a in "$@"; do line="$line [$a]"; done
printf '%s\n' "$line" >> "$CLAUDE_LOG"
CLAUDE
chmod +x "$BARE/claude"
CLAUDE_LOG="$WORK/claude.log"
FAKE_DOCKER_STATE="$WORK/docker state"
export CLAUDE_LOG FAKE_DOCKER_STATE

# The files the image carries that the installer and the launcher read out of it.
reset_docker() {
    rm -rf "$FAKE_DOCKER_STATE"
    mkdir -p "$FAKE_DOCKER_STATE/copy/opt/knossos/launcher" "$FAKE_DOCKER_STATE/copy/opt/knossos/tools/launcher" "$FAKE_DOCKER_STATE/images"
    cp "$ROOT/tools/launcher/knossos" "$FAKE_DOCKER_STATE/copy/opt/knossos/launcher/knossos"
    cp "$ROOT/tools/launcher/container-update" "$FAKE_DOCKER_STATE/copy/opt/knossos/tools/launcher/container-update"
}
calls() { cat "$FAKE_DOCKER_STATE/calls" 2>/dev/null; }
has_call() { grep -qxF "$1" "$FAKE_DOCKER_STATE/calls" 2>/dev/null; }
count_calls() { grep -c "^call \\[$1\\]" "$FAKE_DOCKER_STATE/calls" 2>/dev/null || true; }
field() { sed -n "s/^ *\"$2\": \"\\{0,1\\}\\([^\"]*\\)\"\\{0,1\\},\\{0,1\\}\$/\\1/p" "$1" | head -n 1; }
running() { find "$FAKE_DOCKER_STATE/containers" -type f ! -name '*.*' -exec grep -l '^running$' {} + 2>/dev/null | wc -l | tr -d ' '; }

REPO=example.org/knossos
UID_GID="$(id -u):$(id -g)"

# ci DIR ARGS: install.sh in container mode into DIR/data dir and DIR/bin dir.
ci() {
    dir=$1
    shift
    PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=${FIV:-1.0.0} \
        sh "$INSTALL_SH" --mode=container --data-dir="$dir/data dir" --bin-dir="$dir/bin dir" "$@" 2>&1 </dev/null
}

# ---- a fresh install ---------------------------------------------------------------

reset_docker
T1="$WORK/fresh"
out=$(ci "$T1" --version=1.0.0 --no-plugin --no-roots); status=$?
DATA="$T1/data dir"
LAUNCHER="$T1/bin dir/knossos"
if [ "$status" -eq 0 ]; then pass 'a container install without php, node or python3 exits 0'; else fail "fresh install exited $status: $out"; fi
if has_call "call [pull] [$REPO:1.0.0]"; then pass 'it pulls <repository>:<version>'; else fail "no pull: $(calls)"; fi
if has_call "call [run] [--rm] [--pull=never] [--network] [none] [--user] [$UID_GID] [-e] [HOME=/tmp] [-e] [KNOSSOS_DATA_DIR=/tmp/knossos-check] [-e] [KNOSSOS_ROOTS_FILE=/tmp/knossos-check/roots.json] [--entrypoint] [/opt/knossos/bin/knossos] [$REPO:1.0.0] [version]" \
    && has_call "call [run] [--rm] [--pull=never] [--network] [none] [--user] [$UID_GID] [-e] [HOME=/tmp] [-e] [KNOSSOS_DATA_DIR=/tmp/knossos-check] [-e] [KNOSSOS_ROOTS_FILE=/tmp/knossos-check/roots.json] [--entrypoint] [/opt/knossos/bin/knossos] [$REPO:1.0.0] [doctor]"; then
    pass 'the image is asked its version and runs doctor, as this user, without network or mounts'
else
    fail "image checks: $(calls)"
fi
if has_call "call [run] [--rm] [--pull=never] [--network] [none] [--entrypoint] [cat] [$REPO:1.0.0] [/opt/knossos/tools/launcher/container-update]"; then
    pass 'the install helper is read out of the image without network and without a pull'
else
    fail "helper read: $(calls)"
fi
DIGEST=$(field "$DATA/install.json" image)
case $DIGEST in "$REPO@sha256:"*) pass 'install.json names the image by digest' ;; *) fail "image in install.json: $(cat "$DATA/install.json" 2>&1)" ;; esac
if [ "$(field "$DATA/install.json" mode)" = container ] && [ "$(field "$DATA/install.json" version)" = 1.0.0 ] \
    && [ "$(field "$DATA/install.json" runtime)" = docker ] && [ "$(field "$DATA/install.json" bin_dir)" = "$T1/bin dir" ] \
    && grep -q '"previous_image": null' "$DATA/install.json"; then
    pass 'install.json records mode, version, runtime and bin_dir, and no previous image'
else
    fail "install.json: $(cat "$DATA/install.json" 2>&1)"
fi
if grep -qxF "INSTALL_DIR='$DATA'" "$LAUNCHER" && [ -x "$LAUNCHER" ]; then pass 'the launcher comes from the image with the install directory baked in'; else fail "launcher: $(grep -n '^INSTALL_DIR=' "$LAUNCHER" 2>&1)"; fi
if [ ! -e "$DATA/versions" ] && [ ! -e "$DATA/current" ]; then pass 'container mode has no versions/ and no current'; else fail 'versions/ or current exists'; fi
if [ "$(running)" -eq 0 ] && [ "$(count_calls stop)" -ge 1 ]; then
    pass "the installer's own check through the launcher leaves no container running"
else
    fail "containers left: $(running), $(calls)"
fi
case $out in *'Knossos 1.0.0 is installed.'*) pass 'it says what it installed' ;; *) fail "output: $out" ;; esac
got=$(cd "$WORK" && PATH="$BARE" FAKE_IMAGE_VERSION=1.0.0 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" version 2>&1 </dev/null)
if [ "$got" = 'Knossos 1.0.0' ]; then pass 'the installed launcher answers through a container'; else fail "launcher version said: $got"; fi
leftovers=$(find "$T1" -name '*.tmp.*' | head -n 3)
if [ -z "$leftovers" ] && [ ! -e "$DATA/self-update.lock.d" ]; then pass 'no temporary file or lock is left'; else fail "left behind: $leftovers"; fi

# ---- a second run --------------------------------------------------------------------

: > "$FAKE_DOCKER_STATE/calls"
before=$(cat "$LAUNCHER")
out=$(ci "$T1" --version=1.0.0 --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ] && [ "$(count_calls pull)" -eq 0 ] && [ "$(field "$DATA/install.json" image)" = "$DIGEST" ] \
    && grep -q '"previous_image": null' "$DATA/install.json" && [ "$(cat "$LAUNCHER")" = "$before" ]; then
    pass 'a second run reuses the image on the host and changes nothing'
else
    fail "second run: status $status, $out, $(calls)"
fi

# ---- the plugin, the MCP registration, a root --------------------------------------------

reset_docker
T2="$WORK/with plugin"
PROJECT="$WORK/a project"
mkdir -p "$PROJECT"
rm -f "$CLAUDE_LOG"
out=$(KNOSSOS_ALLOW_ROOT=$PROJECT ci "$T2" --version=1.0.0); status=$?
DATA2="$T2/data dir"
LAUNCHER2="$T2/bin dir/knossos"
DIGEST2=$(field "$DATA2/install.json" image)
if [ "$status" -eq 0 ]; then pass 'a full install with the plugin step exits 0'; else fail "plugin install: $status, $out"; fi
if has_call "call [run] [--rm] [--pull=never] [--network] [none] [--user] [$UID_GID] [-e] [HOME=/tmp] [-e] [KNOSSOS_DATA_DIR=/tmp/knossos-check] [-e] [KNOSSOS_ROOTS_FILE=/tmp/knossos-check/roots.json] [--mount] [type=bind,source=$DATA2,target=$DATA2] [--entrypoint] [/opt/knossos/bin/knossos] [$DIGEST2] [install-agent-plugin] [--execute] [--no-register] [--plugin-dir=$DATA2/plugin] [--bin=$LAUNCHER2] [--data-dir=$DATA2]"; then
    pass "the plugin is written by the image's install-agent-plugin, as this user, with the install directory mounted at its own path"
else
    fail "plugin run: $(grep install-agent-plugin "$FAKE_DOCKER_STATE/calls")"
fi
LIB="$DATA2/plugin/hooks/scripts/lib.sh"
if grep -qxF "KNOSSOS_INSTALLED_BIN='$LAUNCHER2'" "$LIB" 2>/dev/null && grep -qxF "KNOSSOS_INSTALLED_DATA_DIR='$DATA2'" "$LIB"; then
    pass "the plugin's lib.sh names the launcher and the install directory"
else
    fail "lib.sh: $(cat "$LIB" 2>&1)"
fi
expect_claude() { if grep -qxF "$1" "$CLAUDE_LOG" 2>/dev/null; then pass "$2"; else fail "$2: no call $1 in $(cat "$CLAUDE_LOG" 2>&1)"; fi; }
expect_claude "call [plugin] [marketplace] [add] [$DATA2/plugin] [--scope] [user]" 'the marketplace is registered on the host at <install dir>/plugin'
expect_claude 'call [plugin] [install] [knossos@knossos] [--scope] [user] [--yes]' 'the plugin is installed from it'
expect_claude "call [mcp] [add] [knossos] [-s] [user] [-e] [KNOSSOS_DATA_DIR=$DATA2] [-e] [KNOSSOS_ROOTS_FILE=$DATA2/roots.json] [--] [$LAUNCHER2] [serve]" 'the MCP server is registered with the launcher'
if grep -q "^call \[exec\] .*\[/opt/knossos/bin/knossos\] \[allow-root\] \[$PROJECT\] \[--execute\]\$" "$FAKE_DOCKER_STATE/calls" \
    && grep -qF "[--mount] [type=bind,source=$PROJECT,target=$PROJECT]" "$FAKE_DOCKER_STATE/calls"; then
    pass "the project root is granted by the image's allow-root, through the launcher"
else
    fail "allow-root: $(calls)"
fi

# ---- refusals that change nothing -------------------------------------------------------------

nothing_at() { [ ! -e "$1/data dir/install.json" ] && [ ! -e "$1/bin dir/knossos" ]; }

reset_docker
: > "$FAKE_DOCKER_STATE/daemon-down"
out=$(ci "$WORK/down" --version=1.0.0 --no-plugin); status=$?
case $out in *'docker is installed, but its daemon is not running'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/down" ]; then pass 'a stopped daemon is named as such, and nothing is created'; else fail "daemon down: $status, $out"; fi

NODOCKER="$WORK/no docker"
mkdir -p "$NODOCKER"
cp -P "$BARE"/* "$NODOCKER"/
rm -f "$NODOCKER/docker"
out=$(PATH=$NODOCKER sh "$INSTALL_SH" --mode=container --data-dir="$WORK/nd/data" --bin-dir="$WORK/nd/bin" --version=1.0.0 2>&1 </dev/null); status=$?
case $out in *'container mode needs Docker or Podman'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/nd" ]; then pass '--mode=container without docker or podman says so'; else fail "no docker: $status, $out"; fi

reset_docker
out=$(PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 sh "$INSTALL_SH" --no-plugin --no-roots \
    --data-dir="$WORK/auto/data dir" --bin-dir="$WORK/auto/bin dir" --version=1.0.0 2>&1 </dev/null); status=$?
case $out in *'php not found'*'Using container mode: docker is available.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ "$(field "$WORK/auto/data dir/install.json" mode)" = container ]; then
    pass 'without --mode, a host without php but with a running docker gets container mode'
else
    fail "auto mode: $status, $out"
fi

reset_docker
out=$(FIV=2.0.0 ci "$WORK/mismatch" --version=1.0.0 --no-plugin); status=$?
case $out in *'holds Knossos 2.0.0, not 1.0.0'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && nothing_at "$WORK/mismatch"; then pass 'an image of another version is refused and nothing is written'; else fail "mismatch: $status, $out"; fi

reset_docker
out=$(FAKE_DOCTOR_FAILS=1 ci "$WORK/doctor" --version=1.0.0 --no-plugin); status=$?
case $out in *'doctor failed'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && nothing_at "$WORK/doctor"; then pass 'an image whose doctor fails is refused and nothing is written'; else fail "doctor: $status, $out"; fi

reset_docker
: > "$FAKE_DOCKER_STATE/pull-fails"
out=$(ci "$WORK/pull" --version=1.0.0 --no-plugin); status=$?
case $out in *"could not pull $REPO:1.0.0; nothing was changed"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && nothing_at "$WORK/pull"; then pass 'a failed pull is refused and nothing is written'; else fail "pull: $status, $out"; fi

reset_docker
out=$(ci "$WORK/dry" --version=1.0.0 --dry-run); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$WORK/dry" ] && [ "$(count_calls pull)" -eq 0 ] && [ "$(count_calls run)" -eq 0 ]; then
    pass '--dry-run creates nothing and pulls nothing'
else
    fail "dry run: $status, $out, $(calls)"
fi
case $out in *"image:      $REPO:1.0.0"*) pass '--dry-run names the image' ;; *) fail "dry run output: $out" ;; esac

# KNOSSOS_IMAGE installs an image already on the host, for example one built
# here, and the version comes from the image when none is given.
reset_docker
printf 'knossos:local@sha256:9999\n' > "$FAKE_DOCKER_STATE/images/knossos_local"
out=$(PATH="$BARE" KNOSSOS_IMAGE=knossos:local FAKE_ANY_IMAGE=1 FAKE_IMAGE_VERSION=3.1.0 sh "$INSTALL_SH" --mode=container --no-plugin --no-roots \
    --data-dir="$WORK/local/data dir" --bin-dir="$WORK/local/bin dir" 2>&1 </dev/null); status=$?
if [ "$status" -eq 0 ] && [ "$(count_calls pull)" -eq 0 ] && [ "$(field "$WORK/local/data dir/install.json" version)" = 3.1.0 ]; then
    pass 'KNOSSOS_IMAGE installs a local image without pulling, at the version it reports'
else
    fail "KNOSSOS_IMAGE: $status, $out"
fi

# ---- what an existing knossos in the bin directory was -------------------------------------

reset_docker
mkdir -p "$WORK/replace/bin dir"
printf '#!/bin/sh\necho mine\n' > "$WORK/replace/bin dir/knossos"
out=$(ci "$WORK/replace" --version=1.0.0 --no-plugin --no-roots)
case $out in *"replacing $WORK/replace/bin dir/knossos, a file that was not a Knossos launcher"*) pass 'replacing a foreign file says so' ;; *) fail "replace file: $out" ;; esac
mkdir -p "$WORK/link/bin dir"
ln -s /usr/local/bin/something "$WORK/link/bin dir/knossos"
out=$(ci "$WORK/link" --version=1.0.0 --no-plugin --no-roots)
case $out in *"replacing $WORK/link/bin dir/knossos, which was a link to /usr/local/bin/something"*) pass 'replacing a link names its target' ;; *) fail "replace link: $out" ;; esac
mkdir -p "$WORK/other/bin dir"
cp "$LAUNCHER" "$WORK/other/bin dir/knossos"
out=$(ci "$WORK/other" --version=1.0.0 --no-plugin --no-roots)
case $out in *"the launcher of the Knossos installed in $DATA"*) pass 'replacing the launcher of another installation names it' ;; *) fail "replace launcher: $out" ;; esac
out=$(ci "$WORK/other" --version=1.0.0 --no-plugin --no-roots)
case $out in *replacing*) fail "reinstalling over its own launcher still says replacing: $out" ;; *) pass 'its own launcher is replaced without a note' ;; esac

# ---- self-update ----------------------------------------------------------------------------

REL="$WORK/releases"
mkdir -p "$REL"
: > "$REL/knossos-1.0.0-linux-x86_64.tar.gz"
GRAPH="$WORK/graph dir"
# kn ARGS: the launcher of the plugin install, on the bare PATH, with the graph elsewhere.
kn() {
    (cd "$PROJECT" && PATH="$BARE" KNOSSOS_RELEASE_BASE="$REL" KNOSSOS_IMAGE_REPOSITORY=$REPO KNOSSOS_DATA_DIR="$GRAPH" \
        KNOSSOS_ROOTS_FILE="$GRAPH/roots.json" "$LAUNCHER2" "$@" 2>&1 </dev/null)
}
# The plugin was pointed at a graph directory by hand; updates keep it.
sed "s|^KNOSSOS_INSTALLED_DATA_DIR=.*|KNOSSOS_INSTALLED_DATA_DIR='$GRAPH'|" "$LIB" > "$WORK/lib.sh" && cp "$WORK/lib.sh" "$LIB"

out=$(FAKE_IMAGE_VERSION=1.0.0 kn self-update --check); status=$?
if [ "$status" -eq 0 ] && [ "$out" = 'Knossos 1.0.0 is the latest version.' ]; then pass '--check with nothing newer exits 0'; else fail "--check, nothing newer: $status, $out"; fi
if grep -q '"latest": "1.0.0"' "$DATA2/update-check.json" 2>/dev/null && [ ! -e "$GRAPH/update-check.json" ]; then
    pass '--check writes update-check.json to the install directory, not the graph directory'
else
    fail "update-check.json: $(cat "$DATA2/update-check.json" 2>&1)"
fi
: > "$REL/knossos-1.1.0-linux-x86_64.tar.gz"
: > "$REL/knossos-1.1.0-rc.1-linux-x86_64.tar.gz"
: > "$FAKE_DOCKER_STATE/calls"
out=$(kn self-update --check --quiet); status=$?
if [ "$status" -eq 0 ] && [ -z "$out" ] && [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then
    pass 'the background check with a fresh answer prints nothing and starts no container'
else
    fail "quiet fresh check: $status, $out, $(calls)"
fi
touch -t 202001010000 "$DATA2/update-check.json"
out=$(kn self-update --check --quiet); status=$?
if [ "$status" -eq 0 ] && [ -z "$out" ] && grep -q '"latest": "1.1.0"' "$DATA2/update-check.json"; then
    pass 'the background check looks again once the answer is a day old, silently'
else
    fail "quiet stale check: $status, $out, $(cat "$DATA2/update-check.json")"
fi
touch -t 202001010000 "$DATA2/update-check.json"
out=$(KNOSSOS_NO_UPDATE_CHECK=1 kn self-update --check --quiet); status=$?
if [ "$status" -eq 0 ] && [ -z "$out" ] && [ -n "$(find "$DATA2/update-check.json" -mmin +1439)" ]; then pass 'KNOSSOS_NO_UPDATE_CHECK=1 turns the background check off'; else fail "opt-out: $status, $out"; fi
out=$(kn self-update --check); status=$?
case $out in *'Knossos 1.1.0 is available (you have 1.0.0): run knossos self-update.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 10 ] && [ "$said" = yes ]; then pass '--check with a newer release exits 10 and names it'; else fail "--check, newer: $status, $out"; fi
out=$(kn self-update --check --json)
if [ "$out" = '{"current":"1.0.0","latest":"1.1.0","update":true}' ]; then pass '--check --json'; else fail "--check --json: $out"; fi

# A container of this installation runs; the update must stop it.
FAKE_IMAGE_VERSION=1.0.0 kn version >/dev/null
before_running=$(running)
: > "$FAKE_DOCKER_STATE/calls"
launcher_before=$(cat "$LAUNCHER2")
out=$(FAKE_IMAGE_VERSION=1.1.0 kn self-update); status=$?
NEW_DIGEST=$(field "$DATA2/install.json" image)
case $out in *'Updated Knossos from 1.0.0 to 1.1.0.'*'restart it.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && has_call "call [pull] [$REPO:1.1.0]"; then pass 'self-update pulls the newer image and says to restart Claude Code'; else fail "update: $status, $out, $(calls)"; fi
if [ "$(field "$DATA2/install.json" version)" = 1.1.0 ] && [ "$NEW_DIGEST" != "$DIGEST2" ] && case $NEW_DIGEST in "$REPO@sha256:"*) true ;; *) false ;; esac \
    && [ "$(field "$DATA2/install.json" previous_image)" = "$DIGEST2" ] && [ "$(field "$DATA2/install.json" previous_version)" = 1.0.0 ]; then
    pass 'install.json names the new image by digest and keeps the previous one'
else
    fail "install.json after update: $(cat "$DATA2/install.json")"
fi
if [ "$before_running" -ge 1 ] && [ "$(running)" -eq 0 ]; then pass "the installation's running containers are stopped"; else fail "containers: $before_running before, $(running) after"; fi
if grep -qxF "FROM_IMAGE='$NEW_DIGEST'" "$LIB" && grep -qxF "KNOSSOS_INSTALLED_DATA_DIR='$GRAPH'" "$LIB" && grep -qxF "KNOSSOS_INSTALLED_BIN='$LAUNCHER2'" "$LIB"; then
    pass 'the plugin is rewritten by the new image and keeps its hand-set graph directory'
else
    fail "plugin after update: $(cat "$LIB")"
fi
if [ "$(cat "$LAUNCHER2")" = "$launcher_before" ] && has_call "call [run] [--rm] [--pull=never] [--network] [none] [--entrypoint] [cat] [$NEW_DIGEST] [/opt/knossos/launcher/knossos]"; then
    pass 'the launcher is rewritten from the new image'
else
    fail "launcher after update: $(calls)"
fi
if [ -z "$(find "$T2" -name '*.tmp.*')" ] && [ ! -e "$DATA2/self-update.lock.d" ]; then pass 'no temporary file or lock is left'; else fail 'leftovers after the update'; fi
if ! grep '^call \[run\]' "$FAKE_DOCKER_STATE/calls" | grep -qv -e '\[--network\] \[none\]'; then pass 'no container the update runs has a network'; else fail "networked run: $(calls)"; fi

out=$(FAKE_IMAGE_VERSION=1.1.0 kn self-update); status=$?
if [ "$status" -eq 0 ] && [ "$out" = 'Knossos 1.1.0 is already the latest version.' ]; then pass 'a second self-update says it is already the latest'; else fail "second update: $status, $out"; fi

out=$(kn self-update --rollback); status=$?
case $out in *'Rolled back from 1.1.0 to 1.0.0.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ "$(field "$DATA2/install.json" image)" = "$DIGEST2" ] && [ "$(field "$DATA2/install.json" previous_image)" = "$NEW_DIGEST" ] \
    && grep -qxF "FROM_IMAGE='$DIGEST2'" "$LIB"; then
    pass '--rollback returns to the previous digest, plugin included'
else
    fail "rollback: $status, $out, $(cat "$DATA2/install.json")"
fi

# A plugin step that fails puts install.json and the launcher back.
cp "$DATA2/install.json" "$WORK/record.before"
cp "$LAUNCHER2" "$WORK/launcher.before"
out=$(FAKE_IMAGE_VERSION=1.1.0 FAKE_PLUGIN_FAILS=1 kn self-update --version=1.1.0); status=$?
case $out in *'could not write its plugin'*'previous state was restored'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && cmp -s "$WORK/record.before" "$DATA2/install.json" && cmp -s "$WORK/launcher.before" "$LAUNCHER2"; then
    pass 'a failure after the switch puts install.json and the launcher back'
else
    fail "failed update: $status, $out, $(cat "$DATA2/install.json")"
fi

# An image that is not the version asked for changes nothing.
out=$(FAKE_IMAGE_VERSION=9.9.9 kn self-update --version=1.1.0); status=$?
if [ "$status" -ne 0 ] && cmp -s "$WORK/record.before" "$DATA2/install.json"; then pass 'an image of the wrong version is refused before anything changes'; else fail "wrong version: $status, $out"; fi

# Exactly one previous image is kept.
: > "$REL/knossos-1.2.0-linux-x86_64.tar.gz"
FAKE_IMAGE_VERSION=1.1.0 kn self-update --version=1.1.0 >/dev/null
: > "$FAKE_DOCKER_STATE/calls"
out=$(FAKE_IMAGE_VERSION=1.2.0 kn self-update); status=$?
if [ "$status" -eq 0 ] && has_call "call [image] [rm] [$DIGEST2]" && [ "$(field "$DATA2/install.json" previous_image)" = "$NEW_DIGEST" ]; then
    pass 'the image before the previous one is removed, the previous one kept'
else
    fail "prune: $status, $out, $(calls)"
fi

# One update at a time.
mkdir "$DATA2/self-update.lock.d"
printf '%s\n' "$$" > "$DATA2/self-update.lock.d/pid"
out=$(kn self-update --rollback); status=$?
case $out in *'another knossos self-update is running'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'a second self-update while one runs is refused'; else fail "lock: $status, $out"; fi
printf '999999999\n' > "$DATA2/self-update.lock.d/pid"
out=$(kn self-update --rollback); status=$?
if [ "$status" -eq 0 ]; then pass 'a lock left by a run that is gone is taken over'; else fail "stale lock: $status, $out"; fi

out=$(kn self-update --quiet); status=$?
case $out in *'--quiet goes with --check.'*) pass '--quiet alone is refused' ;; *) fail "--quiet alone: $status, $out" ;; esac
out=$(kn self-update --check --rollback); status=$?
case $out in *'cannot be combined'*) pass '--check and --rollback together are refused' ;; *) fail "combined: $status, $out" ;; esac

# The daemon down: the update says so.
: > "$FAKE_DOCKER_STATE/daemon-down"
out=$(kn self-update); status=$?
case $out in *'docker daemon is not running'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'self-update with the daemon down says so'; else fail "daemon down update: $status, $out"; fi
rm -f "$FAKE_DOCKER_STATE/daemon-down"

# A launcher that does not answer after the switch puts install.json, the
# launcher and the plugin back as they were.
printf '# mine\n' >> "$LIB"
cp "$LIB" "$WORK/lib.before"
cp "$DATA2/install.json" "$WORK/record.before"
cp "$LAUNCHER2" "$WORK/launcher.before"
out=$(FAKE_EXEC_EXIT=3 FIV=1.2.0 ci "$T2" --version=1.2.0 --no-roots); status=$?
case $out in *'the previous state was restored'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && cmp -s "$WORK/lib.before" "$LIB" && cmp -s "$WORK/record.before" "$DATA2/install.json" \
    && cmp -s "$WORK/launcher.before" "$LAUNCHER2"; then
    pass 'a failed check after the switch restores install.json, the launcher and the plugin'
else
    fail "probe failure: $status, $out, lib.sh $(cat "$LIB")"
fi

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi

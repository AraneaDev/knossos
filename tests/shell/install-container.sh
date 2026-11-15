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

# A home directory of the test's own, for the launcher's mount rule.
HOME="$WORK/home"
mkdir -p "$HOME"
export HOME

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; failures=$((failures + 1)); }

# A PATH with the system tools the installer, the launcher and the helper use,
# a fake docker and a stub claude, and nothing else: no php, node or python3.
BARE="$WORK/bare path"
mkdir -p "$BARE"
for tool in sh env uname mktemp date cut sed cat rm mkdir mv ln cp dirname sort tail head wc chmod ls find grep tr awk id readlink sha256sum shasum cksum touch sleep kill stat dd; do
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

# ci DIR ARGS: install.sh in container mode into DIR/data dir, DIR/install dir and DIR/bin dir.
ci() {
    dir=$1
    shift
    PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=${FIV:-1.0.0} \
        sh "$INSTALL_SH" --mode=container --data-dir="$dir/data dir" --install-dir="$dir/install dir" --bin-dir="$dir/bin dir" "$@" 2>&1 </dev/null
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
if has_call "call [run] [--rm] [--pull=never] [--network] [none] [--user] [$UID_GID] [--entrypoint] [cat] [$REPO:1.0.0] [/opt/knossos/tools/launcher/container-update]"; then
    pass 'the install helper is read out of the image without network and without a pull'
else
    fail "helper read: $(calls)"
fi
INST="$T1/install dir"
DIGEST=$(field "$INST/install.json" image)
case $DIGEST in "$REPO@sha256:"*) pass 'install.json names the image by digest' ;; *) fail "image in install.json: $(cat "$INST/install.json" 2>&1)" ;; esac
if [ "$(field "$INST/install.json" mode)" = container ] && [ "$(field "$INST/install.json" version)" = 1.0.0 ] \
    && [ "$(field "$INST/install.json" runtime)" = docker ] && [ "$(field "$INST/install.json" bin_dir)" = "$T1/bin dir" ] \
    && [ "$(field "$INST/install.json" data_dir)" = "$DATA" ] && grep -q '"previous_image": null' "$INST/install.json"; then
    pass 'install.json records mode, version, runtime, bin_dir and data_dir, and no previous image'
else
    fail "install.json: $(cat "$INST/install.json" 2>&1)"
fi
if grep -qxF "INSTALL_DIR='$INST'" "$LAUNCHER" && [ -x "$LAUNCHER" ]; then pass 'the launcher comes from the image with the install directory baked in'; else fail "launcher: $(grep -n '^INSTALL_DIR=' "$LAUNCHER" 2>&1)"; fi
if [ ! -e "$DATA/versions" ] && [ ! -e "$DATA/current" ]; then pass 'container mode has no versions/ and no current'; else fail 'versions/ or current exists'; fi
if [ "$(tr -d ' \n' < "$INST/roots.json" 2>/dev/null)" = '{"roots":[]}' ] && [ ! -e "$DATA/roots.json" ]; then
    pass 'the installer writes the first, empty allow-list in the install directory'
else
    fail "first roots file: $(cat "$INST/roots.json" 2>&1)"
fi
# pin_of DIR: the device and inode numbers of DIR; baked_pin LAUNCHER: the pin a launcher carries.
pin_of() { stat -c '%d:%i' -- "$1" 2>/dev/null || stat -f '%d:%i' -- "$1"; }
baked_pin() { sed -n "s/^INSTALL_PIN='\(.*\)'\$/\1/p" "$1"; }
PIN1=$(pin_of "$INST")
if [ -n "$PIN1" ] && [ "$(baked_pin "$LAUNCHER")" = "$PIN1" ]; then pass 'the launcher carries the device and inode numbers of the install directory'; else fail "pin: $(grep -n '^INSTALL_PIN=' "$LAUNCHER")"; fi
# The layout: the graph alone in the data dir, the host state in the install
# directory outside it, with plugin/, containers/, settings/ and tmp/ as real
# directories made before any container.
if [ -d "$INST" ] && [ ! -L "$INST" ] && [ -d "$INST/plugin" ] && [ ! -L "$INST/plugin" ] \
    && [ -d "$INST/containers" ] && [ ! -L "$INST/containers" ] && [ -d "$INST/settings" ] && [ -d "$INST/tmp" ] && [ -f "$INST/container-update" ] \
    && grep -q '"layout": "install-dir"' "$INST/install.json" && [ -z "$(ls -A "$DATA")" ]; then
    pass 'the install directory lies outside the data directory, which holds nothing of the host, and install.json records the layout'
else
    fail "layout: $(find "$T1" -maxdepth 2 | sort | tr '\n' ' ')"
fi
if [ -z "$(find "$INST" -maxdepth 1 -name '.work.*' -o -maxdepth 1 -name '.install.*')" ] && [ -z "$(ls -A "$INST/tmp")" ]; then pass 'no work directory is left in the install directory'; else fail 'a work directory was left behind'; fi
if [ "$(running)" -eq 0 ] && [ "$(count_calls stop)" -ge 1 ]; then
    pass "the installer's own check through the launcher leaves no container running"
else
    fail "containers left: $(running), $(calls)"
fi
case $out in *'Knossos 1.0.0 is installed.'*) pass 'it says what it installed' ;; *) fail "output: $out" ;; esac
mkdir -p "$WORK/elsewhere"
got=$(cd "$WORK/elsewhere" && PATH="$BARE" FAKE_IMAGE_VERSION=1.0.0 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" version 2>&1 </dev/null)
if [ "$got" = 'Knossos 1.0.0' ]; then pass 'the installed launcher answers through a container'; else fail "launcher version said: $got"; fi
leftovers=$(find "$T1" -name '*.tmp.*' | head -n 3)
if [ -z "$leftovers" ] && [ ! -e "$INST/self-update.lock.d" ]; then pass 'no temporary file or lock is left'; else fail "left behind: $leftovers"; fi

# ---- a second run --------------------------------------------------------------------

: > "$FAKE_DOCKER_STATE/calls"
before=$(cat "$LAUNCHER")
out=$(ci "$T1" --version=1.0.0 --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ] && [ "$(count_calls pull)" -eq 0 ] && [ "$(field "$INST/install.json" image)" = "$DIGEST" ] \
    && grep -q '"previous_image": null' "$INST/install.json" && [ "$(cat "$LAUNCHER")" = "$before" ]; then
    pass 'a second run reuses the image on the host and changes nothing'
else
    fail "second run: status $status, $out, $(calls)"
fi
if [ "$(pin_of "$INST")" = "$PIN1" ] && [ "$(baked_pin "$LAUNCHER")" = "$PIN1" ]; then pass 'a second run keeps the install directory and its pin'; else fail 'a second run changed the install directory or its pin'; fi

# ---- symlinks planted where the installer will write ------------------------------------

SENTINEL="$HOME/.secret"
printf 'do not touch\n' > "$SENTINEL"
home_intact() { [ "$(cat "$SENTINEL")" = 'do not touch' ] && [ -z "$(find "$HOME" -mindepth 1 ! -name .secret | head -n 1)" ]; }
for planted in plugin containers settings tmp; do
    reset_docker
    T3="$WORK/planted-$planted"
    mkdir -p "$T3/install dir"
    ln -s "$HOME" "$T3/install dir/$planted"
    out=$(ci "$T3" --version=1.0.0 --no-plugin --no-roots); status=$?
    case $out in *"$T3/install dir/$planted is a symlink"*) said=yes ;; *) said=no ;; esac
    if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$T3/bin dir/knossos" ] && home_intact && [ "$(count_calls pull)" -eq 0 ]; then
        pass "a symlink planted at <install dir>/$planted is refused and nothing is written through it"
    else
        fail "planted $planted: $status, $out, home holds $(find "$HOME" -mindepth 1 | tr '\n' ' ')"
    fi
done
# An install directory that is a link of yours is followed, and its physical
# path is what the launcher bakes in; one that leads to the home directory is refused.
reset_docker
T3="$WORK/planted-install"
mkdir -p "$T3"
ln -s "$HOME" "$T3/install dir"
out=$(ci "$T3" --version=1.0.0 --no-plugin --no-roots); status=$?
case $out in *"the install directory $HOME is your home directory or contains it, which every container would be given read-only; choose a directory of its own with --install-dir"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && home_intact; then pass 'an install directory that is a link to the home directory is refused, and nothing is written there'; else fail "linked install dir to home: $status, $out"; fi
reset_docker
T3="$WORK/linked install"
mkdir -p "$T3/real install"
ln -s "$T3/real install" "$T3/install dir"
out=$(ci "$T3" --version=1.0.0 --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ] && grep -qxF "INSTALL_DIR='$T3/real install'" "$T3/bin dir/knossos" && [ -f "$T3/real install/install.json" ] \
    && [ "$(baked_pin "$T3/bin dir/knossos")" = "$(pin_of "$T3/real install")" ]; then
    pass 'a symlinked install directory is installed into at its physical path, which the launcher bakes and pins'
else
    fail "symlinked install dir: $status, $out"
fi
got=$(cd "$WORK/elsewhere" && PATH="$BARE" FAKE_IMAGE_VERSION=1.0.0 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$T3/bin dir/knossos" version 2>&1 </dev/null)
if [ "$got" = 'Knossos 1.0.0' ]; then pass 'and that launcher runs'; else fail "symlinked install dir launcher: $got"; fi
rm -rf "$WORK"/planted-* "$T3"

# ---- the plugin, the MCP registration, a root --------------------------------------------

reset_docker
T2="$WORK/with plugin"
PROJECT="$WORK/a project"
mkdir -p "$PROJECT"
rm -f "$CLAUDE_LOG"
out=$(KNOSSOS_ALLOW_ROOT=$PROJECT ci "$T2" --version=1.0.0); status=$?
DATA2="$T2/data dir"
INST2="$T2/install dir"
LAUNCHER2="$T2/bin dir/knossos"
DIGEST2=$(field "$INST2/install.json" image)
if [ "$status" -eq 0 ]; then pass 'a full install with the plugin step exits 0'; else fail "plugin install: $status, $out"; fi
if has_call "call [run] [--rm] [--pull=never] [--network] [none] [--user] [$UID_GID] [-e] [HOME=/tmp] [-e] [KNOSSOS_DATA_DIR=/tmp/knossos-check] [-e] [KNOSSOS_ROOTS_FILE=/tmp/knossos-check/roots.json] [--mount] [type=bind,source=$INST2/plugin,target=$INST2/plugin] [--entrypoint] [/opt/knossos/bin/knossos] [$DIGEST2] [install-agent-plugin] [--execute] [--no-register] [--plugin-dir=$INST2/plugin] [--bin=$LAUNCHER2] [--data-dir=$DATA2]"; then
    pass "the plugin is written by the image's install-agent-plugin, as this user, with only the plugin directory mounted read-write"
else
    fail "plugin run: $(grep install-agent-plugin "$FAKE_DOCKER_STATE/calls")"
fi
LIB="$INST2/plugin/hooks/scripts/lib.sh"
if grep -qxF "KNOSSOS_INSTALLED_BIN='$LAUNCHER2'" "$LIB" 2>/dev/null && grep -qxF "KNOSSOS_INSTALLED_DATA_DIR='$DATA2'" "$LIB" \
    && grep -qxF "KNOSSOS_INSTALLED_ROOTS_FILE='$INST2/roots.json'" "$LIB"; then
    pass "the plugin's lib.sh names the launcher, the graph and the host's allow-list in the install directory"
else
    fail "lib.sh: $(cat "$LIB" 2>&1)"
fi
expect_claude() { if grep -qxF "$1" "$CLAUDE_LOG" 2>/dev/null; then pass "$2"; else fail "$2: no call $1 in $(cat "$CLAUDE_LOG" 2>&1)"; fi; }
expect_claude "call [plugin] [marketplace] [add] [$INST2/plugin] [--scope] [user]" 'the marketplace is registered on the host at <install dir>/plugin'
expect_claude 'call [plugin] [install] [knossos@knossos] [--scope] [user] [--yes]' 'the plugin is installed from it'
expect_claude "call [mcp] [add] [knossos] [-s] [user] [-e] [KNOSSOS_DATA_DIR=$DATA2] [-e] [KNOSSOS_ROOTS_FILE=$INST2/roots.json] [--] [$LAUNCHER2] [serve]" 'the MCP server is registered with the launcher and the list in the install directory'
if grep -qxF "        \"$PROJECT\"" "$INST2/roots.json" 2>/dev/null && ! grep -q 'allow-root' "$FAKE_DOCKER_STATE/calls" && [ ! -e "$DATA2/roots.json" ]; then
    pass 'the project root is granted on the host, in the list in the install directory, with no container'
else
    fail "allow-root: $(cat "$INST2/roots.json" 2>&1), $(calls)"
fi

# ---- refusals that change nothing -------------------------------------------------------------

nothing_at() { [ ! -e "$1/install dir" ] && [ ! -e "$1/bin dir/knossos" ]; }

# A data directory or a launcher where a container could write them. The
# directory the installer runs from becomes a project root, which a container
# can write, as soon as knossos runs there.
# cid DIR DATA BIN ARGS: install.sh in container mode run from DIR, with the
# install directory beside the bin directory (BIN.install) unless ARGS name one.
cid() {
    from=$1 data=$2 bin=$3
    shift 3
    case " $* " in *' --install-dir='*) set -- "$@" ;; *) set -- "--install-dir=$bin.install" "$@" ;; esac
    (cd "$from" && PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 \
        sh "$INSTALL_SH" --mode=container --data-dir="$data" --bin-dir="$bin" --version=1.0.0 "$@" 2>&1 </dev/null)
}
# layout_refused WHAT TEXT FROM DATA BIN ARGS: refused in one line, nothing pulled, run or created.
layout_refused() {
    what=$1 text=$2
    shift 2
    reset_docker
    out=$(cid "$@"); status=$?
    case $out in *"$text"*) said=yes ;; *) said=no ;; esac
    if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ "$(count_calls pull)" -eq 0 ] && [ "$(count_calls run)" -eq 0 ] \
        && [ ! -e "$2" ] && [ ! -e "$3" ] && [ ! -e "$3.install" ]; then
        pass "$what is refused and nothing is created"
    else
        fail "$what: $status, $out, $(calls)"
    fi
}
P2="$WORK/proj2"
mkdir -p "$P2"
layout_refused 'a data directory inside the directory the installer runs from' \
    "$P2 contains the Knossos data directory $P2/.kn, which a container must never be able to write once $P2 is a project root; re-run the installer with --data-dir outside the project, or run it from another directory." \
    "$P2" "$P2/.kn" "$WORK/p2-bin" --no-plugin --no-roots
layout_refused 'a data directory inside the directory the installer runs from, dry run' 'contains the Knossos data directory' \
    "$P2" "$P2/.kn" "$WORK/p2-bin" --no-plugin --no-roots --dry-run
layout_refused 'a bin directory inside the directory the installer runs from' \
    "$P2 contains $P2/bin, the directory of the knossos launcher, which a container must never be able to write once $P2 is a project root; re-run the installer with --bin-dir outside the project, or run it from another directory." \
    "$P2" "$WORK/p2-data" "$P2/bin" --install-dir="$WORK/p2-install" --no-plugin --no-roots
layout_refused 'a bin directory inside the data directory' \
    "the directory of the knossos launcher $WORK/p2-data/bin lies inside the Knossos data directory $WORK/p2-data, which a container can write; re-run the installer with --bin-dir outside the data directory." \
    "$WORK" "$WORK/p2-data" "$WORK/p2-data/bin" --no-plugin --no-roots
# The root the installer grants, from KNOSSOS_ALLOW_ROOT, counts the same.
mkdir -p "$WORK/granted"
reset_docker
out=$(cd "$WORK/elsewhere" && KNOSSOS_ALLOW_ROOT="$WORK/granted" PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 \
    sh "$INSTALL_SH" --mode=container --data-dir="$WORK/granted/.kn" --bin-dir="$WORK/p2-bin" --version=1.0.0 --no-plugin 2>&1 </dev/null); status=$?
case $out in *"$WORK/granted contains the Knossos data directory"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/granted/.kn" ]; then pass 'a data directory inside KNOSSOS_ALLOW_ROOT is refused'; else fail "inside KNOSSOS_ALLOW_ROOT: $status, $out"; fi
# Run from the home directory, a data directory below it is the default and fine.
reset_docker
out=$(cid "$HOME" "$HOME/.knossos-test" "$WORK/home-bin" --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ] && [ -f "$WORK/home-bin.install/install.json" ]; then pass 'run from the home directory, a data directory below it is accepted'; else fail "from home: $status, $out"; fi
rm -rf "$HOME/.knossos-test" "$WORK/home-bin" "$WORK/home-bin.install"
# A data directory inside another installation's graph directory is a graph
# shared with it, as two projects share one: its containers can change the
# graph data, and nothing in it is what this installation's host side runs,
# which lives in the install directory. --force-clean removes nothing through
# a link, which a container there could have planted, and a dangling link
# creates nothing.
HELD="$WORK/held graph"
mkdir -p "$HELD/install/containers" "$WORK/victim/versions/0.9.0" "$WORK/victim/plugin"
printf '{"mode": "container", "layout": "separate"}\n' > "$HELD/install/install.json"
: > "$HELD/knossos.sqlite"
printf '{"mode": "native", "version": "0.9.0"}\n' > "$WORK/victim/install.json"
reset_docker
out=$(cid "$WORK" "$HELD/second" "$WORK/held-bin" --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ] && [ -f "$WORK/held-bin.install/install.json" ] && [ -z "$(ls -A "$HELD/second")" ] && [ "$(find "$HELD" -mindepth 1 -maxdepth 1 | LC_ALL=C sort | sed 's|.*/||' | tr '\n' ' ')" = 'install knossos.sqlite second ' ]; then
    pass "a data directory inside another installation's graph directory is shared, and nothing of this installation goes there"
else
    fail "data dir in another graph: $status, $out, $(ls -A "$HELD")"
fi
rm -rf "$HELD/second" "$WORK/held-bin" "$WORK/held-bin.install"
ln -s "$WORK/victim" "$HELD/work"
reset_docker
out=$(cid "$WORK" "$HELD/work" "$WORK/held-bin" --no-plugin --no-roots --force-clean); status=$?
case $out in *"--force-clean removes entries only in a data directory named by its physical path, and $HELD/work goes through a symbolic link to $WORK/victim"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ -f "$WORK/victim/install.json" ] && [ -d "$WORK/victim/versions/0.9.0" ] && [ -d "$WORK/victim/plugin" ] && [ "$(count_calls run)" -eq 0 ]; then
    pass '--force-clean through a link, which a container could have planted in a graph directory, removes nothing where it points'
else
    fail "force-clean through a planted link: $status, $out, $(ls -A "$WORK/victim")"
fi
rm -f "$HELD/work"
# A dangling link of yours that leads into such a graph: mkdir -p will not
# create through it, so the install stops before anything is written there.
ln -s "$HELD/made through" "$WORK/my dangling"
reset_docker
out=$(cid "$WORK" "$WORK/my dangling" "$WORK/held-bin" --no-plugin --no-roots); status=$?
if [ "$status" -ne 0 ] && [ ! -e "$HELD/made through" ] && [ ! -e "$WORK/held-bin/knossos" ] && [ ! -e "$WORK/held-bin.install" ]; then
    pass 'a dangling link of your own into a graph directory is refused before anything is written there, and the install directory it made is removed'
else
    fail "dangling link into a graph: $status, $out"
fi
rm -rf "$WORK/my dangling" "$HELD/made through"
# --help says that --no-plugin also skips the MCP registration, and names --install-dir.
case $(sh "$INSTALL_SH" --help) in *'--no-plugin    skip the Claude Code plugin and the MCP server registration'*) pass '--help says what --no-plugin skips' ;; *) fail '--help does not say that --no-plugin skips the registration' ;; esac
case $(sh "$INSTALL_SH" --help) in *'--install-dir  container mode: where the record'*) pass '--help names --install-dir' ;; *) fail '--help does not name --install-dir' ;; esac
# A TMPDIR inside a graph directory: the installer's work files and the helper
# it runs are in the install directory, so nothing is written or run there.
mkdir -p "$HELD/tmp"
reset_docker
out=$(cd "$WORK" && TMPDIR="$HELD/tmp" PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 \
    sh "$INSTALL_SH" --mode=container --data-dir="$WORK/held data" --install-dir="$WORK/held install" --bin-dir="$WORK/held-bin" --version=1.0.0 --no-plugin --no-roots 2>&1 </dev/null); status=$?
if [ "$status" -eq 0 ] && [ -z "$(ls -A "$HELD/tmp")" ] && [ -f "$WORK/held install/install.json" ]; then
    pass 'a TMPDIR inside a graph directory holds nothing of the install once it is done, and the helper never ran from it'
else
    fail "TMPDIR in a graph: $status, $out, $(ls -A "$HELD/tmp")"
fi
if ! grep -q "sh $HELD/tmp" "$FAKE_DOCKER_STATE/calls" && grep -qF "[--mount] [type=bind,source=$WORK/held install,target=$WORK/held install,readonly]" "$FAKE_DOCKER_STATE/calls"; then
    pass "and the installer's check through the launcher mounted the install directory read-only"
else
    fail "TMPDIR install calls: $(calls)"
fi
rm -rf "$HELD" "$WORK/victim" "$WORK/held-bin" "$WORK/held data" "$WORK/held install"

# A data directory that holds a native install is refused, or cleaned of
# exactly the native entries with --force-clean, its roots list and settings
# included: a container install keeps those in its install directory. The
# graph stays.
NAT="$WORK/was native"
mkdir -p "$NAT/data dir/versions/0.9.0" "$NAT/data dir/plugin"
printf '{"mode": "native", "version": "0.9.0"}\n' > "$NAT/data dir/install.json"
ln -s versions/0.9.0 "$NAT/data dir/current"
printf 'graph\n' > "$NAT/data dir/knossos.sqlite"
printf '{"roots": []}\n' > "$NAT/data dir/roots.json"
reset_docker
out=$(ci "$NAT" --version=1.0.0 --no-plugin --no-roots); status=$?
case $out in *"$NAT/data dir holds a native install (install.json versions current plugin roots.json); remove those entries or pick another --data-dir, or re-run with --force-clean to remove exactly them."*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ -e "$NAT/data dir/versions" ] && [ ! -e "$NAT/install dir" ] && [ "$(count_calls pull)" -eq 0 ]; then
    pass 'a container install into a data directory holding a native install is refused, and nothing changes'
else
    fail "over native: $status, $out"
fi
reset_docker
out=$(ci "$NAT" --version=1.0.0 --no-plugin --no-roots --force-clean); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$NAT/data dir/versions" ] && [ ! -e "$NAT/data dir/current" ] && [ ! -L "$NAT/data dir/current" ] \
    && [ ! -e "$NAT/data dir/install.json" ] && [ ! -e "$NAT/data dir/plugin" ] && [ "$(cat "$NAT/data dir/knossos.sqlite")" = graph ] \
    && [ ! -e "$NAT/data dir/roots.json" ] && [ -f "$NAT/install dir/install.json" ] && [ "$(ls -A "$NAT/data dir")" = knossos.sqlite ]; then
    pass '--force-clean removes exactly the native entries and the native roots file, keeps the graph, and installs'
else
    fail "force-clean over native: $status, $out, $(ls -A "$NAT/data dir")"
fi

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
out=$(PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 KNOSSOS_INSTALL_DIR="$WORK/auto/install dir" sh "$INSTALL_SH" --no-plugin --no-roots \
    --data-dir="$WORK/auto/data dir" --bin-dir="$WORK/auto/bin dir" --version=1.0.0 2>&1 </dev/null); status=$?
case $out in *'php not found'*'Using container mode: docker is available.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ "$(field "$WORK/auto/install dir/install.json" mode)" = container ]; then
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
case $out in *"install:    $WORK/dry/install dir (read-only to every container)"*"plugin:     write $WORK/dry/install dir/plugin from the image"*) pass '--dry-run names the install directory and where the plugin goes' ;; *) fail "dry run install line: $out" ;; esac
out=$(ci "$T1" --version=1.0.0 --dry-run); status=$?
case $out in *"install:    $INST (repaired in place: roots and settings kept; read-only to every container)"*) pass '--dry-run over an installation says it is repaired in place' ;; *) fail "dry run over an install: $status, $out" ;; esac

# KNOSSOS_IMAGE installs an image already on the host, for example one built
# here, and the version comes from the image when none is given.
reset_docker
printf 'knossos:local@sha256:9999\n' > "$FAKE_DOCKER_STATE/images/knossos_local"
out=$(PATH="$BARE" KNOSSOS_IMAGE=knossos:local FAKE_ANY_IMAGE=1 FAKE_IMAGE_VERSION=3.1.0 sh "$INSTALL_SH" --mode=container --no-plugin --no-roots \
    --data-dir="$WORK/local/data dir" --install-dir="$WORK/local/install dir" --bin-dir="$WORK/local/bin dir" 2>&1 </dev/null); status=$?
if [ "$status" -eq 0 ] && [ "$(count_calls pull)" -eq 0 ] && [ "$(field "$WORK/local/install dir/install.json" version)" = 3.1.0 ]; then
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
case $out in *"the launcher of the Knossos installed in $INST"*) pass 'replacing the launcher of another installation names it' ;; *) fail "replace launcher: $out" ;; esac
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

PIN2=$(pin_of "$INST2")
out=$(FAKE_IMAGE_VERSION=1.0.0 kn self-update --check); status=$?
if [ "$status" -eq 0 ] && [ "$out" = 'Knossos 1.0.0 is the latest version.' ]; then pass '--check with nothing newer exits 0'; else fail "--check, nothing newer: $status, $out"; fi
if grep -q '"latest": "1.0.0"' "$INST2/update-check.json" 2>/dev/null && [ ! -e "$GRAPH/update-check.json" ]; then
    pass '--check writes update-check.json to the install directory, not the graph directory'
else
    fail "update-check.json: $(cat "$INST2/update-check.json" 2>&1)"
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
touch -t 202001010000 "$INST2/update-check.json"
out=$(kn self-update --check --quiet); status=$?
if [ "$status" -eq 0 ] && [ -z "$out" ] && grep -q '"latest": "1.1.0"' "$INST2/update-check.json"; then
    pass 'the background check looks again once the answer is a day old, silently'
else
    fail "quiet stale check: $status, $out, $(cat "$INST2/update-check.json")"
fi
touch -t 202001010000 "$INST2/update-check.json"
out=$(KNOSSOS_NO_UPDATE_CHECK=1 kn self-update --check --quiet); status=$?
if [ "$status" -eq 0 ] && [ -z "$out" ] && [ -n "$(find "$INST2/update-check.json" -mmin +1439)" ]; then pass 'KNOSSOS_NO_UPDATE_CHECK=1 turns the background check off'; else fail "opt-out: $status, $out"; fi
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
NEW_DIGEST=$(field "$INST2/install.json" image)
case $out in *'Updated Knossos from 1.0.0 to 1.1.0.'*'restart it.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && has_call "call [pull] [$REPO:1.1.0]"; then pass 'self-update pulls the newer image and says to restart Claude Code'; else fail "update: $status, $out, $(calls)"; fi
if [ "$(field "$INST2/install.json" version)" = 1.1.0 ] && [ "$NEW_DIGEST" != "$DIGEST2" ] && case $NEW_DIGEST in "$REPO@sha256:"*) true ;; *) false ;; esac \
    && [ "$(field "$INST2/install.json" previous_image)" = "$DIGEST2" ] && [ "$(field "$INST2/install.json" previous_version)" = 1.0.0 ]; then
    pass 'install.json names the new image by digest and keeps the previous one'
else
    fail "install.json after update: $(cat "$INST2/install.json")"
fi
if [ "$before_running" -ge 1 ] && [ "$(running)" -eq 0 ]; then pass "the installation's running containers are stopped"; else fail "containers: $before_running before, $(running) after"; fi
if grep -qxF "FROM_IMAGE='$NEW_DIGEST'" "$LIB" && grep -qxF "KNOSSOS_INSTALLED_DATA_DIR='$GRAPH'" "$LIB" && grep -qxF "KNOSSOS_INSTALLED_BIN='$LAUNCHER2'" "$LIB"; then
    pass 'the plugin is rewritten by the new image and keeps its hand-set graph directory'
else
    fail "plugin after update: $(cat "$LIB")"
fi
if [ "$(cat "$LAUNCHER2")" = "$launcher_before" ] && has_call "call [run] [--rm] [--pull=never] [--network] [none] [--user] [$UID_GID] [--entrypoint] [cat] [$NEW_DIGEST] [/opt/knossos/launcher/knossos]"; then
    pass 'the launcher is rewritten from the new image'
else
    fail "launcher after update: $(calls)"
fi
if [ -z "$(find "$T2" -name '*.tmp.*')" ] && [ ! -e "$INST2/self-update.lock.d" ]; then pass 'no temporary file or lock is left'; else fail 'leftovers after the update'; fi
if ! grep '^call \[run\]' "$FAKE_DOCKER_STATE/calls" | grep -qv -e '\[--network\] \[none\]'; then pass 'no container the update runs has a network'; else fail "networked run: $(calls)"; fi

out=$(FAKE_IMAGE_VERSION=1.1.0 kn self-update); status=$?
if [ "$status" -eq 0 ] && [ "$out" = 'Knossos 1.1.0 is already the latest version.' ]; then pass 'a second self-update says it is already the latest'; else fail "second update: $status, $out"; fi
if [ "$(pin_of "$INST2")" = "$PIN2" ] && [ "$(baked_pin "$LAUNCHER2")" = "$PIN2" ]; then
    pass 'self-update never replaces the install directory, and the launcher it rewrites keeps the pin'
else
    fail "pin after update: dir $(pin_of "$INST2"), launcher $(baked_pin "$LAUNCHER2"), installed $PIN2"
fi

out=$(kn self-update --rollback); status=$?
case $out in *'Rolled back from 1.1.0 to 1.0.0.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ "$(field "$INST2/install.json" image)" = "$DIGEST2" ] && [ "$(field "$INST2/install.json" previous_image)" = "$NEW_DIGEST" ] \
    && grep -qxF "FROM_IMAGE='$DIGEST2'" "$LIB"; then
    pass '--rollback returns to the previous digest, plugin included'
else
    fail "rollback: $status, $out, $(cat "$INST2/install.json")"
fi
if [ "$(pin_of "$INST2")" = "$PIN2" ] && [ "$(baked_pin "$LAUNCHER2")" = "$PIN2" ]; then pass '--rollback keeps the install directory and its pin'; else fail 'rollback changed the install directory or its pin'; fi
# shellcheck disable=SC2016 # the pattern matches the helper's own "$INSTALL", unexpanded
REMOVES_INSTALL='(rm -rf|mv -f|rmdir)[^|;]*"\$INSTALL"( |$|;)'
if ! grep -nE "$REMOVES_INSTALL" "$ROOT/tools/launcher/container-update" >/dev/null; then
    pass 'the update helper has no command that removes or moves the install directory itself'
else
    fail "the helper can remove or move the install directory: $(grep -nE "$REMOVES_INSTALL" "$ROOT/tools/launcher/container-update")"
fi

# A plugin step that fails puts install.json and the launcher back.
cp "$INST2/install.json" "$WORK/record.before"
cp "$LAUNCHER2" "$WORK/launcher.before"
out=$(FAKE_IMAGE_VERSION=1.1.0 FAKE_PLUGIN_FAILS=1 kn self-update --version=1.1.0); status=$?
case $out in *'could not write its plugin'*'previous state was restored'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && cmp -s "$WORK/record.before" "$INST2/install.json" && cmp -s "$WORK/launcher.before" "$LAUNCHER2"; then
    pass 'a failure after the switch puts install.json and the launcher back'
    if [ ! -e "$INST2/self-update.lock.d" ] && [ ! -e "$INST2/self-update.lock.d.takeover" ]; then
        pass 'a failed update leaves no lock behind'
    else
        fail 'a failed update left its lock'
    fi
else
    fail "failed update: $status, $out, $(cat "$INST2/install.json")"
fi

# A plugin step that fails half way, after it wrote part of the plugin, puts
# the plugin back too: the hooks never run against an older core.
cp "$LIB" "$WORK/lib.before"
out=$(FAKE_IMAGE_VERSION=1.1.0 FAKE_PLUGIN_FAILS=late kn self-update --version=1.1.0); status=$?
case $out in *'could not write its plugin'*'previous state was restored'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && cmp -s "$WORK/lib.before" "$LIB" && cmp -s "$WORK/record.before" "$INST2/install.json"; then
    pass 'a plugin step that fails half way puts the plugin back as it was'
else
    fail "half-written plugin: $status, $out, lib.sh $(cat "$LIB")"
fi

# An image that is not the version asked for changes nothing.
out=$(FAKE_IMAGE_VERSION=9.9.9 kn self-update --version=1.1.0); status=$?
if [ "$status" -ne 0 ] && cmp -s "$WORK/record.before" "$INST2/install.json"; then pass 'an image of the wrong version is refused before anything changes'; else fail "wrong version: $status, $out"; fi

# Exactly one previous image is kept.
: > "$REL/knossos-1.2.0-linux-x86_64.tar.gz"
FAKE_IMAGE_VERSION=1.1.0 kn self-update --version=1.1.0 >/dev/null
: > "$FAKE_DOCKER_STATE/calls"
out=$(FAKE_IMAGE_VERSION=1.2.0 kn self-update); status=$?
if [ "$status" -eq 0 ] && has_call "call [image] [rm] [$DIGEST2]" && [ "$(field "$INST2/install.json" previous_image)" = "$NEW_DIGEST" ]; then
    pass 'the image before the previous one is removed, the previous one kept'
else
    fail "prune: $status, $out, $(calls)"
fi

# One update at a time.
mkdir "$INST2/self-update.lock.d"
printf '%s\n' "$$" > "$INST2/self-update.lock.d/pid"
out=$(kn self-update --rollback); status=$?
case $out in *'another knossos self-update is running'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'a second self-update while one runs is refused'; else fail "lock: $status, $out"; fi
printf '999999999\n' > "$INST2/self-update.lock.d/pid"
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
cp "$INST2/install.json" "$WORK/record.before"
cp "$LAUNCHER2" "$WORK/launcher.before"
out=$(FAKE_EXEC_EXIT=3 FIV=1.2.0 ci "$T2" --version=1.2.0 --no-roots); status=$?
case $out in *'the previous state was restored'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && cmp -s "$WORK/lib.before" "$LIB" && cmp -s "$WORK/record.before" "$INST2/install.json" \
    && cmp -s "$WORK/launcher.before" "$LAUNCHER2"; then
    pass 'a failed check after the switch restores install.json, the launcher and the plugin'
else
    fail "probe failure: $status, $out, lib.sh $(cat "$LIB")"
fi

# Every switch leaves the helper of the image switched to beside install.json.
if cmp -s "$ROOT/tools/launcher/container-update" "$INST2/container-update"; then pass 'the update helper is kept beside install.json'; else fail 'no helper copy in the install directory'; fi

# The image the installation runs is pruned: the launcher says so, and
# self-update fetches it again without needing it.
CUR=$(field "$INST2/install.json" version)
CUR_IMAGE=$(field "$INST2/install.json" image)
# As `docker rmi` would: every name of that image goes, its tag included.
for file in "$FAKE_DOCKER_STATE"/images/*; do
    if [ "$(cat "$file")" = "$CUR_IMAGE" ]; then rm -f "$file"; fi
done
out=$(FAKE_IMAGE_VERSION=$CUR kn version); status=$?
case $out in *'is gone from this host: run knossos self-update to fetch it again'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ]; then pass 'a pruned image is reported with what works'; else fail "pruned image: $status, $out"; fi
: > "$FAKE_DOCKER_STATE/calls"
out=$(FAKE_IMAGE_VERSION=$CUR kn self-update --version="$CUR"); status=$?
if [ "$status" -eq 0 ] && has_call "call [pull] [$REPO:$CUR]" && [ "$(field "$INST2/install.json" version)" = "$CUR" ]; then
    pass 'self-update works with the current image gone, and fetches it again'
else
    fail "repair: $status, $out, $(calls)"
fi
out=$(FAKE_IMAGE_VERSION=$CUR kn version); status=$?
if [ "$status" -eq 0 ] && [ "$out" = "Knossos $CUR" ]; then pass 'after the repair the launcher runs again'; else fail "after repair: $status, $out"; fi

# ---- an update leaves no container of the old image, and no markers ------------------------

# A call that read the old install.json starts a container of the old image
# right after the update's first stop: the stop after the switch ends it.
FAKE_IMAGE_VERSION=$CUR kn version >/dev/null
OLD_CALL="$FAKE_DOCKER_STATE/containers/knossos-aaaaaaaaaaaa"
INSTALL_LABEL=$(printf '%s' "$INST2" | sha256sum | cut -c 1-12)
printf "printf 'running\\\\n' > '%s'\nprintf 'io.knossos.install=%s\\\\nio.knossos.root=000000000000\\\\n' > '%s.labels'\n" \
    "$OLD_CALL" "$INSTALL_LABEL" "$OLD_CALL" > "$FAKE_DOCKER_STATE/after-stop"
: > "$REL/knossos-1.3.0-linux-x86_64.tar.gz"
out=$(FAKE_IMAGE_VERSION=1.3.0 kn self-update --version=1.3.0); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$FAKE_DOCKER_STATE/after-stop" ] && [ "$(running)" -eq 0 ]; then
    pass 'a container a call started during the update, from the old install.json, is stopped by the update too'
else
    fail "old-image container after update: $status, $(running) running, $out"
fi
if [ -z "$(find "$INST2/containers" -name 'knossos-*' 2>/dev/null)" ]; then pass 'the update removes the markers of the containers it stopped'; else fail "markers after update: $(ls "$INST2/containers")"; fi
# Only names of that pattern: a file of another name there is left.
: > "$INST2/containers/knossos-NOTAHEXNAMEX"
: > "$REL/knossos-1.3.1-linux-x86_64.tar.gz"
FAKE_IMAGE_VERSION=1.3.1 kn self-update --version=1.3.1 >/dev/null
if [ -f "$INST2/containers/knossos-NOTAHEXNAMEX" ]; then pass 'the update removes only names of the marker pattern'; else fail 'the update removed a file that is no marker'; fi
rm -f "$INST2/containers/knossos-NOTAHEXNAMEX"
rm -f "$FAKE_DOCKER_STATE/after-stop"

# ---- a data directory that holds things of yours ------------------------------------------

# A container install hands every container its data directory read-write.
CODE="$WORK/code"
mkdir -p "$CODE/repo2/.git/hooks"
foreign_refused() {
    what=$1
    shift
    reset_docker
    out=$("$@"); status=$?
    case $out in *"the data directory $CODE holds repo2, which is not something Knossos puts in a data directory, and in container mode every container would be able to write all of it; move it out of $CODE (when a container left it there, nothing in it is used), or choose an empty directory with --data-dir (or KNOSSOS_DATA_DIR). Nothing was changed."*) said=yes ;; *) said=no ;; esac
    if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ "$(count_calls pull)" -eq 0 ] && [ "$(count_calls run)" -eq 0 ] \
        && [ "$(ls -A "$CODE")" = repo2 ] && [ ! -e "$WORK/code bin" ]; then
        pass "$what is refused, and nothing is pulled, run or created"
    else
        fail "$what: $status, $out, $(ls -A "$CODE"), $(calls)"
    fi
}
foreign_refused 'a --data-dir that holds a folder of yours' cid "$WORK/elsewhere" "$CODE" "$WORK/code bin" --no-plugin --no-roots
foreign_refused 'a --data-dir that holds a folder of yours, dry run' cid "$WORK/elsewhere" "$CODE" "$WORK/code bin" --no-plugin --no-roots --dry-run
# shellcheck disable=SC2016 # the sh -c program expands its own arguments
foreign_refused 'a KNOSSOS_DATA_DIR that holds a folder of yours' \
    sh -c 'cd "$1" && PATH="$2" KNOSSOS_IMAGE_REPOSITORY="$3" FAKE_IMAGE_VERSION=1.0.0 KNOSSOS_DATA_DIR="$4" sh "$5" --mode=container --bin-dir="$6" --version=1.0.0 --no-plugin --no-roots 2>&1 </dev/null' \
    _ "$WORK/elsewhere" "$BARE" "$REPO" "$CODE" "$INSTALL_SH" "$WORK/code bin"
# Beside a native install, with --force-clean: refused before anything is removed.
mkdir -p "$CODE/versions/1.0.0"
printf '{"mode": "native"}\n' > "$CODE/install.json"
reset_docker
out=$(cid "$WORK/elsewhere" "$CODE" "$WORK/code bin" --no-plugin --no-roots --force-clean); status=$?
case $out in *"the data directory $CODE holds repo2, which is not something Knossos puts in a data directory"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ -f "$CODE/install.json" ] && [ -d "$CODE/versions/1.0.0" ] && [ "$(count_calls pull)" -eq 0 ]; then
    pass 'a folder of yours beside a native install is refused before --force-clean removes anything'
else
    fail "foreign entry beside a native install: $status, $out, $(ls -A "$CODE")"
fi
rm -rf "${CODE:?}/versions" "${CODE:?}/install.json"
# The data directory a native install's graph left behind: only what Knossos put there.
KNOWN="$WORK/known data"
mkdir -p "$KNOWN/backups" "$KNOWN/watch"
for entry in knossos.sqlite knossos.sqlite-wal knossos.sqlite-shm scan-failures.jsonl mcp-serve.log; do : > "$KNOWN/$entry"; done
reset_docker
out=$(cid "$WORK/elsewhere" "$KNOWN" "$WORK/known bin" --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ] && [ -f "$WORK/known bin.install/install.json" ]; then pass 'a --data-dir holding only what Knossos puts there is taken'; else fail "known entries: $status, $out"; fi
# The settings a native install keeps beside its graph are not graph data:
# named, with --force-clean to remove them.
for entry in roots.json enrichers.json pane-autostart.json; do
    printf '{}\n' > "$KNOWN/$entry"
    reset_docker
    out=$(cid "$WORK/elsewhere" "$KNOWN" "$WORK/known bin" --no-plugin --no-roots); status=$?
    case $out in *"$KNOWN holds a native install ($entry); remove those entries or pick another --data-dir, or re-run with --force-clean to remove exactly them."*) said=yes ;; *) said=no ;; esac
    if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ -f "$KNOWN/$entry" ]; then pass "a native $entry beside the graph is named and kept"; else fail "native $entry: $status, $out"; fi
    rm -f "$KNOWN/$entry"
done
# Reinstalling over a container install: what is not graph data in its data
# directory is named, as every new container would be refused for it.
mkdir -p "$KNOWN/written by a container"
reset_docker
out=$(cid "$WORK/elsewhere" "$KNOWN" "$WORK/known bin" --no-plugin --no-roots); status=$?
case $out in *"the data directory $KNOWN holds written by a container, which is not something Knossos puts in a data directory"*"move it out of $KNOWN (when a container left it there, nothing in it is used)"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'a reinstall names what a container left in the data directory, and how to remove it'; else fail "reinstall over junk: $status, $out"; fi
rmdir "$KNOWN/written by a container"
reset_docker
out=$(cid "$WORK/elsewhere" "$KNOWN" "$WORK/known bin" --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ]; then pass 'and once it is gone, the reinstall works'; else fail "reinstall: $status, $out"; fi
# A fresh install leaves no marker behind: the check through the launcher stops its container.
if [ -z "$(find "$WORK/known bin.install/containers" -name 'knossos-*' 2>/dev/null)" ]; then pass "the installer's own check leaves no marker in the install directory"; else fail "probe marker left: $(ls "$WORK/known bin.install/containers")"; fi
rm -rf "${CODE:?}" "${KNOWN:?}" "${WORK:?}/known bin" "${WORK:?}/known bin.install"

# One pattern for the names of idle markers, in the launcher and the helper.
marker_of() { grep "^MARKER_GLOB='" "$1"; }
if [ -n "$(marker_of "$ROOT/tools/launcher/knossos")" ] && [ "$(marker_of "$ROOT/tools/launcher/knossos")" = "$(marker_of "$ROOT/tools/launcher/container-update")" ]; then
    pass 'the launcher and the update helper name idle markers by the same pattern'
else
    fail 'the marker patterns of the launcher and the helper differ'
fi
# The lists the installer checks against are the launcher's, word for word.
entries_of() { awk '/^GRAPH_ENTRIES=/ { on = 1 } on { print } on && /^foreign_entry\(\) \{/ { last = 1 } last && /^}$/ { exit }' "$1"; }
if [ -n "$(entries_of "$ROOT/tools/launcher/knossos")" ] && [ "$(entries_of "$ROOT/tools/launcher/knossos")" = "$(entries_of "$INSTALL_SH")" ]; then
    pass 'install.sh and the launcher carry the same lists of what a graph directory may hold'
else
    fail 'the graph entry lists in install.sh and the launcher differ'
fi

# ---- native mode is chosen only on a host that passes doctor ---------------------------------

# Stand-ins answering as a host with PHP 8.3, Node 24, Python 3.11 and git
# would; STUB_PHP_LACKS names the extensions php says are not loaded.
STUB="$WORK/stub runtimes"
mkdir -p "$STUB"
cat > "$STUB/php" <<'PHP'
#!/bin/sh
# STUB_PHP_WARN: a startup warning on stdout first, as PHP prints one for a
# stale extension= line with its default display_errors.
case "$*" in
    *extension_loaded*)
        [ -z "${STUB_PHP_WARN:-}" ] || printf 'PHP Warning:  Module "pdo_sqlite" is already loaded in Unknown on line 0\n'
        lacks=${STUB_PHP_LACKS:-}
        printf '\nKNOSSOS-MISSING-EXTENSIONS:%s\n' "${lacks% }"
        ;;
    *PHP_VERSION_ID*) exit 0 ;;
    *) echo 8.3.0 ;;
esac
PHP
printf '#!/bin/sh\necho v24.0.0\n' > "$STUB/node"
printf '#!/bin/sh\necho Python 3.12.0\n' > "$STUB/python3"
printf '#!/bin/sh\necho git version 2.45.0\n' > "$STUB/git"
chmod +x "$STUB/php" "$STUB/node" "$STUB/python3" "$STUB/git"
ln -s "$(command -v tar)" "$STUB/tar"
NOGIT="$WORK/stub without git"
mkdir -p "$NOGIT"
for tool in php node python3 tar; do ln -s "$STUB/$tool" "$NOGIT/$tool"; done
NODOCKER_BARE="$WORK/bare without docker"
mkdir -p "$NODOCKER_BARE"
for tool in "$BARE"/*; do
    [ "${tool##*/}" != docker ] || continue
    if [ -L "$tool" ]; then ln -s "$(readlink "$tool")" "$NODOCKER_BARE/${tool##*/}"; else ln -s "$tool" "$NODOCKER_BARE/${tool##*/}"; fi
done
# detect PATH DIR ARGS: install.sh on that PATH into DIR, with no release to download.
detect() {
    front=$1 dir=$2
    shift 2
    (cd "$WORK/elsewhere" && PATH="$front" KNOSSOS_RELEASE_BASE="$WORK/no releases" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 KNOSSOS_INSTALL_DIR="$dir/install dir" \
        sh "$INSTALL_SH" --data-dir="$dir/data dir" --bin-dir="$dir/bin dir" --version=1.0.0 --no-plugin --no-roots "$@" 2>&1 </dev/null)
}
reset_docker
out=$(STUB_PHP_LACKS='mbstring pdo_sqlite ' detect "$STUB:$BARE" "$WORK/no mbstring"); status=$?
case $out in *'This host cannot run Knossos natively. Missing:'*'  - the PHP extension(s) mbstring, pdo_sqlite (not loaded by php)'*'Using container mode: docker is available.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ "$(field "$WORK/no mbstring/install dir/install.json" mode)" = container ]; then
    pass 'a PHP without mbstring and pdo_sqlite is named, and container mode is used when Docker answers'
else
    fail "missing extensions with docker: $status, $out"
fi
reset_docker
out=$(detect "$NOGIT:$BARE" "$WORK/no git"); status=$?
case $out in *'  - Git (git not found)'*'Using container mode: docker is available.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'a host without git is not given native mode'; else fail "no git: $status, $out"; fi
reset_docker
out=$(STUB_PHP_LACKS='pdo_sqlite ' detect "$STUB:$NODOCKER_BARE" "$WORK/no runtime"); status=$?
case $out in *'  - the PHP extension(s) pdo_sqlite (not loaded by php)'*'error: install what is listed above and run the installer again, or install Docker or Podman (with its daemon running) and re-run it with --mode=container'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/no runtime" ]; then
    pass 'without Docker or Podman it stops before writing anything, names what is missing and both ways out'
else
    fail "missing extension, no docker: $status, $out"
fi
reset_docker
out=$(STUB_PHP_LACKS='mbstring ' detect "$STUB:$BARE" "$WORK/forced native" --mode=native); status=$?
case $out in *'  - the PHP extension(s) mbstring (not loaded by php)'*'error: install what is listed above and run the installer again, or re-run it with --mode=container: docker is available'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/forced native" ] && [ "$(count_calls pull)" -eq 0 ]; then
    pass '--mode=native on such a host stops the same way, and points at --mode=container'
else
    fail "forced native: $status, $out"
fi
reset_docker
out=$(detect "$STUB:$BARE" "$WORK/all there" --dry-run); status=$?
case $out in *'mode:       native'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'a host with everything doctor checks gets native mode'; else fail "all there: $status, $out"; fi
reset_docker
out=$(STUB_PHP_WARN=1 detect "$STUB:$BARE" "$WORK/php warns" --dry-run); status=$?
case $out in *'mode:       native'*) said=yes ;; *) said=no ;; esac
case $out in *'Missing:'*) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'a PHP startup warning on stdout is not read as a missing extension'; else fail "php warning: $status, $out"; fi
reset_docker
out=$(STUB_PHP_LACKS='tokenizer ' detect "$STUB:$BARE" "$WORK/no tokenizer"); status=$?
case $out in *'  - the PHP extension(s) tokenizer (not loaded by php)'*'Using container mode: docker is available.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'a PHP without tokenizer, which the PHP worker needs, is not given native mode'; else fail "no tokenizer: $status, $out"; fi
rm -rf "${WORK:?}/php warns" "${WORK:?}/no tokenizer"
rm -rf "${WORK:?}/no mbstring" "${WORK:?}/no git" "${WORK:?}/all there"

# ---- a root container mode never mounts is not granted -------------------------------------

GRANT_DIR="$WORK/grant from the home directory"
reset_docker
out=$(cd "$WORK/elsewhere" && KNOSSOS_ALLOW_ROOT="$HOME" PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 \
    sh "$INSTALL_SH" --mode=container --data-dir="$GRANT_DIR/data dir" --install-dir="$GRANT_DIR/install dir" --bin-dir="$GRANT_DIR/bin dir" --version=1.0.0 --no-plugin 2>&1 </dev/null); status=$?
case $out in *"no project root granted: $HOME is /, your home directory or a directory above it, which container mode never mounts; grant a project inside it with knossos allow-root <project> --execute"*) said=yes ;; *) said=no ;; esac
case $out in *'could not add'*) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ "$(tr -d ' \n' < "$GRANT_DIR/install dir/roots.json")" = '{"roots":[]}' ]; then
    pass 'the home directory as the root to grant is skipped with the reason, and no command that would fail is suggested'
else
    fail "home as root: $status, $out"
fi
rm -rf "${GRANT_DIR:?}"

# ---- nothing in a data directory is opened by the installer ------------------------------

# Hostile entries at every name the layout before this one trusted, in the
# data directory: FIFOs (a read would hang), links to a secret (a read would
# leak it), a script that leaves PWNED when run. The installer opens and runs
# none of them, refuses the directory as no graph, and finishes.
PWNED="$HOME/pwned"
SECRET="$HOME/.install-secret"
printf 'SECRET-OF-THE-HOST\n' > "$SECRET"
HOSTILE="$WORK/hostile data"
mkdir -p "$HOSTILE/install/containers" "$HOSTILE/install/plugin/hooks/scripts" "$HOSTILE/.knossos-graph"
mkfifo "$HOSTILE/install/install.json" "$HOSTILE/install.json" "$HOSTILE/roots.json" "$HOSTILE/enrichers.json"
printf '#!/bin/sh\n: > "%s"\n' "$PWNED" > "$HOSTILE/install/container-update"
cp "$HOSTILE/install/container-update" "$HOSTILE/container-update"
cp "$HOSTILE/install/container-update" "$HOSTILE/install/plugin/hooks/scripts/lib.sh"
ln -s "$SECRET" "$HOSTILE/pane-autostart.json"
ln -s "$SECRET" "$HOSTILE/update-check.json"
TIMEOUT=$(command -v timeout)
for dry in no yes; do
    reset_docker
    if [ "$dry" = yes ]; then set -- --dry-run; else set --; fi
    out=$(cd "$WORK/elsewhere" && PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 "$TIMEOUT" 20 \
        sh "$INSTALL_SH" --mode=container --data-dir="$HOSTILE" --install-dir="$WORK/hostile install" --bin-dir="$WORK/hostile bin" --version=1.0.0 --no-plugin --no-roots "$@" 2>&1 </dev/null); status=$?
    case $out in *SECRET-OF-THE-HOST*) leaked=yes ;; *) leaked=no ;; esac
    if [ "$status" -ne 124 ] && [ "$status" -ne 0 ] && [ "$leaked" = no ] && [ ! -e "$PWNED" ] && [ ! -e "$WORK/hostile install" ] && [ "$(count_calls pull)" -eq 0 ]; then
        pass "a data directory with hostile entries at every name the old layout trusted is refused without opening or running any (dry run: $dry)"
    else
        fail "hostile data dir ($dry): $status, $out"
    fi
done
rm -rf "$HOSTILE" "$SECRET"

# A runtime that cannot be asked which containers run: the installer cannot
# tell whether one can write the install directory, and refuses.
reset_docker
: > "$FAKE_DOCKER_STATE/ps-fails"
out=$(ci "$WORK/ps fails" --version=1.0.0 --no-plugin --no-roots); status=$?
rm -f "$FAKE_DOCKER_STATE/ps-fails"
case $out in *"could not ask docker which containers run, so the installer cannot tell whether one can write the install directory"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/ps fails" ] && [ "$(count_calls pull)" -eq 0 ]; then pass 'the installer refuses when the runtime cannot be asked which containers run'; else fail "installer with ps failing: $status, $out"; fi

# ---- the reproductions of the review rounds, in this layout ------------------------------

# Installation A, with a container running that has A's graph read-write.
reset_docker
IA="$WORK/inst a/install"
GA="$WORK/inst a/data"
out=$(cid "$WORK/elsewhere" "$GA" "$WORK/inst a/bin" --install-dir="$IA" --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ]; then pass 'a first container install for the reproductions'; else fail "install a: $status, $out"; fi
mkdir -p "$WORK/proj a"
start_a() { (cd "$WORK/proj a" && PATH="$BARE" FAKE_IMAGE_VERSION=1.0.0 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/inst a/bin/knossos" version </dev/null >/dev/null 2>&1); }
start_a
# 1. Another installation's graph directory as this one's data directory:
#    shared, and this installation's install directory stays outside it.
out=$(cid "$WORK/elsewhere" "$GA" "$WORK/inst b/bin" --install-dir="$WORK/inst b/install" --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ] && [ -z "$(ls -A "$GA")" ] && [ "$(field "$WORK/inst b/install/install.json" data_dir)" = "$GA" ]; then
    pass "a second installation with another installation's graph as its data directory shares the graph and puts nothing of its own there"
else
    fail "shared graph install: $status, $out, $(ls -A "$GA")"
fi
# 2. The mark is gone, and the install directory is never put in another
#    installation's graph: refused while that graph holds a database, and
#    refused while a container of it runs, whatever was deleted from it.
: > "$GA/knossos.sqlite"
owned_refused() {
    what=$1 inst=$2 text=$3
    shift 3
    : > "$FAKE_DOCKER_STATE/calls"
    out=$(cid "$WORK/elsewhere" "$WORK/inst c/data" "$WORK/inst c/bin" --install-dir="$inst" --no-plugin --no-roots "$@"); status=$?
    case $out in *"$text"*) said=yes ;; *) said=no ;; esac
    if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ "$(count_calls pull)" -eq 0 ] && [ ! -e "$inst" ] && [ ! -e "$WORK/inst c" ]; then
        pass "$what is refused, and nothing is pulled or created"
    else
        fail "$what: $status, $out"
    fi
}
owned_refused "an install directory in another installation's graph directory, which holds a database" "$GA/inst" \
    "the install directory $GA/inst lies in $GA, which holds a Knossos graph (knossos.sqlite): a data directory, which containers can write; choose a directory of its own with --install-dir"
owned_refused "the same, dry run" "$GA/inst" "lies in $GA, which holds a Knossos graph" --dry-run
rm -f "$GA/knossos.sqlite"
owned_refused "an install directory in a graph a running container of another installation can write, emptied" "$GA/inst" \
    "the install directory $GA/inst lies in $GA, which the running Knossos container"
# Emptied and with no container running, the install goes through; the first
# container of the other installation to start with that graph is refused
# then, for what is in it, so the new install directory is never in a
# container's read-write mount.
(cd "$WORK/proj a" && PATH="$BARE" "$WORK/inst a/bin/knossos" stop --all </dev/null >/dev/null 2>&1)
out=$(cid "$WORK/elsewhere" "$WORK/inst c/data" "$WORK/inst c/bin" --install-dir="$GA/inst" --no-plugin --no-roots); status=$?
out_a=$(cd "$WORK/proj a" && PATH="$BARE" FAKE_IMAGE_VERSION=1.0.0 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/inst a/bin/knossos" version </dev/null 2>&1); status_a=$?
case $out_a in *"the graph directory $GA holds inst, which Knossos does not put there in that form, so no container is started with it"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$status_a" -eq 1 ] && [ "$said" = yes ] && [ "$(running)" -eq 0 ]; then
    pass "an install directory put into another installation's graph when nothing guarded it is never mounted read-write: that graph's containers are refused"
else
    fail "install dir in an idle graph: $status, $out, then $status_a, $out_a"
fi
rm -rf "$GA/inst" "$WORK/inst c"
# 3. An install/ planted in a graph, with the old layout's update helper in
#    it: the update checks and calls of both installations run nothing of it.
start_a
mkdir -p "$GA/install/containers"
printf '{"mode": "container", "layout": "separate", "image": "evil", "data_dir": "%s"}\n' "$GA" > "$GA/install/install.json"
printf '#!/bin/sh\n: > "%s"\n' "$PWNED" > "$GA/install/container-update"
rm -f "$PWNED"
for inst in a b; do
    touch -t 202001010000 "$WORK/inst $inst/install/update-check.json" 2>/dev/null
    (cd / && PATH="$BARE" KNOSSOS_RELEASE_BASE="$REL" FAKE_IMAGE_VERSION=1.0.0 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/inst $inst/bin/knossos" self-update --check --quiet </dev/null >/dev/null 2>&1)
    (cd "$WORK/proj a" && PATH="$BARE" FAKE_IMAGE_VERSION=1.0.0 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/inst $inst/bin/knossos" version </dev/null >/dev/null 2>&1)
done
if [ ! -e "$PWNED" ] && ! grep -qF '[evil]' "$FAKE_DOCKER_STATE/calls"; then pass 'an install/ planted in a shared graph is never read or run by either installation'; else fail "planted install in a graph: pwned $(ls "$PWNED" 2>&1), $(calls)"; fi
rm -rf "$GA/install"
(cd / && PATH="$BARE" "$WORK/inst a/bin/knossos" stop --all </dev/null >/dev/null 2>&1)

# ---- reinstall and repair ---------------------------------------------------------------

# rr: the installer again for installation A, with its own directories.
rr() { cid "$WORK/elsewhere" "$GA" "${RR_BIN:-$WORK/inst a/bin}" --install-dir="$IA" --no-plugin --no-roots; }
LA="$WORK/inst a/bin/knossos"
# What a repair must keep: a granted root and an enricher.
(cd / && PATH="$BARE" "$LA" allow-root "$WORK/proj a" --execute </dev/null >/dev/null 2>&1)
printf '{"enrichers": [{"name": "kept", "command": ["tool"]}]}\n' > "$IA/settings/enrichers.json"
kept() { grep -qF "\"$WORK/proj a\"" "$IA/roots.json" && grep -q '"kept"' "$IA/settings/enrichers.json"; }
answers() { [ "$(cd "$WORK/proj a" && PATH="$BARE" FAKE_IMAGE_VERSION=1.0.0 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$1" version 2>&1 </dev/null)" = 'Knossos 1.0.0' ]; }
if kept && answers "$LA"; then pass 'installation A has a root and an enricher to keep'; else fail "setup for repairs: $(cat "$IA/roots.json")"; fi
# a. The launcher deleted.
rm -f "$LA"
reset_docker
out=$(rr); status=$?
if [ "$status" -eq 0 ] && answers "$LA" && kept && [ "$(baked_pin "$LA")" = "$(pin_of "$IA")" ]; then pass 'a deleted launcher is repaired by running the installer again, roots and enrichers kept'; else fail "deleted launcher: $status, $out"; fi
# b. Another bin directory.
reset_docker
out=$(RR_BIN="$WORK/inst a/new bin" rr); status=$?
case $out in *"the launcher in $WORK/inst a/bin still runs this installation; remove $WORK/inst a/bin/knossos if nothing calls it"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && answers "$WORK/inst a/new bin/knossos" && kept && [ "$(field "$IA/install.json" bin_dir)" = "$WORK/inst a/new bin" ]; then
    pass 'a changed bin directory is taken by running the installer again, which says where the old launcher is'
else
    fail "changed bin dir: $status, $out"
fi
rm -rf "$WORK/inst a/new bin"
reset_docker
out=$(rr); status=$?
# c. The install directory copied in its own place, as a move to a new disk does.
cp -pR "$IA" "$WORK/inst a/backup"
mv "$IA" "$IA.old"
cp -pR "$IA.old" "$IA"
rm -rf "$IA.old"
said=$(cd "$WORK/proj a" && PATH="$BARE" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LA" version 2>&1 </dev/null)
case $said in "knossos: the install directory $IA is not the one the installer made this launcher for (it was replaced, copied or restored); run the installer again with --install-dir=$IA to repair it: your roots and enrichers are kept.") said=yes ;; *) said=no ;; esac
reset_docker
out=$(rr); status=$?
if [ "$said" = yes ] && [ "$status" -eq 0 ] && answers "$LA" && kept && [ "$(baked_pin "$LA")" = "$(pin_of "$IA")" ]; then
    pass 'a copied install directory is refused with advice that works: the installer run with that --install-dir re-pins it and keeps everything'
else
    fail "copied install dir: said $said, $status, $out"
fi
# d. Restored from a backup.
rm -rf "$IA"
cp -pR "$WORK/inst a/backup" "$IA"
reset_docker
out=$(rr); status=$?
if [ "$status" -eq 0 ] && answers "$LA" && kept; then pass 'a restored install directory is repaired the same way'; else fail "restored install dir: $status, $out"; fi
rm -rf "$WORK/inst a/backup"

# ---- where the install directory may be ---------------------------------------------------

inst_refused() {
    what=$1 inst=$2 text=$3
    shift 3
    reset_docker
    out=$(cid "${FROM:-$WORK/elsewhere}" "${DATA_FOR:-$WORK/ir/data}" "$WORK/ir/bin" --install-dir="$inst" --no-plugin --no-roots "$@"); status=$?
    case $out in *"$text"*) said=yes ;; *) said=no ;; esac
    case $out in *'--install-dir'*) ;; *) said=no ;; esac
    if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ "$(count_calls pull)" -eq 0 ] && [ ! -e "$WORK/ir/bin" ] && [ ! -e "$WORK/ir/data" ]; then
        pass "an install directory $what is refused, saying how to fix it, and nothing is created"
    else
        fail "install dir $what: $status, $out"
    fi
}
inst_refused 'at the filesystem root' / 'the install directory / is the filesystem root'
inst_refused 'that is the home directory' "$HOME" "the install directory $HOME is your home directory or contains it"
inst_refused 'above the home directory' "$WORK" "the install directory $WORK is your home directory or contains it"
inst_refused 'that is the bin directory' "$WORK/ir/bin" "the install directory $WORK/ir/bin is or contains the bin directory $WORK/ir/bin"
inst_refused 'around the bin directory' "$WORK/ir" "the install directory $WORK/ir is or contains the bin directory"
inst_refused 'inside the data directory' "$WORK/ir/data/install" "the install directory $WORK/ir/data/install lies in the data directory $WORK/ir/data, which every container can write"
DATA_FOR="$WORK/ir/inst/data" inst_refused 'around the data directory' "$WORK/ir/inst" "the data directory $WORK/ir/inst/data lies in the install directory $WORK/ir/inst; choose a --data-dir outside it, or another --install-dir"
mkdir -p "$WORK/ir proj"
FROM="$WORK/ir proj" inst_refused 'inside the directory the installer runs from' "$WORK/ir proj/.knossos" "$WORK/ir proj contains the Knossos install directory $WORK/ir proj/.knossos, which holds what the host trusts and must never be part of a project; re-run the installer with --install-dir outside the project"
mkdir -p "$WORK/ir graph"
: > "$WORK/ir graph/knossos.sqlite-wal"
inst_refused 'below a directory holding a graph' "$WORK/ir graph/deep/install" "lies in $WORK/ir graph, which holds a Knossos graph (knossos.sqlite-wal)"
# Inside a graph directory the installation itself recorded (a reinstall).
mkdir -p "$WORK/ir rec/graph/inst/containers"
printf '{"mode": "container"}\n' > "$WORK/ir rec/graph/inst/install.json"
printf '%s\n' "$WORK/ir rec/graph" > "$WORK/ir rec/graph/inst/containers/graph-0123456789ab"
inst_refused 'inside a graph it recorded' "$WORK/ir rec/graph/inst" "lies in $WORK/ir rec/graph, a graph directory this installation used"
mkdir -p "$WORK/ir odd"
printf 'mine\n' > "$WORK/ir odd/notes.txt"
inst_refused 'that holds a file of yours' "$WORK/ir odd" "the install directory $WORK/ir odd holds notes.txt, which is not something Knossos keeps there"
mkdir -p "$WORK/ir native"
printf '{"mode": "native"}\n' > "$WORK/ir native/install.json"
inst_refused "that holds a native install's record" "$WORK/ir native" "holds an install.json that records no container install"
mkfifo "$WORK/ir fifo"
inst_refused 'that is not a directory' "$WORK/ir fifo" "the install directory $WORK/ir fifo exists and is not a directory"
mkdir -p "$WORK/ir fifo record"
mkfifo "$WORK/ir fifo record/install.json"
inst_refused 'whose install.json is a FIFO, without hanging' "$WORK/ir fifo record" "holds an install.json that is not a plain file"
rm -rf "$WORK/ir proj" "$WORK/ir graph" "$WORK/ir rec" "$WORK/ir odd" "$WORK/ir native" "$WORK/ir fifo" "$WORK/ir fifo record" "$WORK/ir"

# The default place, and what moves it: XDG_DATA_HOME when it is absolute,
# KNOSSOS_INSTALL_DIR and --install-dir, at install time.
place() {
    reset_docker
    (cd "$WORK/elsewhere" && PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 "$@" \
        sh "$INSTALL_SH" --mode=container --data-dir="$WORK/place/data" --bin-dir="$WORK/place/bin" --version=1.0.0 --no-plugin --no-roots 2>&1 </dev/null)
}
placed_at() {
    if grep -qxF "INSTALL_DIR='$2'" "$WORK/place/bin/knossos" 2>/dev/null && [ -f "$2/install.json" ]; then pass "$1"; else fail "$1: $(grep '^INSTALL_DIR=' "$WORK/place/bin/knossos" 2>&1)"; fi
    rm -rf "$WORK/place" "$2"
}
place env -u XDG_DATA_HOME -u KNOSSOS_INSTALL_DIR >/dev/null
placed_at 'with XDG_DATA_HOME unset, the install directory is ~/.local/share/knossos' "$HOME/.local/share/knossos"
place env -u KNOSSOS_INSTALL_DIR XDG_DATA_HOME="$WORK/xdg" >/dev/null
placed_at 'with XDG_DATA_HOME set, the install directory is XDG_DATA_HOME/knossos' "$WORK/xdg/knossos"
place env -u KNOSSOS_INSTALL_DIR XDG_DATA_HOME=relative/xdg >/dev/null
placed_at 'a relative XDG_DATA_HOME is ignored, as the XDG rules say' "$HOME/.local/share/knossos"
if [ ! -e "$WORK/elsewhere/relative" ]; then pass 'and nothing is made at the relative path'; else fail 'a relative XDG_DATA_HOME was used'; fi
place env XDG_DATA_HOME="$WORK/xdg" KNOSSOS_INSTALL_DIR="$WORK/from env" >/dev/null
placed_at 'KNOSSOS_INSTALL_DIR at install time wins over XDG_DATA_HOME' "$WORK/from env"
rm -rf "$WORK/xdg"
# Native mode keeps its release in the data directory: --install-dir is refused.
reset_docker
out=$(detect "$STUB:$BARE" "$WORK/native install dir" --mode=native --install-dir="$WORK/native install dir/inst"); status=$?
case $out in *'--install-dir is for container mode; a native install keeps its release in the data directory (--data-dir)'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/native install dir" ]; then pass 'native mode refuses --install-dir'; else fail "native --install-dir: $status, $out"; fi
# Native mode only reads where a container install could be: a control
# character in KNOSSOS_INSTALL_DIR or XDG_DATA_HOME changes nothing for it.
NL='
'
reset_docker
out=$(cd "$WORK/elsewhere" && PATH="$STUB:$BARE" KNOSSOS_RELEASE_BASE="$WORK/no releases" KNOSSOS_INSTALL_DIR="$WORK/odd${NL}dir" XDG_DATA_HOME="/x${NL}y" \
    sh "$INSTALL_SH" --mode=native --data-dir="$WORK/native ctl/data" --bin-dir="$WORK/native ctl/bin" --version=1.0.0 --no-plugin --no-roots --dry-run 2>&1 </dev/null); status=$?
case $out in *'mode:       native'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'native mode ignores a control character in KNOSSOS_INSTALL_DIR and XDG_DATA_HOME'; else fail "native with an odd install dir: $status, $out"; fi
out=$(cd "$WORK/elsewhere" && PATH="$BARE" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 KNOSSOS_INSTALL_DIR="$WORK/odd${NL}dir" \
    sh "$INSTALL_SH" --mode=container --data-dir="$WORK/ctl/data" --bin-dir="$WORK/ctl/bin" --version=1.0.0 --no-plugin --no-roots 2>&1 </dev/null); status=$?
case $out in *'the install directory must not contain a newline or another control character'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/ctl" ]; then pass 'container mode refuses an install directory with a control character'; else fail "container odd install dir: $status, $out"; fi
# Native mode into the graph directory of a container install that the
# install directory records: its containers could plant the release it runs.
reset_docker
out=$(cd "$WORK/elsewhere" && PATH="$STUB:$BARE" KNOSSOS_RELEASE_BASE="$WORK/no releases" KNOSSOS_INSTALL_DIR="$IA" \
    sh "$INSTALL_SH" --mode=native --data-dir="$GA" --bin-dir="$WORK/native bin" --version=1.0.0 --no-plugin --no-roots --dry-run 2>&1 </dev/null); status=$?
case $out in *"$GA is the graph directory of the container install in $IA, whose containers can write it, so a native install there would run what they could plant"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/native bin" ]; then pass "a native install into a container install's graph directory is refused"; else fail "native into a container graph: $status, $out"; fi
rm -rf "${WORK:?}/inst a" "${WORK:?}/inst b" "${WORK:?}/proj a"

# A fresh install that fails after it made the install directory removes it,
# so nothing is left that the next run would have to look at.
reset_docker
out=$(FAKE_PLUGIN_FAILS=1 ci "$WORK/failed fresh" --version=1.0.0 --no-roots); status=$?
if [ "$status" -ne 0 ] && [ ! -e "$WORK/failed fresh/install dir" ]; then pass 'a fresh install that fails leaves no install directory behind'; else fail "failed fresh install: $status, $(ls -A "$WORK/failed fresh" 2>&1), $out"; fi
reset_docker
out=$(ci "$WORK/failed fresh" --version=1.0.0 --no-plugin --no-roots); status=$?
if [ "$status" -eq 0 ]; then pass 'and the next run installs'; else fail "after a failed fresh install: $status, $out"; fi
rm -rf "${WORK:?}/failed fresh"

# ---- the runtime's user arguments: --userns=keep-id on every Podman run, none with Docker ----

# Rootless Podman maps the caller's ids into a container only with
# --userns=keep-id; without it the plugin step cannot write the plugin.
PODMAN_BARE="$WORK/podman path"
mkdir -p "$PODMAN_BARE"
for tool in "$BARE"/*; do
    [ "${tool##*/}" != docker ] || continue
    if [ -L "$tool" ]; then ln -s "$(readlink "$tool")" "$PODMAN_BARE/${tool##*/}"; else ln -s "$tool" "$PODMAN_BARE/${tool##*/}"; fi
done
cp "$HERE/fixtures/fake-docker" "$PODMAN_BARE/podman"
chmod +x "$PODMAN_BARE/podman"
# every_run_has RUNTIME-PATH WANT: install with the plugin, update, roll back,
# and change both settings through the launcher, then compare every run.
every_run_has() {
    path=$1 want=$2
    reset_docker
    UR="$WORK/userns $want"
    mkdir -p "$UR/proj"
    : > "$REL/knossos-1.4.0-linux-x86_64.tar.gz"
    (cd "$WORK/elsewhere" && PATH="$path" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.0.0 \
        sh "$INSTALL_SH" --mode=container --data-dir="$UR/data" --install-dir="$UR/install" --bin-dir="$UR/bin" --version=1.0.0 --no-roots </dev/null >/dev/null 2>&1)
    for step in "self-update --version=1.4.0" "self-update --rollback" "enricher add tool -- tool" "autostart $UR/proj --set=on" "version"; do
        # shellcheck disable=SC2086 # the step is words on purpose
        (cd "$UR/proj" && PATH="$path" KNOSSOS_RELEASE_BASE="$REL" KNOSSOS_IMAGE_REPOSITORY=$REPO FAKE_IMAGE_VERSION=1.4.0 \
            env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$UR/bin/knossos" $step </dev/null >/dev/null 2>&1)
    done
    runs=$(grep -c '^call \[run\]' "$FAKE_DOCKER_STATE/calls")
    with=$(grep '^call \[run\]' "$FAKE_DOCKER_STATE/calls" | grep -c '\[--userns=keep-id\]')
    plugin=$(grep '^call \[run\]' "$FAKE_DOCKER_STATE/calls" | grep -c 'install-agent-plugin')
    rm -rf "$UR"
}
every_run_has "$PODMAN_BARE" podman
if [ "$runs" -ge 10 ] && [ "$with" -eq "$runs" ] && [ "$plugin" -ge 2 ]; then
    pass "with Podman every run of the image carries --userns=keep-id, the plugin step and the image checks included ($runs runs)"
else
    fail "podman runs: $with of $runs carry --userns=keep-id ($plugin plugin steps): $(grep '^call \[run\]' "$FAKE_DOCKER_STATE/calls" | grep -v 'keep-id' | head -n 3)"
fi
every_run_has "$BARE" docker
if [ "$runs" -ge 10 ] && [ "$with" -eq 0 ] && [ "$plugin" -ge 2 ]; then pass "with Docker no run carries --userns=keep-id ($runs runs)"; else fail "docker runs: $with of $runs carry --userns=keep-id"; fi

# A lookup that never returns, cut off as the session hook's timeout cuts it
# off: the attempt is already recorded, so the next session does not retry.
SLOW="$WORK/slow curl"
mkdir -p "$SLOW"
printf '#!/bin/sh
exec sleep 30
' > "$SLOW/curl"
chmod +x "$SLOW/curl"
touch -t 202001010000 "$INST2/update-check.json"
: > "$WORK/before-check"
(cd "$PROJECT" && PATH="$SLOW:$BARE" KNOSSOS_RELEASE_BASE=https://mirror.example/releases KNOSSOS_DATA_DIR="$GRAPH" \
    KNOSSOS_ROOTS_FILE="$GRAPH/roots.json" exec "$LAUNCHER2" self-update --check --quiet </dev/null >/dev/null 2>&1) &
checker=$!
sleep 3
kill -TERM "$checker" 2>/dev/null
wait "$checker" 2>/dev/null
if [ -n "$(find "$INST2/update-check.json" -newer "$WORK/before-check" 2>/dev/null)" ]; then
    pass 'a lookup that never returns still leaves a recorded attempt'
else
    fail "cut-off lookup: $(cat "$INST2/update-check.json")"
fi
if grep -q -- '--max-time 12' "$INST2/container-update" && grep -q -- '--connect-timeout 5' "$INST2/container-update"; then
    pass "the lookup's own limit is shorter than the session hook's 20 s"
else
    fail 'the lookup has no limit under the hook timeout'
fi

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi

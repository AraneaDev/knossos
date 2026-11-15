#!/bin/sh
# The installed launcher in container mode, against a fake docker that records
# its argument lists (tests/shell/fixtures/fake-docker): no container runtime,
# no daemon and no network is needed. It checks the exact `run` and `exec`
# argument lists for paths with a space, one container per project root that
# the second call reuses, the race for the name, the marker, the daemon-down
# message, `stop`, exit status, stdin and signals; and idle-stop, the
# container's entrypoint, run here directly.
#
# Every call states KNOSSOS_DATA_DIR and KNOSSOS_ROOTS_FILE or unsets them, so
# whatever the caller exported (the quality image exports /data) changes
# nothing.
# shellcheck disable=SC2016 # the sh -c programs below expand their own arguments
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
TEMPLATE="${KNOSSOS_TEST_LAUNCHER:-$HERE/../../tools/launcher/knossos}"
IDLE_STOP="$HERE/../../tools/launcher/idle-stop"
failures=0
WORK=$(mktemp -d)
WORK=$(CDPATH='' cd -P -- "$WORK" && pwd -P)
trap 'rm -rf "$WORK"' EXIT

# A home directory of the test's own: the launcher refuses to mount it or
# anything above it, and the real one must never be in play.
HOME="$WORK/home"
mkdir -p "$HOME"
export HOME

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; failures=$((failures + 1)); }

hash12() {
    if command -v sha256sum >/dev/null 2>&1; then
        printf '%s' "$1" | sha256sum | cut -c 1-12
    else
        printf '%s' "$1" | shasum -a 256 | cut -c 1-12
    fi
}

# A fake docker first on PATH, with its state under WORK.
FAKE_BIN="$WORK/fake bin"
mkdir -p "$FAKE_BIN"
cp "$HERE/fixtures/fake-docker" "$FAKE_BIN/docker"
chmod +x "$FAKE_BIN/docker"
PATH="$FAKE_BIN:$PATH"
FAKE_DOCKER_STATE="$WORK/docker state"
FAKE_ANY_IMAGE=1
export FAKE_DOCKER_STATE FAKE_ANY_IMAGE
reset_docker() { rm -rf "$FAKE_DOCKER_STATE"; mkdir -p "$FAKE_DOCKER_STATE"; }
calls() { cat "$FAKE_DOCKER_STATE/calls" 2>/dev/null; }
NL='
'
has_call() { grep -qxF "$1" "$FAKE_DOCKER_STATE/calls" 2>/dev/null; }
count_calls() { if [ -f "$FAKE_DOCKER_STATE/calls" ]; then grep -c "^call \\[$1\\]" "$FAKE_DOCKER_STATE/calls"; else echo 0; fi; }

IMAGE="ghcr.io/araneadev/knossos@sha256:1111111111111111111111111111111111111111111111111111111111111111"
INSTALL="$WORK/install dir"
mkdir -p "$INSTALL"
write_record() {
    printf '{\n  "mode": "container",\n  "version": "1.0.0",\n  "image": "%s",\n  "runtime": "%s",\n  "bin_dir": "%s",\n  "installed_at": "2026-01-01T00:00:00Z"\n}\n' \
        "$1" "${2:-docker}" "$WORK/bin" > "$INSTALL/install.json"
}
write_record "$IMAGE"
LAUNCHER="$WORK/knossos"
sed "s|__KNOSSOS_INSTALL_DIR__|$INSTALL|" "$TEMPLATE" > "$LAUNCHER"
chmod +x "$LAUNCHER"

PROJECT="$WORK/my project"
mkdir -p "$PROJECT/src/deep"
printf '{\n    "roots": [\n        "%s"\n    ]\n}\n' "$PROJECT" > "$INSTALL/roots.json"

# kn ARGS: the launcher with the caller's data and roots settings removed, from the current directory.
kn() { env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE -u KNOSSOS_NO_UPDATE_CHECK "$LAUNCHER" "$@"; }

UID_GID="$(id -u):$(id -g)"
NAME="knossos-$(hash12 "$INSTALL
$PROJECT
$INSTALL
$INSTALL/roots.json
$IMAGE")"

# ---- the first call starts the container, the call runs in it ---------------

reset_docker
out=$(cd "$PROJECT/src/deep" && kn version --json </dev/null 2>&1); status=$?
expected_run="call [run] [-d] [--rm] [--init] [--no-healthcheck] [--pull=never] [--name] [$NAME] [--label] [io.knossos.install=$(hash12 "$INSTALL")] [--label] [io.knossos.root=$(hash12 "$PROJECT")] [--network] [none] [--user] [$UID_GID] [-e] [HOME=/tmp] [-e] [KNOSSOS_CONTAINER=0] [-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$INSTALL] [-e] [KNOSSOS_DATA_DIR=$INSTALL] [-e] [KNOSSOS_ROOTS_FILE=$INSTALL/roots.json] [--mount] [type=bind,source=$PROJECT,target=$PROJECT] [--mount] [type=bind,source=$INSTALL,target=$INSTALL] [--mount] [type=bind,source=$INSTALL/install.json,target=$INSTALL/install.json,readonly] [--entrypoint] [/opt/knossos/tools/launcher/idle-stop] [$IMAGE] [$INSTALL/containers/$NAME]"
if [ "$status" -eq 0 ] && grep -qxF "$expected_run" "$FAKE_DOCKER_STATE/calls"; then
    pass 'the first call runs the container with same-path mounts, the user, HOME=/tmp, no network and idle-stop'
else
    fail "run line (status $status, output $out): $(calls)"
fi
exec_line=$(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls")
case $exec_line in
    "call [exec] [-i] [-w] [$PROJECT/src/deep] [-e] [HOME=/tmp] [-e] [KNOSSOS_CONTAINER=0] [-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$INSTALL] [-e] [KNOSSOS_DATA_DIR=$INSTALL] [-e] [KNOSSOS_ROOTS_FILE=$INSTALL/roots.json] "*"[$NAME] [/opt/knossos/tools/launcher/idle-stop] [--run] ["*"] [/opt/knossos/bin/knossos] [version] [--json]")
        pass 'the call is an exec -i in the working directory, without -t when there is no terminal'
        ;;
    *) fail "exec line: $exec_line" ;;
esac
case $exec_line in *'[-t]'*) fail 'exec got -t without a terminal' ;; esac
case $out in *'[/opt/knossos/bin/knossos][version][--json]'*) pass 'the command and its arguments reach the container' ;; *) fail "output: $out" ;; esac
if [ "$(count_calls run)" -eq 1 ] && [ "$(count_calls container)" -eq 1 ]; then pass 'one inspect and one run on a cold start'; else fail "cold start calls: $(calls)"; fi
if [ -f "$INSTALL/containers/$NAME" ]; then pass 'the marker is written under <install dir>/containers'; else fail 'no marker'; fi

# ---- the second call reuses it, and touches the marker ----------------------

touch -t 202001010000 "$INSTALL/containers/$NAME"
: > "$FAKE_DOCKER_STATE/calls"
out=$(cd "$PROJECT" && kn find-component 'App\Api::scan' </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$(count_calls run)" -eq 0 ] && [ "$(count_calls exec)" -eq 1 ]; then
    pass 'a second call in the same project starts no second container'
else
    fail "second call (status $status, output $out): $(calls)"
fi
if [ -n "$(find "$INSTALL/containers/$NAME" -newer "$INSTALL/install.json" 2>/dev/null)" ]; then pass 'every call touches the marker'; else fail 'the marker was not touched'; fi
case $out in *'[find-component][App\Api::scan]'*) pass 'an argument with a backslash passes unchanged' ;; *) fail "backslash argument: $out" ;; esac

# ---- exit status, stdin, KNOSSOS_* settings -----------------------------------

(cd "$PROJECT" && FAKE_EXEC_EXIT=7 kn version </dev/null >/dev/null 2>&1); status=$?
if [ "$status" -eq 7 ]; then pass 'the exit status passes through'; else fail "exit status was $status, not 7"; fi

out=$(cd "$PROJECT" && printf '{"jsonrpc":"2.0"}\n' | FAKE_EXEC_STDIN=1 kn serve 2>&1)
case $out in *'stdin:{"jsonrpc":"2.0"}'*) pass 'stdin reaches the command (the MCP server reads it)' ;; *) fail "stdin: $out" ;; esac

: > "$FAKE_DOCKER_STATE/calls"
(cd "$PROJECT" && KNOSSOS_NO_UPDATE_CHECK=1 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" version </dev/null >/dev/null 2>&1)
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls") in
    *'[-e] [KNOSSOS_NO_UPDATE_CHECK]'*) pass "a caller's other KNOSSOS_* settings reach the command by name" ;;
    *) fail "passthrough: $(calls)" ;;
esac

# ---- a path argument decides the project --------------------------------------

OTHER="$WORK/other proj"
mkdir -p "$OTHER"
: > "$FAKE_DOCKER_STATE/calls"
out=$(cd "$WORK" && kn scan "other proj" </dev/null 2>&1); status=$?
OTHER_NAME="knossos-$(hash12 "$INSTALL
$OTHER
$INSTALL
$INSTALL/roots.json
$IMAGE")"
if [ "$status" -eq 0 ] && grep -qF "[--name] [$OTHER_NAME]" "$FAKE_DOCKER_STATE/calls" \
    && grep -qF "[--mount] [type=bind,source=$OTHER,target=$OTHER]" "$FAKE_DOCKER_STATE/calls"; then
    pass 'scan <path> mounts that path as the project root, in a container of its own'
else
    fail "scan path (status $status, output $out): $(calls)"
fi
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls") in
    *"[-w] [$OTHER] "*"[/opt/knossos/bin/knossos] [scan] [$OTHER]") pass 'a relative path is handed on absolute, and the call works from the root when the working directory is outside it' ;;
    *) fail "scan exec: $(calls)" ;;
esac

# A hook command passes the project as its first argument, from anywhere.
: > "$FAKE_DOCKER_STATE/calls"
(cd / && kn session-brief "$PROJECT/src" </dev/null >/dev/null 2>&1)
if grep -qF "[$NAME]" "$FAKE_DOCKER_STATE/calls" && [ "$(count_calls run)" -eq 0 ]; then
    pass 'a hook command naming a directory inside a listed root uses that root'
else
    fail "hook command: $(calls)"
fi

# Not listed and no path: the working directory is the root.
LOOSE="$WORK/loose dir"
mkdir -p "$LOOSE"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$LOOSE" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "[--mount] [type=bind,source=$LOOSE,target=$LOOSE]" "$FAKE_DOCKER_STATE/calls"; then pass 'outside every listed root the working directory is the root'; else fail "loose dir: $(calls)"; fi

# The filesystem root is refused rather than mounted.
out=$(cd / && kn version </dev/null 2>&1); status=$?
case $out in *'the project root / is the filesystem root, which container mode does not mount'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ]; then pass 'the filesystem root is never mounted'; else fail "root dir: status $status, $out"; fi

# ---- the roots file in its other shapes ---------------------------------------

NESTED="$PROJECT/src"
printf '["%s","%s"]' "$PROJECT" "$NESTED" > "$INSTALL/roots.json"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$NESTED/deep" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "[--mount] [type=bind,source=$NESTED,target=$NESTED]" "$FAKE_DOCKER_STATE/calls"; then pass 'a bare array works, and the nearest listed ancestor wins'; else fail "nested roots: $(calls)"; fi
QUOTED="$WORK/q\"uo\\te"
mkdir -p "$QUOTED/x"
printf '{"roots":["%s"]}' "$WORK/q\\\"uo\\\\te" > "$INSTALL/roots.json"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$QUOTED/x" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "[--mount] [type=bind,source=$QUOTED,target=$QUOTED]" "$FAKE_DOCKER_STATE/calls"; then pass 'escaped quotes and backslashes in the roots file are read'; else fail "escaped roots: $(calls)"; fi
printf '{\n    "roots": [\n        "%s"\n    ]\n}\n' "$PROJECT" > "$INSTALL/roots.json"

# ---- data dir, roots file and install dir apart ---------------------------------

GRAPH="$WORK/graph dir"
ROOTS="$WORK/roots dir/roots.json"
mkdir -p "$(dirname "$ROOTS")"
cp "$INSTALL/roots.json" "$ROOTS"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$PROJECT" && KNOSSOS_DATA_DIR="$GRAPH" KNOSSOS_ROOTS_FILE="$ROOTS" "$LAUNCHER" version </dev/null >/dev/null 2>&1)
run_line=$(grep '^call \[run\]' "$FAKE_DOCKER_STATE/calls")
case $run_line in
    *"[-e] [KNOSSOS_DATA_DIR=$GRAPH] [-e] [KNOSSOS_ROOTS_FILE=$ROOTS] [--mount] [type=bind,source=$PROJECT,target=$PROJECT] [--mount] [type=bind,source=$GRAPH,target=$GRAPH] [--mount] [type=bind,source=$WORK/roots dir,target=$WORK/roots dir] [--mount] [type=bind,source=$INSTALL,target=$INSTALL] "*)
        pass "a caller's data dir and roots file are mounted beside the project and the install directory, each at its own path"
        ;;
    *) fail "separate dirs: $run_line" ;;
esac
if [ -d "$GRAPH" ]; then pass 'a missing data dir is created on the host before it is mounted'; else fail 'the data dir was not created'; fi
case $run_line in *"[--name] [$NAME]"*) fail 'another data dir reused the container of the default one' ;; *) pass 'another data dir gets a container of its own' ;; esac

# ---- the race for the name -------------------------------------------------------

reset_docker
: > "$FAKE_DOCKER_STATE/race"
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$(count_calls run)" -eq 1 ] && [ "$(count_calls exec)" -eq 1 ]; then
    pass 'a call that loses the race for the name uses the container that won'
else
    fail "race (status $status, output $out): $(calls)"
fi

# Two at once, for real: both end with the command run and one container.
reset_docker
(cd "$PROJECT" && kn version </dev/null > "$WORK/a.out" 2>&1) &
first=$!
(cd "$PROJECT" && kn version </dev/null > "$WORK/b.out" 2>&1) &
second=$!
wait "$first"; s1=$?
wait "$second"; s2=$?
containers=$(find "$FAKE_DOCKER_STATE/containers" -name 'knossos-*' ! -name '*.*' | wc -l | tr -d ' ')
if [ "$s1" -eq 0 ] && [ "$s2" -eq 0 ] && [ "$containers" -eq 1 ] && [ "$(count_calls exec)" -eq 2 ]; then
    pass 'two calls at once both run, in one container'
else
    fail "parallel calls: $s1 $s2, $containers containers, $(cat "$WORK/a.out" "$WORK/b.out")"
fi

# A stopped container left behind is removed and replaced.
printf 'exited\n' > "$FAKE_DOCKER_STATE/containers/$NAME"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qxF "call [rm] [-f] [$NAME]" "$FAKE_DOCKER_STATE/calls" && [ "$(count_calls run)" -eq 1 ]; then
    pass 'a stopped container is removed and a new one started'
else
    fail "stopped container: $(calls)"
fi

# ---- a new image means a new container --------------------------------------------

NEW_IMAGE="ghcr.io/araneadev/knossos@sha256:2222222222222222222222222222222222222222222222222222222222222222"
write_record "$NEW_IMAGE"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "[$NEW_IMAGE] [" "$FAKE_DOCKER_STATE/calls" && ! grep -qF "[--name] [$NAME]" "$FAKE_DOCKER_STATE/calls"; then
    pass 'after the image changes, the next call starts a container of the new image instead of reusing the old one'
else
    fail "new image: $(calls)"
fi
write_record "$IMAGE"

# Another installation on the same machine never shares a container.
INSTALL2="$WORK/second install"
mkdir -p "$INSTALL2"
cp "$INSTALL/install.json" "$INSTALL2/install.json"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$PROJECT" && KNOSSOS_INSTALL_DIR="$INSTALL2" kn version </dev/null >/dev/null 2>&1)
if grep -qF "io.knossos.install=$(hash12 "$INSTALL2")" "$FAKE_DOCKER_STATE/calls" && ! grep -qF "[--name] [$NAME]" "$FAKE_DOCKER_STATE/calls"; then
    pass 'a second installation gets its own container for the same project'
else
    fail "second installation: $(calls)"
fi

# ---- podman ----------------------------------------------------------------------

cp "$HERE/fixtures/fake-docker" "$FAKE_BIN/podman"
write_record "$IMAGE" podman
reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
if grep -q '^call \[run\].*\[--userns=keep-id\]' "$FAKE_DOCKER_STATE/calls"; then pass 'podman, as install.json records it, runs with --userns=keep-id'; else fail "podman: $(calls)"; fi
write_record "$IMAGE"

# ---- signals ------------------------------------------------------------------------

reset_docker
(cd "$PROJECT" && FAKE_EXEC_WAIT=1 exec env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" watch "$PROJECT" </dev/null > "$WORK/watch.out" 2>&1) &
watcher=$!
tries=0
while [ ! -f "$FAKE_DOCKER_STATE/waiting" ] && [ "$tries" -lt 50 ]; do sleep 1; tries=$((tries + 1)); done
kill -TERM "$watcher" 2>/dev/null
wait "$watcher"; status=$?
call_id=$(sed -n 's/^call \[exec\].*\[--run\] \[\([^]]*\)\].*/\1/p' "$FAKE_DOCKER_STATE/calls" | head -n 1)
if grep -qxF "call [exec] [$NAME] [/opt/knossos/tools/launcher/idle-stop] [--signal] [TERM] [$call_id]" "$FAKE_DOCKER_STATE/calls" && [ "$status" -eq 143 ]; then
    pass 'a SIGTERM to the launcher reaches the command in the container, and its status comes back'
else
    fail "signal (status $status, id $call_id): $(calls)"
fi

# ---- the daemon is down ------------------------------------------------------------

reset_docker
: > "$FAKE_DOCKER_STATE/daemon-down"
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
lines=$(printf '%s\n' "$out" | wc -l | tr -d ' ')
case $out in *'docker daemon is not running'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$lines" -eq 1 ]; then pass 'a stopped daemon is one line saying so, exit 1'; else fail "daemon down (status $status): $out"; fi
rm -f "$FAKE_DOCKER_STATE/daemon-down"

# ---- stop ---------------------------------------------------------------------------

reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
(cd "$OTHER" && kn version </dev/null >/dev/null 2>&1)
out=$(cd "$PROJECT/src" && kn stop 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qxF "call [stop] [$NAME]" "$FAKE_DOCKER_STATE/calls" && [ "$(count_calls stop)" -eq 1 ]; then
    pass 'stop stops the container of this project only'
else
    fail "stop (status $status, $out): $(calls)"
fi
case $out in *'Stopped 1 Knossos container(s) for '"$PROJECT"*) pass 'stop says what it stopped' ;; *) fail "stop said: $out" ;; esac
out=$(cd "$PROJECT" && kn stop 2>&1)
case $out in *'No Knossos container is running'*) pass 'stop with nothing running says so' ;; *) fail "second stop said: $out" ;; esac
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
out=$(cd "$WORK" && kn stop --all 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$(count_calls stop)" -eq 3 ]; then pass 'stop --all stops every container of this installation'; else fail "stop --all (status $status, $out): $(calls)"; fi
out=$(kn stop --bogus 2>&1); status=$?
if [ "$status" -eq 1 ]; then pass 'stop refuses an unknown option'; else fail "stop --bogus: $status $out"; fi

# ---- paths that look like patterns ----------------------------------------------

# mounts_under DIR: the bind sources of the last run under DIR, one per line.
mounts_under() {
    grep '^call \[run\] \[-d\]' "$FAKE_DOCKER_STATE/calls" | tail -n 1 | awk '{ gsub(/\] \[/, "\n"); print }' \
        | sed -n 's|^type=bind,source=\(.*\),target=.*$|\1|p' \
        | while IFS= read -r source; do case $source in "$1"/*) printf '%s\n' "$source" ;; esac; done
}
G="$WORK/g"
TAB=$(printf '\t')
for name in 'a?' 's*' '[x]' 'sp ace' "ta${TAB}b" '-dash'; do mkdir -p "$G/$name"; done
for name in ab ac sx x; do mkdir -p "$G/$name"; done
printf '{"roots": []}\n' > "$INSTALL/roots.json"
all_one=yes
for name in 'a?' 's*' '[x]' 'sp ace' "ta${TAB}b" '-dash'; do
    reset_docker
    (cd "$G/$name" && kn version </dev/null >/dev/null 2>&1); status=$?
    got=$(mounts_under "$G")
    if [ "$status" -ne 0 ] || [ "$got" != "$G/$name" ]; then
        all_one=no
        printf '  %s: status %s, mounts under g: %s\n' "$name" "$status" "$(printf '%s' "$got" | tr '\n' '|')"
    fi
done
if [ "$all_one" = yes ]; then pass 'a project path with ?, *, [x], a space, a tab or a leading dash mounts exactly that directory'; else fail 'a project path that looks like a pattern mounted something else'; fi

# A roots entry that is a pattern, relative, or not a directory never widens the root.
mkdir -p "$WORK/q/proj"
printf '{"roots": ["%s", "/home/*", "q", "%s/missing"]}\n' "$WORK/q*" "$WORK" > "$INSTALL/roots.json"
reset_docker
(cd "$WORK/q/proj" && kn version </dev/null >/dev/null 2>&1)
got=$(mounts_under "$WORK")
case $got in
    "$WORK/q/proj$NL$INSTALL" | "$WORK/q/proj") ok_q=yes ;;
    *) ok_q=no ;;
esac
if [ "$(printf '%s\n' "$got" | grep -c "^$WORK/q\$")" -eq 0 ] && printf '%s\n' "$got" | grep -qxF "$WORK/q/proj"; then
    pass 'a roots entry holding a pattern, a relative path or a missing directory never becomes a mount'
else
    fail "pattern roots ($ok_q): $(printf '%s' "$got" | tr '\n' '|')"
fi
printf '{\n    "roots": [\n        "%s"\n    ]\n}\n' "$PROJECT" > "$INSTALL/roots.json"

# ---- directories too wide to mount ------------------------------------------------

# refused DESCRIPTION EXPECTED-TEXT ARGS...: a call that must be one line, exit 1, and no container started.
refused() {
    what=$1
    text=$2
    shift 2
    reset_docker
    out=$("$@" 2>&1); status=$?
    lines=$(printf '%s\n' "$out" | wc -l | tr -d ' ')
    case $out in *"$text"*) said=yes ;; *) said=no ;; esac
    if [ "$status" -eq 1 ] && [ "$lines" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ]; then
        pass "$what is refused, and no container is started"
    else
        fail "$what: status $status, $out, $(calls)"
    fi
}
refused 'a roots file in the home directory' 'put the roots file in a directory of its own and point KNOSSOS_ROOTS_FILE' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE="$HOME/roots.json" "$3" version </dev/null' _ "$PROJECT" "$INSTALL" "$LAUNCHER"
refused 'a roots file at the filesystem root' 'is the filesystem root' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE=/roots.json "$3" version </dev/null' _ "$PROJECT" "$INSTALL" "$LAUNCHER"
refused 'KNOSSOS_DATA_DIR=/' 'point KNOSSOS_DATA_DIR at a directory of its own' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR=/ KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$INSTALL" "$LAUNCHER"
refused 'KNOSSOS_DATA_DIR set to the home directory' 'is your home directory' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$HOME" KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$INSTALL" "$LAUNCHER"
refused 'a data dir above the home directory' 'contains your home directory' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE="$3/roots.json" "$4" version </dev/null' _ "$PROJECT" "$WORK" "$INSTALL" "$LAUNCHER"
refused 'the home directory as the project root' 'the project root' \
    sh -c 'cd "$HOME" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$1" version </dev/null' _ "$LAUNCHER"
refused 'the filesystem root as the project root' 'the project root / is the filesystem root' \
    sh -c 'cd / && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$1" version </dev/null' _ "$LAUNCHER"
cp "$INSTALL/install.json" "$HOME/install.json"
refused 'an install directory that is the home directory' 're-run the installer with --data-dir' \
    sh -c 'cd "$1" && KNOSSOS_INSTALL_DIR="$HOME" KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$INSTALL" "$LAUNCHER"
rm -f "$HOME/install.json"

# ---- stop and self-update work from anywhere -------------------------------------------

reset_docker
mkdir -p "$FAKE_DOCKER_STATE/copy/opt/knossos/tools/launcher"
cp "$HERE/../../tools/launcher/container-update" "$FAKE_DOCKER_STATE/copy/opt/knossos/tools/launcher/container-update"
REL="$WORK/releases"
mkdir -p "$REL" "$WORK/tmp"
: > "$REL/knossos-1.0.0-linux-x86_64.tar.gz"
out=$(cd / && kn stop --all 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'stop --all works from /'; else fail "stop --all from /: $status, $out"; fi
out=$(cd / && TMPDIR="$WORK/tmp" KNOSSOS_RELEASE_BASE="$REL" kn self-update --check 2>&1 </dev/null); status=$?
if [ "$status" -eq 0 ] && [ "$out" = 'Knossos 1.0.0 is the latest version.' ]; then pass 'self-update works from /'; else fail "self-update from /: $status, $out"; fi
if has_call "call [run] [--rm] [--pull=never] [--network] [none] [--entrypoint] [cat] [$IMAGE] [/opt/knossos/tools/launcher/container-update]"; then
    pass 'the update helper is read out of the installed image without network and without a pull'
else
    fail "helper read: $(calls)"
fi
if [ -z "$(find "$WORK/tmp" -name 'knossos-update.*')" ]; then pass "the launcher's copy of the helper is gone once it has run"; else fail "helper copy left: $(ls "$WORK/tmp")"; fi

# The background check: a fresh answer costs nothing, one from the future is due.
: > "$FAKE_DOCKER_STATE/calls"
(cd "$PROJECT" && TMPDIR="$WORK/tmp" KNOSSOS_RELEASE_BASE="$REL" kn self-update --check --quiet </dev/null >/dev/null 2>&1)
if [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then pass 'a fresh cached answer starts no container'; else fail "fresh check: $(calls)"; fi
touch -t 203001010000 "$INSTALL/update-check.json"
(cd "$PROJECT" && TMPDIR="$WORK/tmp" KNOSSOS_RELEASE_BASE="$REL" kn self-update --check --quiet </dev/null >/dev/null 2>&1)
if [ "$(count_calls run)" -eq 1 ]; then
    pass 'a cached answer stamped in the future is due, as UpdateCheck::due has it'
else
    fail "future stamp: $(calls)"
fi
rm -f "$INSTALL/update-check.json"

# ---- only commands that take a path get it rewritten -------------------------------------

mkdir -p "$PROJECT/Foo"
reset_docker
(cd "$PROJECT" && kn callers Foo </dev/null >/dev/null 2>&1)
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls") in
    *'[/opt/knossos/bin/knossos] [callers] [Foo]') pass 'a name that happens to be a directory is passed on as given' ;;
    *) fail "callers Foo: $(calls)" ;;
esac
: > "$FAKE_DOCKER_STATE/calls"
(cd "$PROJECT" && kn find-component Foo App </dev/null >/dev/null 2>&1)
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls") in
    *"[/opt/knossos/bin/knossos] [find-component] [$PROJECT/Foo] [App]") pass 'a command that takes a project path gets it absolute' ;;
    *) fail "find-component path: $(calls)" ;;
esac

# ---- a pruned image, the plugin read-only, a terminal -------------------------------------

reset_docker
out=$(cd "$PROJECT" && env -u FAKE_ANY_IMAGE -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" version 2>&1 </dev/null); status=$?
case $out in *"the image $IMAGE is gone from this host: run knossos self-update to fetch it again, or re-run the installer."*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls pull)" -eq 0 ]; then pass 'an image gone from the host is reported, never pulled'; else fail "gone image: $status, $out"; fi

mkdir -p "$INSTALL/plugin/hooks"
: > "$INSTALL/container-update"
reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "[--mount] [type=bind,source=$INSTALL/plugin,target=$INSTALL/plugin,readonly]" "$FAKE_DOCKER_STATE/calls" \
    && grep -qF "[--mount] [type=bind,source=$INSTALL/container-update,target=$INSTALL/container-update,readonly]" "$FAKE_DOCKER_STATE/calls"; then
    pass "the installation's plugin and update helper are mounted read-only into the container"
else
    fail "plugin read-only: $(calls)"
fi
rm -rf "$INSTALL/plugin" "$INSTALL/container-update"

# The terminal decision, without a terminal: KNOSSOS_TEST_TERMINALS stands in
# for "is stdin a terminal, is stdout a terminal", and the flags are read off
# the exec line.
# terminal_flags STDIN,STDOUT COMMAND: whether that call got -t and TERM.
terminal_flags() {
    : > "$FAKE_DOCKER_STATE/calls"
    (cd "$PROJECT" && KNOSSOS_TEST_TERMINALS=$1 TERM=xterm-test kn "$2" </dev/null >/dev/null 2>&1)
    case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1) in
        *' [-t] [-e] [TERM=xterm-test] '*) printf 'tty' ;;
        *'[-t]'*) printf 'tty without TERM' ;;
        *) printf 'none' ;;
    esac
}
terminals_ok=yes
for case in 'yes,yes tui tty' 'yes,no tui none' 'no,yes tui none' 'no,no version none' 'yes,yes serve none'; do
    # shellcheck disable=SC2086 # three words, split on purpose
    set -- $case
    got=$(terminal_flags "$1" "$2")
    if [ "$got" != "$3" ]; then terminals_ok=no; printf '  %s %s: wanted %s, got %s\n' "$1" "$2" "$3" "$got"; fi
done
if [ "$terminals_ok" = yes ]; then
    pass 'a call gets -t and TERM only when stdin and stdout are terminals, and serve never does'
else
    fail 'the terminal flags are chosen wrongly'
fi
if ! grep -q '^call \[exec\].*KNOSSOS_TEST_TERMINALS' "$FAKE_DOCKER_STATE/calls"; then pass 'the test-only terminal switch never reaches the container'; else fail 'KNOSSOS_TEST_TERMINALS was passed into the container'; fi

# ---- the mount rule cannot be got around ---------------------------------------------

# A `..` after a directory that does not exist yet: refused, and nothing created.
refused 'a data dir spelled through a missing directory and ..' "through '..'" \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$HOME/nope/.." KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$INSTALL" "$LAUNCHER"
if [ ! -e "$HOME/nope" ]; then pass 'no directory is created through ..'; else fail "$HOME/nope was created"; fi
refused 'a roots file spelled through a missing directory and ..' "through '..'" \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE="$HOME/nope/../roots.json" "$3" version </dev/null' _ "$PROJECT" "$INSTALL" "$LAUNCHER"
# An existing directory with `..` is judged by where it leads.
mkdir -p "$HOME/sub"
refused 'an existing data dir whose .. leads to the home directory' 'is your home directory' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$HOME/sub/.." KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$INSTALL" "$LAUNCHER"
ln -s "$HOME" "$WORK/to home"
refused 'a data dir that is a symlink to the home directory' 'is your home directory' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2/to home" KNOSSOS_ROOTS_FILE="$3/roots.json" "$4" version </dev/null' _ "$PROJECT" "$WORK" "$INSTALL" "$LAUNCHER"

# HOME has to name the home directory, or the rule cannot be applied.
refused 'HOME unset' 'container mode needs HOME to point at your home directory' \
    sh -c 'cd "$1" && env -u HOME -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" version </dev/null' _ "$PROJECT" "$LAUNCHER"
refused 'HOME empty' 'container mode needs HOME to point at your home directory' \
    sh -c 'cd "$1" && HOME= env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" version </dev/null' _ "$PROJECT" "$LAUNCHER"
refused 'HOME naming no directory' 'container mode needs HOME to point at your home directory' \
    sh -c 'cd "$1" && HOME="$2/no such home" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$3" version </dev/null' _ "$PROJECT" "$WORK" "$LAUNCHER"

# A roots file that lists the home directory (a native install run from there
# grants it) does not block the projects below it: that entry is skipped.
mkdir -p "$HOME/proj/sub" "$HOME/other/deep"
printf '{"roots": ["%s", "%s/proj"]}\n' "$HOME" "$HOME" > "$INSTALL/roots.json"
reset_docker
out=$(cd "$HOME/proj/sub" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qF "[--mount] [type=bind,source=$HOME/proj,target=$HOME/proj]" "$FAKE_DOCKER_STATE/calls"; then
    pass 'with the home directory listed, a narrower listed root below it is used'
else
    fail "home listed, narrower root: $status, $out, $(calls)"
fi
reset_docker
out=$(cd "$HOME/other/deep" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qF "[--mount] [type=bind,source=$HOME/other/deep,target=$HOME/other/deep]" "$FAKE_DOCKER_STATE/calls"; then
    pass 'with the home directory listed and nothing narrower, the working directory is the root'
else
    fail "home listed, working directory: $status, $out, $(calls)"
fi
printf '{\n    "roots": [\n        "%s"\n    ]\n}\n' "$PROJECT" > "$INSTALL/roots.json"

# ---- symlinks: everything the container is given is the physical path -------------------

REAL="$WORK/real"
mkdir -p "$REAL/graph" "$REAL/roots" "$REAL/proj/src" "$REAL/install"
cp "$INSTALL/install.json" "$REAL/install/install.json"
ln -s "$REAL/graph" "$WORK/graph link"
ln -s "$REAL/roots" "$WORK/roots link"
ln -s "$REAL/proj" "$WORK/proj link"
ln -s "$REAL/install" "$WORK/install link"
printf '{"roots": ["%s"]}\n' "$WORK/proj link" > "$REAL/roots/roots.json"
reset_docker
out=$(cd "$WORK/proj link/src" && KNOSSOS_INSTALL_DIR="$WORK/install link" KNOSSOS_DATA_DIR="$WORK/graph link" \
    KNOSSOS_ROOTS_FILE="$WORK/roots link/roots.json" "$LAUNCHER" version </dev/null 2>&1); status=$?
run_line=$(grep '^call \[run\] \[-d\]' "$FAKE_DOCKER_STATE/calls")
exec_line=$(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1)
agree=yes
for wanted in "[-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$REAL/install]" "[-e] [KNOSSOS_DATA_DIR=$REAL/graph]" "[-e] [KNOSSOS_ROOTS_FILE=$REAL/roots/roots.json]" \
    "[--mount] [type=bind,source=$REAL/proj,target=$REAL/proj]" "[--mount] [type=bind,source=$REAL/graph,target=$REAL/graph]" \
    "[--mount] [type=bind,source=$REAL/roots,target=$REAL/roots]" "[--mount] [type=bind,source=$REAL/install,target=$REAL/install]" \
    "[$REAL/install/containers/knossos-"; do
    case $run_line in *"$wanted"*) ;; *) agree=no; printf '  run lacks %s\n' "$wanted" ;; esac
done
for wanted in "[-w] [$REAL/proj/src]" "[-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$REAL/install]" "[-e] [KNOSSOS_DATA_DIR=$REAL/graph]" "[-e] [KNOSSOS_ROOTS_FILE=$REAL/roots/roots.json]"; do
    case $exec_line in *"$wanted"*) ;; *) agree=no; printf '  exec lacks %s\n' "$wanted" ;; esac
done
case "$run_line$exec_line" in *link*) agree=no; printf '  a symlinked spelling reached the container\n' ;; esac
if [ "$status" -eq 0 ] && [ "$agree" = yes ] && [ -n "$(find "$REAL/install/containers" -name 'knossos-*' 2>/dev/null)" ]; then
    pass 'through symlinks, the mounts, the environment, the working directory and the marker all use the physical path'
else
    fail "symlinks: $status, $out"
fi
# The roots entry was spelled through a symlink: inside, only its physical form exists.
case $exec_line in
    *"[-e] [KNOSSOS_ALLOWED_ROOTS=$REAL/proj]"*) pass 'a root listed through a symlink is granted in its physical form inside' ;;
    *) fail "allowed root through a symlink: $exec_line" ;;
esac

# ---- install.json problems -------------------------------------------------------------

printf '{"mode": "container", "runtime": "docker"}\n' > "$INSTALL/install.json"
out=$(cd "$PROJECT" && kn version 2>&1); status=$?
case $out in *'names no image'*) pass 'a record without an image says to re-run the installer' ;; *) fail "no image: $status $out" ;; esac
printf '{"mode": "container", "image": "x", "runtime": "lxc"}\n' > "$INSTALL/install.json"
out=$(cd "$PROJECT" && kn version 2>&1); status=$?
case $out in *"unknown container runtime 'lxc'"*) pass 'an unknown runtime is refused' ;; *) fail "unknown runtime: $status $out" ;; esac
printf '{"mode":"container","image":"%s"}' "$IMAGE" > "$INSTALL/install.json"
reset_docker
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -q '^call \[run\]' "$FAKE_DOCKER_STATE/calls"; then pass 'a compact record without a runtime means docker'; else fail "compact record: $status $out"; fi
write_record "$IMAGE"
# A PATH with the tools the launcher uses but no docker: never the system's
# PATH, which may hold a real docker this test must not reach.
NODOCKER="$WORK/no docker"
mkdir -p "$NODOCKER"
for tool in env sh awk sed cut sha256sum shasum mkdir dirname id date sort; do
    found=$(command -v "$tool" 2>/dev/null || true)
    case $found in /*) ln -s "$found" "$NODOCKER/$tool" ;; esac
done
out=$(cd "$PROJECT" && PATH="$NODOCKER" kn version 2>&1); status=$?
case $out in *'docker is not installed'*) pass 'a missing runtime binary is named' ;; *) fail "missing docker: $status $out" ;; esac

# ---- idle-stop ----------------------------------------------------------------------------

IDLE="$WORK/idle"
mkdir -p "$IDLE/proc empty" "$IDLE/proc busy/1" "$IDLE/proc busy/4242" "$IDLE/run"
: > "$IDLE/marker"
touch -t 202001010000 "$IDLE/marker"
KNOSSOS_IDLE_PROC="$IDLE/proc empty" KNOSSOS_IDLE_RUN_DIR="$IDLE/run" sh "$IDLE_STOP" "$IDLE/marker" 2 1 &
idle=$!
sleep 4
if kill -0 "$idle" 2>/dev/null; then fail 'idle-stop did not exit with an old marker and nothing running'; kill "$idle"; else pass 'idle-stop exits once the marker is older than the limit and nothing runs'; fi
wait "$idle" 2>/dev/null

touch -t 202001010000 "$IDLE/marker"
KNOSSOS_IDLE_PROC="$IDLE/proc busy" KNOSSOS_IDLE_RUN_DIR="$IDLE/run" sh "$IDLE_STOP" "$IDLE/marker" 2 1 &
idle=$!
sleep 4
if kill -0 "$idle" 2>/dev/null; then pass 'idle-stop keeps the container while a command still runs in it'; else fail 'idle-stop exited while a process ran'; fi
started=$(date +%s)
kill -TERM "$idle"
wait "$idle"; status=$?
took=$(($(date +%s) - started))
if [ "$status" -eq 0 ] && [ "$took" -le 2 ]; then pass 'idle-stop ends at once on SIGTERM (docker stop)'; else fail "idle-stop on TERM: status $status after $took s"; fi

: > "$IDLE/marker"
KNOSSOS_IDLE_PROC="$IDLE/proc empty" KNOSSOS_IDLE_RUN_DIR="$IDLE/run" sh "$IDLE_STOP" "$IDLE/marker" 3 1 &
idle=$!
sleep 2
if kill -0 "$idle" 2>/dev/null; then pass 'idle-stop waits while the marker is fresh'; else fail 'idle-stop exited with a fresh marker'; fi
kill -TERM "$idle" 2>/dev/null
wait "$idle" 2>/dev/null

out=$(KNOSSOS_IDLE_RUN_DIR="$IDLE/run" sh "$IDLE_STOP" --run call-1 sh -c 'printf "%s:%s" "$$" "$(cat "$0")"; exit 5' "$IDLE/run/call-1.pid"); status=$?
if [ "$status" -eq 5 ] && [ "${out%%:*}" = "${out#*:}" ]; then pass 'idle-stop --run records the pid of the command it becomes, and passes its status'; else fail "idle-stop --run: $status $out"; fi
# A command that ends with 143 on SIGTERM by itself, so the shell reports no killed job.
sh -c 'trap "exit 143" TERM; sleep 30 & wait' &
sleeper=$!
sleep 1
printf '%s\n' "$sleeper" > "$IDLE/run/call-2.pid"
KNOSSOS_IDLE_RUN_DIR="$IDLE/run" sh "$IDLE_STOP" --signal TERM call-2
wait "$sleeper"; status=$?
if [ "$status" -eq 143 ]; then pass 'idle-stop --signal sends the signal to the recorded command'; else fail "idle-stop --signal: sleeper ended with $status"; fi
out=$(sh "$IDLE_STOP" 2>&1); status=$?
if [ "$status" -eq 2 ]; then pass 'idle-stop without a marker prints its usage'; else fail "idle-stop usage: $status $out"; fi

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi

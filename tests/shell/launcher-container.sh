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
# The layout install.sh makes: the graph in the data dir, the host state in
# <data dir>/install with real plugin/ and containers/ directories.
DATA="$WORK/data dir"
INSTALL="$DATA/install"
mkdir -p "$INSTALL/plugin" "$INSTALL/containers"
write_record() {
    printf '{\n  "mode": "container",\n  "layout": "separate",\n  "version": "1.0.0",\n  "image": "%s",\n  "runtime": "%s",\n  "bin_dir": "%s",\n  "installed_at": "2026-01-01T00:00:00Z"\n}\n' \
        "$1" "${2:-docker}" "$WORK/bin" > "$INSTALL/install.json"
}
write_record "$IMAGE"
# The device and inode numbers of a directory, as the installer bakes them in.
pin_of() { stat -c '%d:%i' -- "$1" 2>/dev/null || stat -f '%d:%i' -- "$1"; }
PIN=$(pin_of "$INSTALL")
# bake PIN OUT: a launcher for INSTALL with PIN baked in, as install.sh writes it.
bake() {
    sed -e "s|__KNOSSOS_INSTALL_DIR__|$INSTALL|" -e "s|^INSTALL_PIN='__KNOSSOS_INSTALL_PIN__'\$|INSTALL_PIN='$1'|" "$TEMPLATE" > "$2"
    chmod +x "$2"
}
LAUNCHER="$WORK/knossos"
bake "$PIN" "$LAUNCHER"

PROJECT="$WORK/my project"
mkdir -p "$PROJECT/src/deep"
printf '{\n    "roots": [\n        "%s"\n    ]\n}\n' "$PROJECT" > "$INSTALL/roots.json"

# kn ARGS: the launcher with the caller's data and roots settings removed, from the current directory.
kn() { env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE -u KNOSSOS_NO_UPDATE_CHECK "$LAUNCHER" "$@"; }

UID_GID="$(id -u):$(id -g)"
NAME="knossos-$(hash12 "$INSTALL
$PROJECT
$DATA
$INSTALL/roots.json
$IMAGE")"

# ---- the first call starts the container, the call runs in it ---------------

reset_docker
out=$(cd "$PROJECT/src/deep" && kn version --json </dev/null 2>&1); status=$?
expected_run="call [run] [-d] [--rm] [--init] [--no-healthcheck] [--pull=never] [--name] [$NAME] [--label] [io.knossos.install=$(hash12 "$INSTALL")] [--label] [io.knossos.root=$(hash12 "$PROJECT")] [--network] [none] [--user] [$UID_GID] [-e] [HOME=/tmp] [-e] [KNOSSOS_CONTAINER=0] [-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$INSTALL] [-e] [KNOSSOS_DATA_DIR=$DATA] [-e] [KNOSSOS_ROOTS_FILE=$INSTALL/roots.json] [-e] [KNOSSOS_SETTINGS_DIR=$DATA] [-e] [KNOSSOS_ENRICHERS_FILE=$INSTALL/settings/enrichers.json] [--mount] [type=bind,source=$PROJECT,target=$PROJECT,readonly] [--mount] [type=bind,source=$DATA,target=$DATA] [--mount] [type=bind,source=$INSTALL,target=$INSTALL,readonly] [--entrypoint] [/opt/knossos/tools/launcher/idle-stop] [$IMAGE] [$INSTALL/containers/$NAME]"
if [ "$status" -eq 0 ] && grep -qxF "$expected_run" "$FAKE_DOCKER_STATE/calls"; then
    pass 'the first call runs the container with same-path mounts, the install dir as one read-only mount, the user, HOME=/tmp, no network and idle-stop'
else
    fail "run line (status $status, output $out): $(calls)"
fi
exec_line=$(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls")
case $exec_line in
    "call [exec] [-i] [-w] [$PROJECT/src/deep] [-e] [HOME=/tmp] [-e] [KNOSSOS_CONTAINER=0] [-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$INSTALL] [-e] [KNOSSOS_DATA_DIR=$DATA] [-e] [KNOSSOS_ROOTS_FILE=$INSTALL/roots.json] [-e] [KNOSSOS_SETTINGS_DIR=$DATA] [-e] [KNOSSOS_ENRICHERS_FILE=$INSTALL/settings/enrichers.json] "*"[$NAME] [/opt/knossos/tools/launcher/idle-stop] [--run] ["*"] [/opt/knossos/bin/knossos] [version] [--json]")
        pass 'the call is an exec -i in the working directory, without -t when there is no terminal'
        ;;
    *) fail "exec line: $exec_line" ;;
esac
case $exec_line in *'[-t]'*) fail 'exec got -t without a terminal' ;; esac
case $out in *'[/opt/knossos/bin/knossos][version][--json]'*) pass 'the command and its arguments reach the container' ;; *) fail "output: $out" ;; esac
if [ "$(count_calls run)" -eq 1 ] && [ "$(count_calls container)" -eq 2 ]; then pass 'a cold start is one run, with an inspect before it and one after it that reads the mounts back'; else fail "cold start calls: $(calls)"; fi
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
$DATA
$INSTALL/roots.json
$IMAGE")"
if [ "$status" -eq 0 ] && grep -qF "[--name] [$OTHER_NAME]" "$FAKE_DOCKER_STATE/calls" \
    && grep -qF "[--mount] [type=bind,source=$OTHER,target=$OTHER,readonly]" "$FAKE_DOCKER_STATE/calls"; then
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
if grep -qF "[--mount] [type=bind,source=$LOOSE,target=$LOOSE,readonly]" "$FAKE_DOCKER_STATE/calls"; then pass 'outside every listed root the working directory is the root'; else fail "loose dir: $(calls)"; fi

# The filesystem root is refused rather than mounted.
out=$(cd / && kn version </dev/null 2>&1); status=$?
case $out in *'the project root / is the filesystem root, which container mode does not mount'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ]; then pass 'the filesystem root is never mounted'; else fail "root dir: status $status, $out"; fi

# ---- the roots file in its other shapes ---------------------------------------

NESTED="$PROJECT/src"
printf '["%s","%s"]' "$PROJECT" "$NESTED" > "$INSTALL/roots.json"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$NESTED/deep" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "[--mount] [type=bind,source=$NESTED,target=$NESTED,readonly]" "$FAKE_DOCKER_STATE/calls"; then pass 'a bare array works, and the nearest listed ancestor wins'; else fail "nested roots: $(calls)"; fi
QUOTED="$WORK/q\"uo\\te"
mkdir -p "$QUOTED/x"
printf '{"roots":["%s"]}' "$WORK/q\\\"uo\\\\te" > "$INSTALL/roots.json"
: > "$FAKE_DOCKER_STATE/calls"
(cd "$QUOTED/x" && kn version </dev/null >/dev/null 2>&1)
QUOTED_CSV=$(printf '%s' "$QUOTED" | sed 's/"/""/g')
if grep -qF "[--mount] [type=bind,\"source=$QUOTED_CSV\",\"target=$QUOTED_CSV\",readonly]" "$FAKE_DOCKER_STATE/calls"; then pass 'escaped quotes and backslashes in the roots file are read'; else fail "escaped roots: $(calls)"; fi
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
    *"[-e] [KNOSSOS_DATA_DIR=$GRAPH] [-e] [KNOSSOS_ROOTS_FILE=$ROOTS] [-e] [KNOSSOS_SETTINGS_DIR=$GRAPH] [-e] [KNOSSOS_ENRICHERS_FILE=$INSTALL/settings/enrichers.json] [--mount] [type=bind,source=$PROJECT,target=$PROJECT,readonly] [--mount] [type=bind,source=$GRAPH,target=$GRAPH] [--mount] [type=bind,source=$WORK/roots dir,target=$WORK/roots dir,readonly] [--mount] [type=bind,source=$INSTALL,target=$INSTALL,readonly] "*)
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
INSTALL2="$WORK/second/install"
mkdir -p "$INSTALL2/plugin" "$INSTALL2/containers"
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
    if [ "$status" -eq 1 ] && [ "$lines" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ] && [ "$(count_calls exec)" -eq 0 ]; then
        pass "$what is refused, and no container is started"
    else
        fail "$what: status $status, $out, $(calls)"
    fi
}
refused 'a roots file in the home directory' 'put the roots file in a directory of its own and point KNOSSOS_ROOTS_FILE' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE="$HOME/roots.json" "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
refused 'a roots file at the filesystem root' 'is the filesystem root' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE=/roots.json "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
refused 'KNOSSOS_DATA_DIR=/' 'point KNOSSOS_DATA_DIR at a directory of its own' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR=/ KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
refused 'KNOSSOS_DATA_DIR set to the home directory' 'is your home directory' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$HOME" KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
refused 'a data dir above the home directory' 'contains your home directory' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE="$3/roots.json" "$4" version </dev/null' _ "$PROJECT" "$WORK" "$DATA" "$LAUNCHER"
refused 'the home directory as the project root' 'the project root' \
    sh -c 'cd "$HOME" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$1" version </dev/null' _ "$LAUNCHER"
refused 'the filesystem root as the project root' 'the project root / is the filesystem root' \
    sh -c 'cd / && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$1" version </dev/null' _ "$LAUNCHER"
cp "$INSTALL/install.json" "$HOME/install.json"
mkdir -p "$HOME/plugin" "$HOME/containers"
refused 'an install directory that is the home directory' 're-run the installer with --data-dir' \
    sh -c 'cd "$1" && KNOSSOS_INSTALL_DIR="$HOME" KNOSSOS_DATA_DIR="$2" env -u KNOSSOS_ROOTS_FILE "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
rm -rf "$HOME/install.json" "$HOME/plugin" "$HOME/containers"

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
if grep -qF "[--mount] [type=bind,source=$INSTALL,target=$INSTALL,readonly]" "$FAKE_DOCKER_STATE/calls" \
    && ! grep -q "source=$INSTALL/" "$FAKE_DOCKER_STATE/calls"; then
    pass 'the install directory is one read-only mount, with no mount of anything inside it'
else
    fail "install mount: $(calls)"
fi
rm -rf "$INSTALL/plugin/hooks" "$INSTALL/container-update"

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
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$HOME/nope/.." KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
if [ ! -e "$HOME/nope" ]; then pass 'no directory is created through ..'; else fail "$HOME/nope was created"; fi
refused 'a roots file spelled through a missing directory and ..' "through '..'" \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" KNOSSOS_ROOTS_FILE="$HOME/nope/../roots.json" "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
# An existing directory with `..` is judged by where it leads.
mkdir -p "$HOME/sub"
refused 'an existing data dir whose .. leads to the home directory' 'is your home directory' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$HOME/sub/.." KNOSSOS_ROOTS_FILE="$2/roots.json" "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
ln -s "$HOME" "$WORK/to home"
refused 'a data dir that is a symlink to the home directory' 'is your home directory' \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2/to home" KNOSSOS_ROOTS_FILE="$3/roots.json" "$4" version </dev/null' _ "$PROJECT" "$WORK" "$DATA" "$LAUNCHER"

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
if [ "$status" -eq 0 ] && grep -qF "[--mount] [type=bind,source=$HOME/proj,target=$HOME/proj,readonly]" "$FAKE_DOCKER_STATE/calls"; then
    pass 'with the home directory listed, a narrower listed root below it is used'
else
    fail "home listed, narrower root: $status, $out, $(calls)"
fi
reset_docker
out=$(cd "$HOME/other/deep" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qF "[--mount] [type=bind,source=$HOME/other/deep,target=$HOME/other/deep,readonly]" "$FAKE_DOCKER_STATE/calls"; then
    pass 'with the home directory listed and nothing narrower, the working directory is the root'
else
    fail "home listed, working directory: $status, $out, $(calls)"
fi
printf '{\n    "roots": [\n        "%s"\n    ]\n}\n' "$PROJECT" > "$INSTALL/roots.json"

# ---- symlinks: everything the container is given is the physical path -------------------

REAL="$WORK/real"
# The graph, the roots file and the project outside the install's default
# graph directory ($REAL), where a container of that graph could not change them.
PHYS="$WORK/phys"
mkdir -p "$PHYS/graph" "$PHYS/roots" "$PHYS/proj/src" "$REAL/install/plugin" "$REAL/install/containers"
cp "$INSTALL/install.json" "$REAL/install/install.json"
ln -s "$PHYS/graph" "$WORK/graph link"
ln -s "$PHYS/roots" "$WORK/roots link"
ln -s "$PHYS/proj" "$WORK/proj link"
ln -s "$REAL/install" "$WORK/install link"
printf '{"roots": ["%s"]}\n' "$WORK/proj link" > "$PHYS/roots/roots.json"
reset_docker
out=$(cd "$WORK/proj link/src" && KNOSSOS_INSTALL_DIR="$REAL/install" KNOSSOS_DATA_DIR="$WORK/graph link" \
    KNOSSOS_ROOTS_FILE="$WORK/roots link/roots.json" "$LAUNCHER" version </dev/null 2>&1); status=$?
run_line=$(grep '^call \[run\] \[-d\]' "$FAKE_DOCKER_STATE/calls")
exec_line=$(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1)
agree=yes
for wanted in "[-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$REAL/install]" "[-e] [KNOSSOS_DATA_DIR=$PHYS/graph]" "[-e] [KNOSSOS_ROOTS_FILE=$PHYS/roots/roots.json]" \
    "[--mount] [type=bind,source=$PHYS/proj,target=$PHYS/proj,readonly]" "[--mount] [type=bind,source=$PHYS/graph,target=$PHYS/graph]" \
    "[--mount] [type=bind,source=$PHYS/roots,target=$PHYS/roots,readonly]" "[--mount] [type=bind,source=$REAL/install,target=$REAL/install,readonly]" \
    "[$REAL/install/containers/knossos-"; do
    case $run_line in *"$wanted"*) ;; *) agree=no; printf '  run lacks %s\n' "$wanted" ;; esac
done
for wanted in "[-w] [$PHYS/proj/src]" "[-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$REAL/install]" "[-e] [KNOSSOS_DATA_DIR=$PHYS/graph]" "[-e] [KNOSSOS_ROOTS_FILE=$PHYS/roots/roots.json]"; do
    case $exec_line in *"$wanted"*) ;; *) agree=no; printf '  exec lacks %s\n' "$wanted" ;; esac
done
case "$run_line$exec_line" in *link*) agree=no; printf '  a symlinked spelling reached the container\n' ;; esac
if [ "$status" -eq 0 ] && [ "$agree" = yes ] && [ -n "$(find "$REAL/install/containers" -name 'knossos-*' 2>/dev/null)" ]; then
    pass 'through symlinked graph, roots and project directories, the mounts, the environment, the working directory and the marker all use the physical path'
else
    fail "symlinks: $status, $out"
fi
# The roots entry was spelled through a symlink: inside, only its physical form exists.
case $exec_line in
    *"[-e] [KNOSSOS_ALLOWED_ROOTS=$PHYS/proj]"*) pass 'a root listed through a symlink is granted in its physical form inside' ;;
    *) fail "allowed root through a symlink: $exec_line" ;;
esac

# ---- the install directory: nothing in it is trusted that a container could plant ----

# A file outside every mount that a planted link would aim at; its bytes must never change.
SENTINEL="$HOME/.secret"
printf 'do not touch\n' > "$SENTINEL"
sentinel_intact() { [ "$(cat "$SENTINEL" 2>/dev/null)" = 'do not touch' ]; }
# planted WHAT: the call is refused in one line, starts nothing, runs nothing, and the sentinel is intact.
planted() {
    what=$1
    reset_docker
    out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
    case $out in *'re-run the installer'*) said=yes ;; *) said=no ;; esac
    if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ] && [ "$(count_calls exec)" -eq 0 ] && sentinel_intact; then
        pass "a symlink planted at $what is refused, nothing runs and nothing outside is written"
    else
        fail "planted $what: status $status, $out, $(calls), sentinel $(cat "$SENTINEL" 2>&1)"
    fi
}
mv "$INSTALL/plugin" "$INSTALL/plugin.real"
ln -s "$HOME" "$INSTALL/plugin"
planted 'plugin'
rm -f "$INSTALL/plugin"
mv "$INSTALL/plugin.real" "$INSTALL/plugin"

mv "$INSTALL/containers" "$INSTALL/containers.real"
ln -s "$HOME" "$INSTALL/containers"
planted 'containers'
rm -f "$INSTALL/containers"
mv "$INSTALL/containers.real" "$INSTALL/containers"

rm -f "$INSTALL/containers/$NAME"
ln -s "$SENTINEL" "$INSTALL/containers/$NAME"
planted 'the idle marker'
rm -f "$INSTALL/containers/$NAME"

ln -s "$SENTINEL" "$INSTALL/container-update"
planted 'the update helper copy'
rm -f "$INSTALL/container-update"

ln -s "$SENTINEL" "$INSTALL/update-check.json"
planted 'update-check.json'
rm -f "$INSTALL/update-check.json"

mv "$INSTALL/install.json" "$WORK/install.json.real"
ln -s "$WORK/install.json.real" "$INSTALL/install.json"
reset_docker
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
case $out in *"$INSTALL/install.json is a symlink; re-run the installer"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then pass 'a symlinked install.json is refused in container mode, and nothing runs'; else fail "planted install.json: $status, $out"; fi
rm -f "$INSTALL/install.json"
mv "$WORK/install.json.real" "$INSTALL/install.json"

ln -s "$INSTALL" "$WORK/install link"
reset_docker
out=$(cd "$PROJECT" && KNOSSOS_INSTALL_DIR="$WORK/install link" kn version </dev/null 2>&1); status=$?
case $out in *'is a symlink; re-run the installer'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ]; then pass 'an install directory that is a symlink is refused'; else fail "install dir link: $status, $out"; fi
reset_docker
out=$(cd "$PROJECT" && KNOSSOS_INSTALL_DIR="$WORK/install link/" kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 1 ] && [ "$(count_calls run)" -eq 0 ]; then pass 'an install directory reached through a symlinked parent is refused'; else fail "install dir via link: $status, $out"; fi

# A record in container mode without the separate layout: no release wrote one.
printf '{"mode": "container", "image": "%s", "runtime": "docker"}\n' "$IMAGE" > "$INSTALL/install.json"
reset_docker
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
case $out in *'a layout no release wrote'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ]; then pass 'a container record at the old location is refused'; else fail "old layout: $status, $out"; fi
write_record "$IMAGE"

# A graph or roots directory inside the read-only install directory is refused.
reset_docker
out=$(cd "$PROJECT" && KNOSSOS_DATA_DIR="$INSTALL" KNOSSOS_ROOTS_FILE="$DATA/roots.json" "$LAUNCHER" version </dev/null 2>&1); status=$?
case $out in *'lies inside the install directory'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ]; then pass 'a graph directory inside the install directory is refused'; else fail "graph inside install: $status, $out"; fi

# The helper writes its files in a fresh work directory, never under a name a container could guess.
if grep -n 'INSTALL[^"]*\.tmp\.\$\$' "$HERE/../../tools/launcher/container-update" "$HERE/../../tools/launcher/knossos" >/dev/null; then
    fail 'a host write into the install directory still uses a pid-named temporary file'
else
    pass 'no host write into the install directory uses a pid-named temporary file'
fi

# ---- a container runs only with the mounts that were checked ------------------------------

reset_docker
out=$(cd "$PROJECT" && FAKE_EXTRA_MOUNT='/etc|/etc|true' kn version </dev/null 2>&1); status=$?
case $out in *'does not have the mounts that were checked, so it was removed'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && has_call "call [rm] [-f] [$NAME]" && [ "$(count_calls exec)" -eq 0 ]; then
    pass 'a new container with a mount nobody checked is removed before anything runs in it'
else
    fail "mismatch on create: $status, $out, $(calls)"
fi
reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
printf '%s|%s|true\n' "$HOME" "$HOME" >> "$FAKE_DOCKER_STATE/containers/$NAME.mounts"
: > "$FAKE_DOCKER_STATE/calls"
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
case $out in *'does not have the mounts that were checked'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && has_call "call [rm] [-f] [$NAME]" && [ "$(count_calls exec)" -eq 0 ]; then
    pass 'a running container whose mounts differ is removed, never reused'
else
    fail "mismatch on reuse: $status, $out, $(calls)"
fi

# ---- the install directory is the one the installer created ------------------------------

# pin_refused WHAT LAUNCHER [ARGS]: refused in one line naming the install
# directory, before install.json is read (the record is unreadable on purpose,
# which would otherwise say "unreadable mode"), and without any runtime call.
pin_refused() {
    what=$1 launcher=$2
    shift 2
    [ $# -gt 0 ] || set -- version
    cp "$INSTALL/install.json" "$WORK/record.kept"
    printf '{"mode": "bogus"}\n' > "$INSTALL/install.json"
    reset_docker
    out=$(cd "$PROJECT" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$launcher" "$@" </dev/null 2>&1); status=$?
    cp "$WORK/record.kept" "$INSTALL/install.json"
    if [ "$status" -eq 1 ] && [ "$out" = "knossos: the install directory $INSTALL is not the one the installer created; re-run the installer to fix it." ] \
        && [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then
        pass "$what is refused before install.json is read"
    else
        fail "$what: $status, $out, $(calls)"
    fi
}
dev=${PIN%%:*}
ino=${PIN#*:}
bake "$dev:$((ino + 1))" "$WORK/wrong inode"
pin_refused 'a launcher whose pinned inode differs' "$WORK/wrong inode"
# The device is kept for diagnostics only: it can change across a reboot.
bake "$((dev + 1)):$ino" "$WORK/wrong device"
reset_docker
out=$(cd "$PROJECT" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/wrong device" version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'a pin whose device differs but whose inode matches is accepted'; else fail "device change: $status, $out"; fi
bake "$((dev + 1)):$((ino + 1))" "$WORK/wrong both"
pin_refused 'a launcher whose pinned device and inode differ' "$WORK/wrong both"
bake ":$((ino + 1))" "$WORK/wrong inode only"
pin_refused 'a pin of the inode alone that differs' "$WORK/wrong inode only"
# The attack itself: the directory moved aside and a copy put in its place.
mv "$INSTALL" "$INSTALL.old"
cp -pR "$INSTALL.old" "$INSTALL"
pin_refused 'an install directory replaced by a copy' "$LAUNCHER"
pin_refused 'stop, with the install directory replaced,' "$LAUNCHER" stop --all
pin_refused 'self-update, with the install directory replaced,' "$LAUNCHER" self-update --check
rm -rf "$INSTALL"
mv "$INSTALL.old" "$INSTALL"
reset_docker
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'the original install directory is accepted again'; else fail "after restore: $status, $out"; fi
bake ":$ino" "$WORK/inode only"
reset_docker
out=$(cd "$PROJECT" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/inode only" version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'a pin of the inode alone, as ls -di gives it, is accepted when it matches'; else fail "inode-only pin: $status, $out"; fi
# Without a stat command the inode comes from ls -di.
NOSTAT="$WORK/no stat"
mkdir -p "$NOSTAT"
for tool in env sh awk sed cut sha256sum shasum mkdir dirname id date sort ls cat grep printf; do
    found=$(command -v "$tool" 2>/dev/null || true)
    case $found in /*) ln -s "$found" "$NOSTAT/$tool" ;; esac
done
ln -s "$FAKE_BIN/docker" "$NOSTAT/docker"
reset_docker
out=$(cd "$PROJECT" && PATH="$NOSTAT" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/inode only" version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'without stat, the inode read by ls -di is compared'; else fail "ls -di pin: $status, $out"; fi
out=$(cd "$PROJECT" && PATH="$NOSTAT" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/wrong inode only" version </dev/null 2>&1); status=$?
case $out in *'is not the one the installer created'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ]; then pass 'without stat, an inode that differs is refused'; else fail "ls -di wrong pin: $status, $out"; fi
sed "s|__KNOSSOS_INSTALL_DIR__|$INSTALL|" "$TEMPLATE" > "$WORK/no pin"
chmod +x "$WORK/no pin"
reset_docker
out=$(cd "$PROJECT" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$WORK/no pin" version </dev/null 2>&1); status=$?
case $out in *'this launcher carries no record of which directory the installer created; re-run the installer'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then pass 'a container launcher without a pin is refused'; else fail "no pin: $status, $out"; fi

# ---- no read-write mount holds a directory the host trusts ------------------------------
#
# A container that can write an ancestor of the install directory can rename
# it aside and plant one of its own, which the host then trusts; one that can
# write the launcher's directory can replace the launcher. Each such layout is
# refused before anything runs.

# nested_install DIR: an install directory at DIR, as install.sh makes it.
nested_install() {
    mkdir -p "$1/plugin" "$1/containers"
    cp "$INSTALL/install.json" "$1/install.json"
}
NEST="$WORK/nest"
nested_install "$NEST/proj/.kn/install"
refused 'a project root that contains the data directory' "the project root $NEST/proj contains the install directory $NEST/proj/.kn/install, which a container must never be able to write; run knossos from a project that does not contain it, or re-run the installer with --data-dir outside the project." \
    sh -c 'cd "$1" && KNOSSOS_INSTALL_DIR="$2" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$3" version </dev/null' _ "$NEST/proj" "$NEST/proj/.kn/install" "$LAUNCHER"
refused 'a project root that is the data directory' "the project root $NEST/proj/.kn contains the install directory" \
    sh -c 'cd "$1" && KNOSSOS_INSTALL_DIR="$2" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$3" version </dev/null' _ "$NEST/proj/.kn" "$NEST/proj/.kn/install" "$LAUNCHER"
# The graph elsewhere, the install directory still inside the project.
refused 'a project root that contains the install directory' "the project root $NEST/proj contains the install directory $NEST/proj/.kn/install" \
    sh -c 'cd "$1" && KNOSSOS_INSTALL_DIR="$2" KNOSSOS_DATA_DIR="$3" env -u KNOSSOS_ROOTS_FILE "$4" version </dev/null' _ "$NEST/proj" "$NEST/proj/.kn/install" "$GRAPH" "$LAUNCHER"
# A graph directory set by hand inside the project.
mkdir -p "$PROJECT/.graph"
refused 'a project root that contains the graph directory' "the project root $PROJECT contains the Knossos data directory $PROJECT/.graph" \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$1/.graph" env -u KNOSSOS_ROOTS_FILE "$2" version </dev/null' _ "$PROJECT" "$LAUNCHER"
rm -rf "$PROJECT/.graph"
# A roots file in a directory around the data directory.
nested_install "$NEST/outer/data/install"
refused 'a roots file directory that contains the data directory' "the directory of KNOSSOS_ROOTS_FILE ($NEST/outer) contains the install directory $NEST/outer/data/install, which a container must never be able to write; move the roots file to a directory of its own and point KNOSSOS_ROOTS_FILE at it." \
    sh -c 'cd "$1" && KNOSSOS_INSTALL_DIR="$2" KNOSSOS_ROOTS_FILE="$3/roots.json" env -u KNOSSOS_DATA_DIR "$4" version </dev/null' _ "$PROJECT" "$NEST/outer/data/install" "$NEST/outer" "$LAUNCHER"
# A graph directory two levels above the install directory.
refused 'a graph directory that holds the install directory deeper than one level' "KNOSSOS_DATA_DIR ($NEST/outer) contains the install directory $NEST/outer/data/install deeper than one level" \
    sh -c 'cd "$1" && KNOSSOS_INSTALL_DIR="$2" KNOSSOS_DATA_DIR="$3" env -u KNOSSOS_ROOTS_FILE "$4" version </dev/null' _ "$PROJECT" "$NEST/outer/data/install" "$NEST/outer" "$LAUNCHER"
# The launcher inside the project, and inside the graph directory.
mkdir -p "$NEST/tools/proj/bin"
cp "$LAUNCHER" "$NEST/tools/proj/bin/knossos"
refused "a project root that contains the launcher's directory" "the project root $NEST/tools/proj contains $NEST/tools/proj/bin, the directory of the knossos launcher, which a container must never be able to write; run knossos from a project that does not contain it, or re-run the installer with --bin-dir outside the project." \
    sh -c 'cd "$1" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" version </dev/null' _ "$NEST/tools/proj" "$NEST/tools/proj/bin/knossos"
mkdir -p "$DATA/bin"
cp "$LAUNCHER" "$DATA/bin/knossos"
refused "a graph directory that contains the launcher's directory" "KNOSSOS_DATA_DIR ($DATA) contains $DATA/bin, the directory of the knossos launcher" \
    sh -c 'cd "$1" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" version </dev/null' _ "$PROJECT" "$DATA/bin/knossos"
rm -rf "${DATA:?}/bin"
# The bin directory install.json names counts too, wherever the launcher was run from.
write_record "$IMAGE"
sed "s|\"bin_dir\": \"[^\"]*\"|\"bin_dir\": \"$NEST/tools/proj/bin\"|" "$INSTALL/install.json" > "$WORK/record.tmp" && mv "$WORK/record.tmp" "$INSTALL/install.json"
refused "a project root that contains the bin directory install.json names" "contains $NEST/tools/proj/bin, the directory of the knossos launcher" \
    sh -c 'cd "$1" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" version </dev/null' _ "$NEST/tools/proj" "$LAUNCHER"
write_record "$IMAGE"
# The same refusal for a container that is already running: it is never reused.
reset_docker
out=$(cd "$NEST/proj" && KNOSSOS_INSTALL_DIR="$NEST/proj/.kn/install" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" version </dev/null 2>&1); status=$?
if [ "$status" -eq 1 ] && [ "$(count_calls exec)" -eq 0 ] && [ "$(count_calls container)" -eq 0 ]; then
    pass 'a refused layout is refused before any container is looked at'
else
    fail "refused layout with a running container: $status, $out, $(calls)"
fi

# Every layout that is accepted mounts the graph directory as its own
# read-write mount, with the install directory read-only directly below it.
graph_mounted() {
    run_line=$(grep '^call \[run\] \[-d\]' "$FAKE_DOCKER_STATE/calls" | tail -n 1)
    case $run_line in
        *"[--mount] [type=bind,source=$1,target=$1] "*"[--mount] [type=bind,source=$2,target=$2,readonly] "*) return 0 ;;
    esac
    return 1
}
all_graph=yes
for layout in default roots-apart; do
    reset_docker
    case $layout in
        default) (cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1) ;;
        roots-apart) (cd "$PROJECT" && KNOSSOS_ROOTS_FILE="$ROOTS" env -u KNOSSOS_DATA_DIR "$LAUNCHER" version </dev/null >/dev/null 2>&1) ;;
    esac
    if ! graph_mounted "$DATA" "$INSTALL"; then all_graph=no; printf '  %s: %s\n' "$layout" "$(calls)"; fi
done
if [ "$all_graph" = yes ]; then pass 'the graph directory is always its own read-write mount, the install directory read-only below it'; else fail 'a layout ran without the graph directory as its own mount'; fi

# ---- a reused container runs the image, network and user that were checked --------------

# reuse_refused WHAT CONFIG: a running container whose inspect reports CONFIG
# (`image|network|user`) is removed and the call refused, with nothing exec'd.
reuse_refused() {
    reset_docker
    (cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
    printf '%s\n' "$2" > "$FAKE_DOCKER_STATE/containers/$NAME.config"
    : > "$FAKE_DOCKER_STATE/calls"
    out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
    case $out in *"container $NAME does not run the image, network or user that were checked, so it was removed; run the command again."*) said=yes ;; *) said=no ;; esac
    if [ "$status" -eq 1 ] && [ "$said" = yes ] && has_call "call [rm] [-f] [$NAME]" && [ "$(count_calls exec)" -eq 0 ] && [ "$(count_calls container)" -eq 1 ]; then
        pass "a running container with $1 is removed in the same inspect that reads its mounts, never reused"
    else
        fail "reuse with $1: $status, $out, $(calls)"
    fi
}
reuse_refused 'another image' "ghcr.io/araneadev/knossos:latest|none|$UID_GID"
reuse_refused 'a network' "$IMAGE|bridge|$UID_GID"
reuse_refused 'another user' "$IMAGE|none|0:0"
# The same for a container just started that the runtime reports differently.
reset_docker
out=$(cd "$PROJECT" && FAKE_CONFIG="$IMAGE|host|$UID_GID" kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 1 ] && has_call "call [rm] [-f] [$NAME]" && [ "$(count_calls exec)" -eq 0 ]; then
    pass 'a new container that does not run as asked is removed before anything runs in it'
else
    fail "config mismatch on create: $status, $out, $(calls)"
fi
# Podman reports a short image name fully qualified.
cp "$HERE/fixtures/fake-docker" "$FAKE_BIN/podman"
write_record knossos:local podman
reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
PNAME=$(sed -n 's/^call \[run\] .*\[--name\] \[\([^]]*\)\].*/\1/p' "$FAKE_DOCKER_STATE/calls" | head -n 1)
printf 'localhost/knossos:local|none|%s\n' "$UID_GID" > "$FAKE_DOCKER_STATE/containers/$PNAME.config"
: > "$FAKE_DOCKER_STATE/calls"
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$(count_calls exec)" -eq 1 ]; then pass 'podman reporting localhost/<image> counts as the same image'; else fail "podman image name: $status, $out, $(calls)"; fi
write_record "$IMAGE"

# ---- the project is read-only; named files go through the host -------------------------

# scratch_left: scratch or private directories this test's calls left behind.
scratch_left() { find "$DATA" "$WORK/tmp" -maxdepth 1 \( -name '.knossos-files.*' -o -name 'knossos-files.*' \) 2>/dev/null; }
mkdir -p "$WORK/tmp"
# out_call ARGS: a call from the project with a private TMPDIR, output and status kept.
out_call() {
    reset_docker
    out=$(cd "$PROJECT" && TMPDIR="$WORK/tmp" kn "$@" </dev/null 2>&1); status=$?
}
OUT_IN="$PROJECT/out/bundle.json"
mkdir -p "$PROJECT/out" "$WORK/outside"
OUT_OUT="$WORK/outside/brief.md"

FAKE_EXEC_WRITE='bundle bytes' out_call export-bundle project_1 "--output=$OUT_IN" --json
exec_line=$(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1)
case $exec_line in *"[--output=$DATA/.knossos-files."*"/output]"*) via=scratch ;; *) via=other ;; esac
if [ "$status" -eq 0 ] && [ "$via" = scratch ] && [ "$(cat "$OUT_IN" 2>/dev/null)" = 'bundle bytes' ] && [ -z "$(scratch_left)" ]; then
    pass 'an --output in the read-only project is written by the command into a scratch file and moved there by the host'
else
    fail "output into the project: $status, $out, $exec_line, $(cat "$OUT_IN" 2>&1), left $(scratch_left)"
fi
case $out in *"$OUT_IN"*) case $out in *.knossos-files.*) fail "the scratch path was printed: $out" ;; *) pass 'the command prints the path that was named, not the scratch path' ;; esac ;; *) fail "named path not printed: $out" ;; esac
rm -f "$OUT_IN"

FAKE_EXEC_WRITE='brief text' out_call export-agent-brief "$PROJECT" "--out=$OUT_OUT"
if [ "$status" -eq 0 ] && [ "$(cat "$OUT_OUT" 2>/dev/null)" = 'brief text' ]; then pass '--out=PATH to a path outside every mount is written too'; else fail "--out outside: $status, $out"; fi
rm -f "$OUT_OUT"
# The CLI reads only --name=VALUE, so a spaced `--name VALUE` is a flag and a
# positional argument here too, and nothing is placed for it.
FAKE_EXEC_WRITE='brief text' out_call export-agent-brief "$PROJECT" --out "$OUT_OUT"
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1) in *"[export-agent-brief] [$PROJECT] [--out] [$OUT_OUT]") said=yes ;; *) said=no ;; esac
if [ "$said" = yes ] && [ ! -e "$OUT_OUT" ]; then pass 'a spaced --out PATH is passed on as the CLI reads it, a flag and an argument, and nothing is placed'; else fail "spaced --out: $status, $out, $(calls)"; fi
FAKE_EXEC_WRITE='{"format":"knossos-notes"}' out_call export-notes "$PROJECT" --file --json
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1) in *"[export-notes] [$PROJECT] [--file] [--json]") said=yes ;; *) said=no ;; esac
if [ "$said" = yes ] && [ ! -e "$PROJECT/--json" ] && [ ! -e "$PROJECT/knossos-notes.json" ]; then pass 'export-notes --file --json writes no file named --json, and adds no default --file the CLI would see twice'; else fail "value-less --file: $status, $out, $(calls)"; fi

(cd "$PROJECT/out" && FAKE_EXEC_WRITE=rel TMPDIR="$WORK/tmp" kn export-bundle project_1 --output=rel.json </dev/null >/dev/null 2>&1)
if [ "$(cat "$PROJECT/out/rel.json" 2>/dev/null)" = rel ]; then pass 'a relative --output is resolved against the working directory on the host'; else fail "relative output: $(ls "$PROJECT/out")"; fi
rm -f "$PROJECT/out/rel.json"

printf 'old\n' > "$OUT_OUT"
FAKE_EXEC_WRITE=new FAKE_EXEC_EXIT=3 out_call export-agent-brief "$PROJECT" "--out=$OUT_OUT"
if [ "$status" -eq 3 ] && [ "$(cat "$OUT_OUT")" = old ] && [ -z "$(scratch_left)" ]; then pass 'a command that fails writes nothing at the destination, and its scratch file is removed'; else fail "failed output: $status, $(cat "$OUT_OUT"), left $(scratch_left)"; fi

ln -s "$SENTINEL" "$WORK/outside/link.md"
FAKE_EXEC_WRITE=x out_call export-agent-brief "$PROJECT" "--out=$WORK/outside/link.md"
if [ "$status" -eq 1 ] && [ "$out" = "knossos: $WORK/outside/link.md is a symlink; name the file itself, not a link to it." ] && [ "$(count_calls run)" -eq 0 ] && [ "$(count_calls exec)" -eq 0 ] && sentinel_intact; then
    pass 'a destination that is a symlink is refused before anything runs'
else
    fail "symlinked destination: $status, $out, $(calls)"
fi
FAKE_EXEC_WRITE=x out_call export-agent-brief "$PROJECT" "--out=$WORK/no such dir/brief.md"
if [ "$status" -eq 1 ] && [ "$out" = "knossos: cannot write $WORK/no such dir/brief.md: the directory $WORK/no such dir does not exist." ] && [ "$(count_calls run)" -eq 0 ] && [ ! -e "$WORK/no such dir" ]; then
    pass 'a destination whose directory does not exist is refused before any run, and nothing is created'
else
    fail "missing parent: $status, $out, $(calls)"
fi
# A command that leaves a link where its output belongs: nothing is copied through it.
FAKE_EXEC_LINK="$SENTINEL" out_call export-agent-brief "$PROJECT" "--out=$WORK/outside/linked.md"
expected="knossos: the command left no plain file with a single link for $WORK/outside/linked.md; nothing was written.
KNOSSOS_INVALID_ARGUMENT: Unable to write brief to $WORK/outside/linked.md."
if [ "$status" -eq 2 ] && [ ! -e "$WORK/outside/linked.md" ] && [ ! -L "$WORK/outside/linked.md" ] && sentinel_intact; then
    pass 'a link left in the scratch directory is never followed to the destination'
else
    fail "link in scratch: $status, $out, $(ls -l "$WORK/outside")"
fi
# ... and the command's own answer, which said it wrote the file, is not printed (N14).
if [ "$out" = "$expected" ]; then pass 'an output the host could not place is said as the failure the command gives, not as its success'; else fail "success printed beside the refusal: $out"; fi
FAKE_EXEC_LINK="$SENTINEL" out_call export-notes "$PROJECT" "--file=$WORK/outside/notes.json" --json
if [ "$status" -eq 2 ] && [ "$out" = "knossos: the command left no plain file with a single link for $WORK/outside/notes.json; nothing was written.
KNOSSOS_RUNTIME_ERROR: NOTES_FILE_WRITE_FAILED: could not write $WORK/outside/notes.json." ]; then
    pass 'a refused export-notes --json prints no JSON success, only the failure'
else
    fail "export-notes refused: $status, $out"
fi
FAKE_EXEC_LINK="$SENTINEL" out_call notes-export "$PROJECT" "--file=$WORK/outside/notes.json" --json
if [ "$status" -eq 0 ] && [ "$out" = "{\"status\":\"refused\",\"reason\":\"NOTES_FILE_WRITE_FAILED: could not write $WORK/outside/notes.json: the command left no plain file with a single link for $WORK/outside/notes.json; nothing was written.\"}" ]; then
    pass 'a refused notes-export answers refused, as the pane reads it'
else
    fail "notes-export refused: $status, $out"
fi
# The scratch output is bounded: more than KNOSSOS_OUTPUT_LIMIT_MB is never moved out.
FAKE_EXEC_KB=2048 KNOSSOS_OUTPUT_LIMIT_MB=1 out_call export-bundle project_1 "--output=$WORK/outside/big.json"
case $out in "knossos: the command wrote more than 1 MB, the most the host moves out of a container (KNOSSOS_OUTPUT_LIMIT_MB raises it), for $WORK/outside/big.json; nothing was written.${NL}KNOSSOS_INVALID_ARGUMENT: Unable to create bundle output: $WORK/outside/big.json") said=yes ;; *) said=no ;; esac
if [ "$status" -eq 2 ] && [ "$said" = yes ] && [ ! -e "$WORK/outside/big.json" ] && [ -z "$(scratch_left)" ]; then pass 'an output over the limit is refused and nothing is written'; else fail "output limit: $status, $out"; fi
FAKE_EXEC_KB=512 KNOSSOS_OUTPUT_LIMIT_MB=1 out_call export-bundle project_1 "--output=$WORK/outside/small.json"
if [ "$status" -eq 0 ] && [ "$(wc -c < "$WORK/outside/small.json" | tr -d ' ')" -eq 524288 ]; then pass 'an output under the limit is written whole'; else fail "under the limit: $status, $out"; fi
rm -f "$WORK/outside/small.json"
# What a hostile container leaves for the host to take: refused before anything is copied, nothing left behind.
taken_left() { find "$INSTALL" "$(dirname -- "$DATA")" -maxdepth 1 -name '.knossos-taken.*' 2>/dev/null; }
for shape in hardlink dir fifo; do
    FAKE_EXEC_SHAPE=$shape out_call export-agent-brief "$PROJECT" "--out=$WORK/outside/$shape.md"
    if [ "$status" -eq 2 ] && [ "$out" = "knossos: the command left no plain file with a single link for $WORK/outside/$shape.md; nothing was written.
KNOSSOS_INVALID_ARGUMENT: Unable to write brief to $WORK/outside/$shape.md." ] && [ ! -e "$WORK/outside/$shape.md" ] && [ -z "$(scratch_left)" ] && [ -z "$(taken_left)" ]; then
        pass "an output left as a $shape is refused before the host takes it, and nothing is left in TMPDIR or beside the install"
    else
        fail "$shape output: $status, $out, left $(scratch_left) $(taken_left)"
    fi
done
FAKE_EXEC_WRITE='brief' out_call export-agent-brief "$PROJECT" "--out=$WORK/outside/taken.md"
if [ "$status" -eq 0 ] && [ "$(cat "$WORK/outside/taken.md")" = brief ] && [ -z "$(taken_left)" ]; then pass 'a plain output is taken out of reach by a rename and written, and the directory it was taken into is removed'; else fail "taken output: $status, $out"; fi
rm -f "$WORK/outside/taken.md"
# With neither the install directory nor the directory around the graph on
# the graph's filesystem, the output is refused, and the message says what to
# change. A stat earlier on PATH reports another device for those two (the
# launcher compares devices through stat, see dir_id), as a separate mount would.
REAL_STAT=$(command -v stat)
APART_BIN="$WORK/apart bin"
mkdir -p "$APART_BIN"
{
    printf '#!/bin/sh\n'
    printf 'for last in "$@"; do :; done\n'
    printf 'case $last in\n'
    printf '    %s | %s) id=$(%s "$@") || exit; printf "999999:%%s\\n" "${id#*:}"; exit 0 ;;\n' "'$INSTALL'" "'$(dirname -- "$DATA")'" "'$REAL_STAT'"
    printf 'esac\n'
    printf 'exec %s "$@"\n' "'$REAL_STAT'"
} > "$APART_BIN/stat"
chmod +x "$APART_BIN/stat"
reset_docker
out=$(cd "$PROJECT" && PATH="$APART_BIN:$PATH" FAKE_EXEC_WRITE=brief TMPDIR="$WORK/tmp" kn export-agent-brief "$PROJECT" "--out=$WORK/outside/apart.md" </dev/null 2>&1); status=$?
expected="knossos: neither the install directory $INSTALL nor the directory around the graph directory $DATA is on the graph directory's filesystem, so the host cannot take the file out of every container's reach without copying it; nothing was written to $WORK/outside/apart.md. To fix it, put the graph directory on the same filesystem as its parent or as the install directory, by choosing another --data-dir (or KNOSSOS_DATA_DIR).
KNOSSOS_INVALID_ARGUMENT: Unable to write brief to $WORK/outside/apart.md."
if [ "$status" -eq 2 ] && [ "$out" = "$expected" ] && [ ! -e "$WORK/outside/apart.md" ] && [ -z "$(scratch_left)" ] && [ -z "$(taken_left)" ]; then
    pass 'an output the host cannot take out of reach without a copy is refused, saying how to fix it'
else
    fail "graph on its own filesystem: $status, $out"
fi
# A copy of the notes file in the way, left there by a command that said it exported: nothing was written, so it is a failure (M-3).
printf '{"format":"knossos-notes"}\n' > "$WORK/outside/kept.json"
FAKE_EXEC_SHAPE=untouched out_call export-notes "$PROJECT" "--file=$WORK/outside/kept.json"
if [ "$status" -eq 2 ] && [ "$out" = "knossos: the file in the way was left as it was; nothing was written to $WORK/outside/kept.json.
KNOSSOS_RUNTIME_ERROR: NOTES_FILE_WRITE_FAILED: could not write $WORK/outside/kept.json." ]; then
    pass 'export-notes that left the file in the way untouched is said as a failure, not as an export'
else
    fail "untouched notes copy: $status, $out"
fi
FAKE_EXEC_SHAPE=untouched out_call notes-export "$PROJECT" "--file=$WORK/outside/kept.json" --json
case $out in *'{"status":"refused","reason":"NOTES_FILE_WRITE_FAILED: could not write '"$WORK"'/outside/kept.json: the file in the way was left as it was;'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'notes-export that left it untouched without refusing answers refused'; else fail "untouched notes-export: $status, $out"; fi
rm -f "$WORK/outside/kept.json"
KNOSSOS_OUTPUT_LIMIT_MB=lots out_call export-bundle project_1 "--output=$WORK/outside/x.json"
if [ "$status" -eq 1 ] && [ "$out" = "knossos: KNOSSOS_OUTPUT_LIMIT_MB must be a whole number of MB above 0, got 'lots'." ]; then pass 'a limit that is not a number is refused'; else fail "bad limit: $status, $out"; fi
FAKE_EXEC_WRITE=x out_call export-bundle project_1 "--output=$DATA/bundle.json"
case $out in *'lies in the Knossos data directory, which a container can write'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ]; then pass 'a destination in the graph directory is refused'; else fail "output in graph: $status, $out"; fi
printf 'x\n' > "$OUT_IN"
FAKE_EXEC_WRITE=y out_call export-bundle project_1 "--output=$OUT_IN"
if [ "$status" -eq 2 ] && [ "$out" = "KNOSSOS_INVALID_ARGUMENT: Bundle output already exists: $OUT_IN" ] && [ "$(count_calls run)" -eq 0 ] && [ "$(cat "$OUT_IN")" = x ]; then
    pass 'export-bundle still refuses an existing output, before anything runs'
else
    fail "existing bundle: $status, $out"
fi
rm -f "$OUT_IN"

# export-notes and notes-export write knossos-notes.json in the project by default.
FAKE_EXEC_WRITE='{"format": "knossos-notes"}' out_call notes-export "$PROJECT" --json
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1) in *"[--file=$DATA/.knossos-files."*) via=scratch ;; *) via=other ;; esac
if [ "$status" -eq 0 ] && [ "$via" = scratch ] && [ -f "$PROJECT/knossos-notes.json" ]; then pass 'notes-export writes the project knossos-notes.json through the host'; else fail "notes-export default: $status, $out"; fi
# A file in the way is judged by the command, as in native mode: it gets a
# read-only copy at the path it writes, and what it leaves there is not
# written back.
printf '{"note": "quotes \\"format\\":\\"knossos-notes\\""}\n' > "$PROJECT/knossos-notes.json"
cp "$PROJECT/knossos-notes.json" "$WORK/quoting.json"
FAKE_EXEC_REFUSE=1 out_call notes-export "$PROJECT" --json
case $out in *"file:$PROJECT/knossos-notes.json:read-only:$(cat "$WORK/quoting.json")"*) copied=yes ;; *) copied=no ;; esac
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1) in *"[--file=$DATA/.knossos-files."*"/output]") ;; *) copied=no ;; esac
case $out in *'{"status":"refused","reason":"NOTES_FILE_NOT_NOTES: '"$PROJECT"'/knossos-notes.json exists and is not a knossos notes file; remove it or pick another --file."}') said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$copied" = yes ] && [ "$said" = yes ] && cmp -s "$PROJECT/knossos-notes.json" "$WORK/quoting.json" && [ -z "$(scratch_left)" ]; then
    pass 'a file in the way of a notes export is handed to the command read-only, which decides, and a refusal leaves it as it was'
else
    fail "notes-export over another file: $status, $out"
fi
inode_before=$(pin_of "$PROJECT/knossos-notes.json")
FAKE_EXEC_REFUSE=1 out_call export-notes "$PROJECT" --json
inode_after=$(pin_of "$PROJECT/knossos-notes.json")
if [ "$inode_before" = "$inode_after" ]; then pass 'a copy the command left in place is never written back over the file'; else fail "unchanged copy rewritten: $inode_before $inode_after"; fi
FAKE_EXEC_WRITE='{"format":"knossos-notes","notes":[]}' out_call export-notes "$PROJECT" --json
if [ "$status" -eq 0 ] && [ "$(cat "$PROJECT/knossos-notes.json")" = '{"format":"knossos-notes","notes":[]}' ]; then pass 'a notes file the command replaced is written over the one in place'; else fail "replaced notes: $status, $out"; fi
mkfifo "$WORK/outside/fifo.json"
FAKE_EXEC_WRITE=x out_call notes-export "$PROJECT" "--file=$WORK/outside/fifo.json" --json
if [ "$status" -eq 0 ] && [ "$out" = '{"status":"refused","reason":"NOTES_FILE_NOT_NOTES: '"$WORK"'/outside/fifo.json exists and is not a knossos notes file; remove it or pick another --file."}' ] && [ "$(count_calls run)" -eq 0 ]; then
    pass 'something in the way that is no plain file is refused as the command refuses it, before anything runs'
else
    fail "fifo in the way: $status, $out"
fi
rm -f "$PROJECT/knossos-notes.json" "$WORK/outside/fifo.json"
(cd "$PROJECT/src" && FAKE_EXEC_WRITE='{"format":"knossos-notes"}' TMPDIR="$WORK/tmp" kn export-notes </dev/null >/dev/null 2>&1)
if [ -f "$PROJECT/src/knossos-notes.json" ]; then pass 'export-notes without --file writes knossos-notes.json in the folder it runs from'; else fail 'export-notes default'; fi
rm -f "$PROJECT/src/knossos-notes.json"

# A file to read outside every mount is copied in, read-only; one inside is passed on.
printf 'bundle in\n' > "$WORK/outside/in.json"
out_call import-bundle "$WORK/outside/in.json"
case $out in *"file:$DATA/.knossos-files."*"/input-1:read-only:bundle in"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ -z "$(scratch_left)" ]; then pass 'an input outside every mount is copied into the scratch directory, read-only'; else fail "input outside: $status, $out"; fi
printf 'budgets\n' > "$PROJECT/budgets.json"
out_call quality-gate "$PROJECT" snap_1 --budgets=budgets.json
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1) in *"[--budgets=$PROJECT/budgets.json]"*) said=yes ;; *) said=no ;; esac
if [ "$said" = yes ]; then pass 'an input inside the project is passed on at its absolute path'; else fail "input inside: $(calls)"; fi
rm -f "$PROJECT/budgets.json"

# --db must lie in the graph directory; install-agent-plugin is the host's.
out_call scan "$PROJECT" "--db=$WORK/outside/other.sqlite"
case $out in *"--db names $WORK/outside/other.sqlite, outside the graph directory $DATA"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ]; then pass '--db outside the graph directory is refused'; else fail "db outside: $status, $out"; fi
out_call scan "$PROJECT" "--db=$DATA/other.sqlite"
if [ "$status" -eq 0 ]; then pass '--db inside the graph directory is accepted'; else fail "db inside: $status, $out"; fi
out_call install-agent-plugin --out=/tmp/x
case $out in *'its plugin is managed from the host'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls run)" -eq 0 ]; then pass 'install-agent-plugin is refused in container mode before anything runs'; else fail "plugin refused: $status, $out"; fi
# ---- the enricher list is the host's ----------------------------------------------------

ENRICHERS_RUN="call [run] [--rm] [--pull=never] [--network] [none] [--user] [$UID_GID] [-e] [HOME=/tmp] [-e] [KNOSSOS_DATA_DIR=/tmp/knossos-settings] [-e] [KNOSSOS_ROOTS_FILE=/tmp/knossos-settings/roots.json] [-e] [KNOSSOS_ENRICHERS_FILE=$INSTALL/settings/enrichers.json] [--mount] [type=bind,source=$INSTALL/settings,target=$INSTALL/settings] [--entrypoint] [/opt/knossos/bin/knossos] [$IMAGE]"
rm -rf "$INSTALL/settings"
# After the -- of enricher add, nothing is a file to place either.
out_call enricher add tool --timeout=5 -- tool --output=/nowhere/x
if [ "$status" -eq 0 ] && [ "$(calls)" = "$ENRICHERS_RUN [enricher] [add] [tool] [--timeout=5] [--] [tool] [--output=/nowhere/x]" ] && [ -d "$INSTALL/settings" ]; then
    pass 'enricher add is one run of the image with only the settings directory mounted read-write, no project, no network, and its arguments as given'
else
    fail "enricher add: $status, $out, $(calls)"
fi
out_call enricher --json remove tool
if [ "$status" -eq 0 ] && [ "$(calls)" = "$ENRICHERS_RUN [enricher] [--json] [remove] [tool]" ]; then pass 'enricher remove is the same single run'; else fail "enricher remove: $status, $out, $(calls)"; fi
out_call enricher add tool -- python3 "$DATA/tool.py"
case $out in "knossos: the enricher command names $DATA/tool.py, in the graph directory $DATA, which every container can write; put it in $INSTALL/settings, which containers only read, and add the enricher again. This check only catches a path given as a word of its own: an enricher must not read or run anything from the graph directory in any way.") said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then pass 'an enricher command in the graph directory is refused, and nothing runs'; else fail "enricher in graph: $status, $out, $(calls)"; fi
# A list planted in the graph directory, or named by the caller, is not what a scan reads.
printf '{"enrichers": [{"name": "planted", "command": ["sh", "-c", "id"]}]}\n' > "$DATA/enrichers.json"
reset_docker
(cd "$PROJECT" && KNOSSOS_ENRICHERS_FILE="$DATA/enrichers.json" TMPDIR="$WORK/tmp" kn scan "$PROJECT" </dev/null >/dev/null 2>&1); status=$?
exec_line=$(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1)
case $exec_line in *"[-e] [KNOSSOS_ENRICHERS_FILE=$INSTALL/settings/enrichers.json] "*) named=yes ;; *) named=no ;; esac
if [ "$status" -eq 0 ] && [ "$named" = yes ] && [ "$(grep -o 'KNOSSOS_ENRICHERS_FILE' "$FAKE_DOCKER_STATE/calls" | wc -l | tr -d ' ')" -eq 2 ] && [ "$(count_calls run)" -eq 1 ]; then
    pass "a scan is given the install directory's enricher list, never one in the graph directory or the caller's"
else
    fail "scan enrichers file: $status, $(calls)"
fi
rm -f "$DATA/enrichers.json"
out_call enricher list --json
case $(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1) in *"[/opt/knossos/bin/knossos] [enricher] [list] [--json]") said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'enricher list runs in the project container, which reads the list read-only'; else fail "enricher list: $status, $(calls)"; fi
ln -s "$WORK/outside" "$INSTALL/settings.link"
rm -rf "$INSTALL/settings"
mv "$INSTALL/settings.link" "$INSTALL/settings"
out_call enricher add tool -- tool
case $out in *"$INSTALL/settings is a symlink"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then pass 'a settings directory that is a symlink is refused'; else fail "settings symlink: $status, $out"; fi
rm -f "$INSTALL/settings"

# ---- the allow-list is the host's -------------------------------------------------------

TRUSTED="$INSTALL/roots.json"
cp "$TRUSTED" "$WORK/trusted.kept"
# A list in the graph directory, which every container can write, never chooses a mount.
mkdir -p "$WORK/wide/p1"
printf '{"roots": ["%s"]}\n' "$WORK/wide" > "$DATA/roots.json"
printf '{"roots": []}\n' > "$TRUSTED"
reset_docker
(cd "$WORK/wide/p1" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "[--mount] [type=bind,source=$WORK/wide/p1,target=$WORK/wide/p1,readonly]" "$FAKE_DOCKER_STATE/calls" && ! grep -qF "source=$WORK/wide," "$FAKE_DOCKER_STATE/calls"; then
    pass 'a roots entry written to the graph directory is ignored when the root is chosen'
else
    fail "graph roots file widened the mount: $(calls)"
fi
# What the plugin's hooks name, the graph's own roots.json, stands for the host's list.
reset_docker
(cd "$WORK/wide/p1" && KNOSSOS_ROOTS_FILE="$DATA/roots.json" env -u KNOSSOS_DATA_DIR "$LAUNCHER" version </dev/null >/dev/null 2>&1)
if grep -qF "[-e] [KNOSSOS_ROOTS_FILE=$TRUSTED]" "$FAKE_DOCKER_STATE/calls" && ! grep -qF "source=$WORK/wide," "$FAKE_DOCKER_STATE/calls"; then
    pass "KNOSSOS_ROOTS_FILE naming the graph's roots.json means the host's list, which the container is given"
else
    fail "graph roots.json named: $(calls)"
fi
# ... and the host never opens that name: a container can swap in a link to a
# file only you may read, or a FIFO that would hang the call.
printf 'SECRET-TOKEN\n' > "$HOME/.credential"
chmod 0600 "$HOME/.credential"
for planted in link fifo; do
    rm -f "$DATA/roots.json"
    if [ "$planted" = link ]; then ln -s "$HOME/.credential" "$DATA/roots.json"; else mkfifo "$DATA/roots.json"; fi
    for cmd in version find-component; do
        out=$(cd "$WORK/wide/p1" && KNOSSOS_ROOTS_FILE="$DATA/roots.json" env -u KNOSSOS_DATA_DIR timeout 20 "$LAUNCHER" "$cmd" . x </dev/null 2>&1); status=$?
        case $out in *SECRET-TOKEN*) leaked=yes ;; *) leaked=no ;; esac
        if [ "$status" -eq 0 ] && [ "$leaked" = no ]; then
            pass "a $planted at the graph's roots.json is never read on the host ($cmd): no hang, nothing of it printed"
        else
            fail "$planted at graph roots.json ($cmd): $status, $out"
        fi
    done
done
rm -f "$DATA/roots.json" "$HOME/.credential"
out=$(cd / && kn allow-root "$WORK/wide/p1" </dev/null 2>&1)
case $out in *"This container install reads only this list; a roots.json in the graph directory $DATA is ignored, so add roots with knossos allow-root.") said=yes ;; *) said=no ;; esac
if [ "$said" = yes ]; then pass "allow-root says that a roots.json in the graph directory is ignored"; else fail "allow-root note: $out"; fi
printf '{"roots": []}\n' > "$TRUSTED"
rm -f "$DATA/roots.json"
mkdir -p "$DATA/sub"
refused "a caller's KNOSSOS_ROOTS_FILE in the graph directory" "KNOSSOS_ROOTS_FILE ($DATA/sub/roots.json) lies in the graph directory $DATA, which a container can write; unset it to use the list the installer keeps in $INSTALL/roots.json, or point it at a file outside every directory a container can write." \
    sh -c 'cd "$1" && KNOSSOS_ROOTS_FILE="$2/sub/roots.json" env -u KNOSSOS_DATA_DIR "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
# A link a container planted in the graph directory is never followed to create the roots file's directory.
mkdir -p "$WORK/escaped to"
ln -s "$WORK/escaped to" "$DATA/escape"
refused "a caller's KNOSSOS_ROOTS_FILE through a link in the graph directory" "KNOSSOS_ROOTS_FILE ($DATA/escape/new/roots.json) lies in the graph directory $DATA, which a container can write" \
    sh -c 'cd "$1" && KNOSSOS_ROOTS_FILE="$2/escape/new/roots.json" env -u KNOSSOS_DATA_DIR "$3" version </dev/null' _ "$PROJECT" "$DATA" "$LAUNCHER"
if [ ! -e "$WORK/escaped to/new" ]; then pass 'and nothing is created at the end of that link'; else fail 'a directory was created through a link in the graph directory'; fi
rm -f "$DATA/escape"
refused "a caller's KNOSSOS_ROOTS_FILE inside the project" "KNOSSOS_ROOTS_FILE ($PROJECT/src/roots.json) lies inside the project root $PROJECT, whose files the scanned project controls" \
    sh -c 'cd "$1" && KNOSSOS_ROOTS_FILE="$1/src/roots.json" env -u KNOSSOS_DATA_DIR "$2" version </dev/null' _ "$PROJECT" "$LAUNCHER"

# ---- a data directory is never reached through a link a container planted ---------------

# The default graph directory ($DATA) is read-write in every container of the
# default setup, so a link there may lead anywhere by the next call: to a
# folder of yours, which would be mounted read-write, or to one that does not
# exist yet, which the host would create.
ESCAPE="$HOME/escape"
mkdir -p "$ESCAPE"
printf 'keep\n' > "$ESCAPE/sentinel"
escape_intact() { [ "$(cat "$ESCAPE/sentinel" 2>/dev/null)" = keep ] && [ "$(ls -A "$ESCAPE")" = sentinel ]; }
data_call() { sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$2" env -u KNOSSOS_ROOTS_FILE "$3" version </dev/null' _ "$PROJECT" "$1" "$LAUNCHER"; }
ln -s "$ESCAPE/new graph" "$DATA/work"
refused 'a KNOSSOS_DATA_DIR whose last component is a link planted in the default graph directory, to a folder that does not exist' \
    "KNOSSOS_DATA_DIR ($DATA/work) lies in the graph directory $DATA, which a container can write, and goes through $DATA/work there, a symbolic link the host does not follow; pick a data directory outside $DATA." \
    data_call "$DATA/work"
if [ ! -e "$ESCAPE/new graph" ] && escape_intact; then pass 'and nothing is created where that link points'; else fail "created through a planted last component: $(ls -A "$ESCAPE")"; fi
rm -f "$DATA/work"
ln -s "$ESCAPE" "$DATA/work"
refused 'a KNOSSOS_DATA_DIR whose last component is a link planted in the default graph directory, to a folder of yours' \
    "KNOSSOS_DATA_DIR ($DATA/work) lies in the graph directory $DATA, which a container can write, and goes through $DATA/work there" \
    data_call "$DATA/work"
if escape_intact; then pass 'and that folder is neither mounted nor changed'; else fail "folder behind a planted link changed: $(ls -A "$ESCAPE")"; fi
rm -f "$DATA/work"
ln -s "$ESCAPE" "$DATA/mid"
refused 'a KNOSSOS_DATA_DIR whose middle component is a link planted in the default graph directory' \
    "KNOSSOS_DATA_DIR ($DATA/mid/work) lies in the graph directory $DATA, which a container can write, and goes through $DATA/mid there, a symbolic link the host does not follow; pick a data directory outside $DATA." \
    data_call "$DATA/mid/work"
if [ ! -e "$ESCAPE/work" ] && escape_intact; then pass 'and nothing is created below where that link points'; else fail "created through a planted middle component: $(ls -A "$ESCAPE")"; fi
rm -f "$DATA/mid"
mkdir -p "$DATA/second"
refused 'a KNOSSOS_DATA_DIR inside the default graph directory' \
    "KNOSSOS_DATA_DIR ($DATA/second) lies in the graph directory $DATA, which a container can write; pick a data directory outside $DATA." \
    data_call "$DATA/second"
refused 'a KNOSSOS_DATA_DIR inside the default graph directory that does not exist yet' \
    "KNOSSOS_DATA_DIR ($DATA/not yet) lies in the graph directory $DATA, which a container can write; pick a data directory outside $DATA." \
    data_call "$DATA/not yet"
if [ ! -e "$DATA/not yet" ]; then pass 'and it is not created'; else fail "$DATA/not yet was created"; fi
# A link of yours that leads into the default graph directory changes nothing.
ln -s "$DATA" "$WORK/to data"
refused 'a KNOSSOS_DATA_DIR that a link of yours leads into the default graph directory' \
    "KNOSSOS_DATA_DIR ($WORK/to data/second) lies in the graph directory $DATA, which a container can write; pick a data directory outside $DATA." \
    data_call "$WORK/to data/second"
ln -s "$DATA/second" "$WORK/to second"
refused 'a KNOSSOS_DATA_DIR that is a link of yours to a folder in the default graph directory' \
    "KNOSSOS_DATA_DIR ($WORK/to second) lies in the graph directory $DATA, which a container can write; pick a data directory outside $DATA." \
    data_call "$WORK/to second"
rm -rf "$DATA/second" "$WORK/to data" "$WORK/to second"
# Outside it, through a link you made above it: works, and the physical path is used.
mkdir -p "$WORK/person target"
ln -s "$WORK/person target" "$WORK/person link"
SECOND="$WORK/person target/graph two"
reset_docker
out=$(data_call "$WORK/person link/graph two" 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qF "[--mount] [type=bind,source=$SECOND,target=$SECOND]" "$FAKE_DOCKER_STATE/calls" \
    && [ "$(cat "$INSTALL/containers/graph-$(hash12 "$SECOND")" 2>/dev/null)" = "$SECOND" ]; then
    pass 'a KNOSSOS_DATA_DIR outside the default graph, reached through a link of yours, is created, mounted at its physical path and recorded'
else
    fail "data dir through your own link: $status, $out, $(calls)"
fi
# Once used, that graph directory counts as one a container can write.
mkdir -p "$SECOND/inner"
refused 'a KNOSSOS_DATA_DIR inside another graph directory this installation has used' \
    "KNOSSOS_DATA_DIR ($SECOND/inner) lies in the graph directory $SECOND, which a container can write; pick a data directory outside $SECOND." \
    data_call "$SECOND/inner"
ln -s "$ESCAPE" "$SECOND/planted"
refused 'a KNOSSOS_DATA_DIR through a link planted in another graph directory this installation has used' \
    "KNOSSOS_DATA_DIR ($SECOND/planted/x) lies in the graph directory $SECOND, which a container can write, and goes through $SECOND/planted there" \
    data_call "$SECOND/planted/x"
if [ ! -e "$ESCAPE/x" ] && escape_intact; then pass 'and nothing is created there either'; else fail "created through a link in a used graph: $(ls -A "$ESCAPE")"; fi
refused "a caller's KNOSSOS_ROOTS_FILE in another graph directory this installation has used" \
    "KNOSSOS_ROOTS_FILE ($SECOND/roots.json) lies in the graph directory $SECOND, which a container can write" \
    sh -c 'cd "$1" && KNOSSOS_ROOTS_FILE="$2/roots.json" env -u KNOSSOS_DATA_DIR "$3" version </dev/null' _ "$PROJECT" "$SECOND" "$LAUNCHER"
refused 'an --out in another graph directory this installation has used' \
    "$SECOND/brief.md lies in the graph directory $SECOND, which a container can write; write it somewhere else." \
    sh -c 'cd "$1" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" export-agent-brief "$1" "--out=$3/brief.md" </dev/null' _ "$PROJECT" "$LAUNCHER" "$SECOND"
printf 'SECRET-INPUT\n' > "$ESCAPE/policies.json"
refused 'an input file reached through a link planted in the graph directory' \
    "the directory of $SECOND/planted/policies.json lies in the graph directory $SECOND, which a container can write, and goes through $SECOND/planted there" \
    sh -c 'cd "$1" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" quality-gate "--policies=$3/planted/policies.json" </dev/null' _ "$PROJECT" "$LAUNCHER" "$SECOND"
rm -f "$ESCAPE/policies.json"
mkdir -p "$DATA/tmp"
refused 'a TMPDIR in the graph directory' \
    "TMPDIR ($DATA/tmp) lies in the graph directory $DATA, which a container can write; point TMPDIR at a directory outside it." \
    sh -c 'cd "$1" && TMPDIR="$3/tmp" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" export-agent-brief "$1" "--out=$4/brief.md" </dev/null' _ "$PROJECT" "$LAUNCHER" "$DATA" "$WORK/outside"
rm -rf "$DATA/tmp" "$SECOND/planted" "$WORK/person link" "$WORK/person target"
find "$INSTALL/containers" -name 'graph-*' -exec rm -f {} +
# A project root reached through a link planted in the graph directory is not mounted.
ln -s "$ESCAPE" "$DATA/proj"
refused 'a project path through a link planted in the graph directory' \
    "the project root ($DATA/proj) lies in the graph directory $DATA, which a container can write, and goes through $DATA/proj there" \
    sh -c 'cd / && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$1" scan "$2/proj" </dev/null' _ "$LAUNCHER" "$DATA"
rm -f "$DATA/proj"
rm -rf "$ESCAPE"

# ---- a project inside a graph directory is never mounted ----------------------------------

# Its own container would see it read-only, but every other container of the
# graph writes it through the graph mount.
mkdir -p "$DATA/projects/inside"
refused 'a project root inside the default graph directory' \
    "the project root $DATA/projects/inside lies in the graph directory $DATA, which every container of that graph can write, so it would not stay read-only; keep projects outside the Knossos data directory, or pick another --data-dir." \
    sh -c 'cd "$1" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" version </dev/null' _ "$DATA/projects/inside" "$LAUNCHER"
reset_docker
out=$(cd / && kn allow-root "$DATA/projects/inside" --execute </dev/null 2>&1); status=$?
case $out in *"the project root $DATA/projects/inside lies in the graph directory $DATA"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && ! grep -qF "$DATA/projects/inside" "$INSTALL/roots.json"; then pass 'allow-root refuses a project inside a graph directory and records nothing'; else fail "allow-root inside graph: $status, $out"; fi
rm -rf "$DATA/projects"

# ---- graph records: named in a refusal, and dropped once their directory is gone ----------

OLD_GRAPH="$WORK/old graph"
reset_docker
(cd "$PROJECT" && KNOSSOS_DATA_DIR="$OLD_GRAPH" env -u KNOSSOS_ROOTS_FILE "$LAUNCHER" version </dev/null >/dev/null 2>&1)
OLD_RECORD="$INSTALL/containers/graph-$(hash12 "$OLD_GRAPH")"
if [ -f "$OLD_RECORD" ]; then pass 'a graph directory is recorded at its first use'; else fail "no record for $OLD_GRAPH"; fi
refused 'an output in a recorded graph directory, naming the record' \
    "$OLD_GRAPH/brief.md lies in the graph directory $OLD_GRAPH, which a container can write; write it somewhere else. $OLD_RECORD records that this installation used $OLD_GRAPH as a graph directory; if it no longer is one, remove that file to lift this refusal." \
    sh -c 'cd "$1" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" export-agent-brief "$1" "--out=$3/brief.md" </dev/null' _ "$PROJECT" "$LAUNCHER" "$OLD_GRAPH"
# Still there without its database: kept, as a container of that graph could have deleted it.
rm -f "$OLD_GRAPH/knossos.sqlite"
reset_docker
(cd "$PROJECT" && TMPDIR="$WORK/tmp" FAKE_EXEC_WRITE=b kn export-agent-brief "$PROJECT" "--out=$OLD_GRAPH/brief.md" </dev/null >/dev/null 2>&1); status=$?
if [ "$status" -eq 1 ] && [ -f "$OLD_RECORD" ] && [ ! -e "$OLD_GRAPH/brief.md" ]; then pass 'a record whose directory still exists is kept, database or not'; else fail "record kept: $status"; fi
# Gone: the record goes at the next call, and the folder made again later is an ordinary one.
rm -rf "$OLD_GRAPH"
reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
mkdir -p "$OLD_GRAPH"
reset_docker
(cd "$PROJECT" && TMPDIR="$WORK/tmp" FAKE_EXEC_WRITE=b kn export-agent-brief "$PROJECT" "--out=$OLD_GRAPH/brief.md" </dev/null >/dev/null 2>&1); status=$?
if [ ! -e "$OLD_RECORD" ] && [ "$status" -eq 0 ] && [ "$(cat "$OLD_GRAPH/brief.md" 2>/dev/null)" = b ]; then pass 'a record whose directory is gone is dropped, and that path is ordinary again'; else fail "stale record: $status, $(ls "$INSTALL/containers")"; fi
rm -rf "$OLD_GRAPH"

# ---- only a real container install's record makes a directory a graph -------------------

# A repository that happens to hold install/containers and install/install.json.
LOOKALIKE="$WORK/lookalike repo"
mkdir -p "$LOOKALIKE/install/containers"
printf '{"name": "some-package", "scripts": {"install": "make"}}\n' > "$LOOKALIKE/install/install.json"
reset_docker
(cd "$PROJECT" && TMPDIR="$WORK/tmp" FAKE_EXEC_WRITE=c kn export-agent-brief "$PROJECT" "--out=$LOOKALIKE/brief.md" </dev/null >/dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$(cat "$LOOKALIKE/brief.md" 2>/dev/null)" = c ]; then pass 'a folder with install/containers and an install.json that is no Knossos record is not a graph'; else fail "lookalike treated as a graph: $status"; fi
printf '{"mode": "container", "layout": "separate"}\n' > "$LOOKALIKE/install/install.json"
refused 'a folder holding a container install record' "$LOOKALIKE/brief2.md lies in the graph directory $LOOKALIKE" \
    sh -c 'cd "$1" && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$2" export-agent-brief "$1" "--out=$3/brief2.md" </dev/null' _ "$PROJECT" "$LAUNCHER" "$LOOKALIKE"
rm -f "$LOOKALIKE/install/install.json"
mkfifo "$LOOKALIKE/install/install.json"
reset_docker
out=$(cd "$PROJECT" && TMPDIR="$WORK/tmp" FAKE_EXEC_WRITE=d timeout 20 env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" export-agent-brief "$PROJECT" "--out=$LOOKALIKE/brief3.md" </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'an install.json that is a FIFO is never opened'; else fail "fifo record: $status, $out"; fi
rm -rf "$LOOKALIKE"

# allow-root: validated and written on the host; no container runs for it.
mkdir -p "$WORK/second project"
reset_docker
out=$(cd / && kn allow-root "$WORK/second project" --json </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$out" = "{\"path\":\"$WORK/second project\",\"roots_file\":\"$TRUSTED\",\"roots_file_source\":\"named\",\"added\":false,\"preview\":true}" ] \
    && ! grep -qF "$WORK/second project" "$TRUSTED" && [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then
    pass 'allow-root previews on the host, writes nothing and runs no container'
else
    fail "allow-root preview: $status, $out, $(calls)"
fi
out=$(cd / && kn allow-root "$WORK/second project/" --execute --json </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$out" = "{\"path\":\"$WORK/second project\",\"roots_file\":\"$TRUSTED\",\"roots_file_source\":\"named\",\"added\":true}" ] \
    && grep -qxF "        \"$WORK/second project\"" "$TRUSTED" && [ ! -s "$FAKE_DOCKER_STATE/calls" ] && [ ! -e "$TRUSTED.lock.d" ]; then
    pass 'allow-root --execute adds the root to the list in the install directory, on the host'
else
    fail "allow-root execute: $status, $out, $(cat "$TRUSTED"), $(calls)"
fi
out=$(cd / && kn allow-root "$WORK/second project" --execute </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$out" = "$WORK/second project is already present in $TRUSTED. Nothing to do." ]; then pass 'allow-root of a listed root says it is already present'; else fail "allow-root present: $status, $out"; fi
reset_docker
(cd "$WORK/second project" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "io.knossos.root=$(hash12 "$WORK/second project")" "$FAKE_DOCKER_STATE/calls"; then pass 'the granted root is the project root of a call from inside it'; else fail "granted root unused: $(calls)"; fi
out=$(cd / && kn allow-root "$HOME" --execute </dev/null 2>&1); status=$?
case $out in *"the project root $HOME is your home directory, which container mode does not mount"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && ! grep -qF "\"$HOME\"" "$TRUSTED"; then pass 'allow-root refuses a root the mount rule refuses'; else fail "allow-root home: $status, $out"; fi
out=$(cd / && kn allow-root "$WORK/no such project" --execute </dev/null 2>&1); status=$?
if [ "$status" -eq 2 ] && [ "$out" = "KNOSSOS_INVALID_ARGUMENT: $WORK/no such project is not an existing directory." ]; then pass 'allow-root of a missing folder answers as in native mode'; else fail "allow-root missing: $status, $out"; fi
# --db in the graph directory is accepted by allow-root and claim, elsewhere refused, on the host.
out=$(cd / && kn allow-root "$WORK/second project" "--db=$DATA/knossos.sqlite" </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$out" = "$WORK/second project is already present in $TRUSTED. Nothing to do." ]; then pass 'allow-root --db= in the graph directory is answered on the host'; else fail "allow-root --db: $status, $out"; fi
out=$(cd / && kn claim "$WORK/second project" "--db=$DATA/knossos.sqlite" --json </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && [ "$out" = "{\"status\":\"present\",\"root\":\"$WORK/second project\",\"reason\":null}" ]; then pass 'claim --db= in the graph directory is answered on the host'; else fail "claim --db: $status, $out"; fi
out=$(cd / && kn allow-root "$WORK/second project" "--db=$WORK/outside/x.sqlite" </dev/null 2>&1); status=$?
case $out in *"--db names $WORK/outside/x.sqlite, outside the graph directory $DATA"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ]; then pass 'allow-root --db= outside the graph directory is refused'; else fail "allow-root --db outside: $status, $out"; fi

# A list with an entry this launcher cannot reproduce is never rewritten.
cp "$TRUSTED" "$WORK/list.escaped"
printf '{"roots": ["/x\\u00e9"]}\n' > "$TRUSTED"
cp "$TRUSTED" "$WORK/list.escaped.before"
out=$(cd / && kn allow-root "$WORK/second project" --execute </dev/null 2>&1); status=$?
case $out in *'holds an entry with an escape this launcher cannot rewrite safely, or cannot be read; edit it by hand.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 2 ] && [ "$said" = yes ] && cmp -s "$TRUSTED" "$WORK/list.escaped.before"; then pass 'a list holding a \u escape is refused, not rewritten'; else fail "escaped list: $status, $out, $(cat "$TRUSTED")"; fi
cp "$WORK/list.escaped" "$TRUSTED"
# A list the server cannot read (a raw tab in a string is invalid JSON) chooses no root and is never rewritten.
printf '{"roots": ["%s", "/x\ty"]}\n' "$WORK/wide" > "$TRUSTED"
cp "$TRUSTED" "$WORK/list.broken.before"
out=$(cd / && kn allow-root "$PROJECT" --execute </dev/null 2>&1); status=$?
case $out in *'cannot rewrite safely, or cannot be read; edit it by hand.'*) said=yes ;; *) said=no ;; esac
reset_docker
(cd "$WORK/wide/p1" && kn version </dev/null >/dev/null 2>&1)
if [ "$status" -eq 2 ] && [ "$said" = yes ] && cmp -s "$TRUSTED" "$WORK/list.broken.before" && grep -qF "source=$WORK/wide/p1," "$FAKE_DOCKER_STATE/calls" && ! grep -qF "source=$WORK/wide," "$FAKE_DOCKER_STATE/calls"; then
    pass 'a list holding a raw control character is read as no roots, as the server reads it, and never rewritten'
else
    fail "broken list: $status, $out, $(calls)"
fi
cp "$WORK/list.escaped" "$TRUSTED"
# Only the roots array grants: a note beside it that looks like a path is not a root.
printf '{"roots": [], "_note": "%s", "meta": {"where": "%s"}}\n' "$WORK/wide" "$WORK/wide" > "$TRUSTED"
reset_docker
(cd "$WORK/wide/p1" && kn version </dev/null >/dev/null 2>&1)
if grep -qF "source=$WORK/wide/p1," "$FAKE_DOCKER_STATE/calls" && ! grep -qF "source=$WORK/wide," "$FAKE_DOCKER_STATE/calls"; then
    pass 'a string under another key of the allow-list is never taken for a root'
else
    fail "note taken for a root: $(calls)"
fi
cp "$WORK/list.escaped" "$TRUSTED"
# A name the list cannot hold is refused before anything is written.
TAB_DIR="$WORK/tab$(printf '\t')name"
mkdir -p "$TAB_DIR" "$WORK/bad$(printf '\377')byte"
cp "$TRUSTED" "$WORK/list.names.before"
for bad in "$TAB_DIR" "$WORK/bad$(printf '\377')byte"; do
    out=$(cd / && kn allow-root "$bad" --execute </dev/null 2>&1); status=$?
    if [ "$status" -eq 2 ] && [ "$out" = "KNOSSOS_INVALID_ARGUMENT: this folder's name holds a control character (such as a tab or a newline) or bytes that are not valid UTF-8, so it cannot be recorded in the allow-list; rename the folder and try again." ] && cmp -s "$TRUSTED" "$WORK/list.names.before"; then
        pass 'allow-root refuses a folder name with a control character or bytes that are not UTF-8, and writes nothing'
    else
        fail "unrecordable name: $status, $out"
    fi
done
out=$(cd / && kn claim "$TAB_DIR" --execute --json </dev/null 2>&1)
case $out in '{"status":"error","root":"'"$WORK"'/tab\tname","reason":"this folder'"'"'s name holds a control character'*) said=yes ;; *) said=no ;; esac
if [ "$said" = yes ] && cmp -s "$TRUSTED" "$WORK/list.names.before"; then pass 'claim answers error for such a name, as valid JSON, and writes nothing'; else fail "claim unrecordable: $out"; fi
# White space at the ends is trimmed, as the server trims it: `/p ` and `/p` are one root.
mkdir -p "$WORK/spaced" "$WORK/spaced "
out=$(cd / && kn allow-root "$WORK/spaced " --execute --json </dev/null 2>&1); status=$?
out2=$(cd / && kn allow-root "$WORK/spaced" --execute </dev/null 2>&1)
if [ "$status" -eq 0 ] && [ "$out" = "{\"path\":\"$WORK/spaced\",\"roots_file\":\"$TRUSTED\",\"roots_file_source\":\"named\",\"added\":true}" ] \
    && [ "$out2" = "$WORK/spaced is already present in $TRUSTED. Nothing to do." ] && [ "$(grep -c spaced "$TRUSTED")" -eq 1 ]; then
    pass 'a trailing space is trimmed as the server trims it, so both spellings are one root'
else
    fail "trimmed root: $status, $out, $out2, $(cat "$TRUSTED")"
fi
cp "$WORK/list.escaped" "$TRUSTED"

# claim: the folder's repository, as StartClaim decides, written on the host.
mkdir -p "$WORK/repo/.git" "$WORK/repo/pkg/sub"
reset_docker
out=$(cd / && kn claim "$WORK/repo/pkg/sub" --json </dev/null 2>&1)
if [ "$out" = "{\"status\":\"would-add\",\"root\":\"$WORK/repo\",\"reason\":null}" ]; then pass 'claim without --execute answers would-add with the repository root'; else fail "claim preview: $out"; fi
out=$(cd / && kn claim "$WORK/repo/pkg/sub" --execute --json </dev/null 2>&1)
if [ "$out" = "{\"status\":\"added\",\"root\":\"$WORK/repo\",\"reason\":null}" ] && grep -qxF "        \"$WORK/repo\"" "$TRUSTED" && [ ! -s "$FAKE_DOCKER_STATE/calls" ]; then
    pass 'claim --execute adds the repository root on the host, with no container'
else
    fail "claim execute: $out, $(cat "$TRUSTED")"
fi
out=$(cd / && kn claim "$WORK/repo/pkg/sub" --execute --json </dev/null 2>&1)
if [ "$out" = "{\"status\":\"present\",\"root\":\"$WORK/repo\",\"reason\":null}" ]; then pass 'claim of a claimed repository answers present'; else fail "claim present: $out"; fi
out=$(cd / && kn claim "$HOME" --execute --json </dev/null 2>&1)
case $out in "{\"status\":\"ask\",\"root\":\"$HOME\",\"reason\":\"$HOME is the filesystem root, your home folder or a folder above it, so it is not allowed without being asked.\"}") said=yes ;; *) said=no ;; esac
if [ "$said" = yes ]; then pass 'claim of the home folder asks first'; else fail "claim home: $out"; fi
# Without a home folder, claim asks, as ClaimCommand does when neither HOME nor the account database names one.
NOGETENT="$WORK/no getent"
mkdir -p "$NOGETENT"
for tool in env sh awk sed cut sha256sum shasum mkdir rmdir dirname id date sort stat ls grep mktemp chmod mv rm cat head tr docker; do
    found=$(PATH="$FAKE_BIN:$PATH" command -v "$tool" 2>/dev/null || true)
    case $found in /*) ln -s "$found" "$NOGETENT/$tool" ;; esac
done
cp "$TRUSTED" "$WORK/list.home.before"
mkdir -p "$WORK/unclaimed"
out=$(cd / && env -u HOME -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE PATH="$NOGETENT" "$LAUNCHER" claim "$WORK/unclaimed" --execute --json </dev/null 2>&1); status=$?
case $out in '{"status":"ask",'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && cmp -s "$TRUSTED" "$WORK/list.home.before"; then pass 'claim with HOME unset and no account home asks, and adds nothing'; else fail "claim without HOME: $status, $out"; fi
out=$(cd / && HOME="$WORK/no such home" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" claim "$WORK/unclaimed" --execute --json </dev/null 2>&1); status=$?
case $out in '{"status":"ask",'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && cmp -s "$TRUSTED" "$WORK/list.home.before"; then pass 'claim with a HOME that is not a folder asks'; else fail "claim with a missing HOME: $status, $out"; fi

# move-project --execute: the host swaps the old root for the new one; a failed move puts it back.
mkdir -p "$WORK/moved to"
write_list() { printf '{"roots": ["%s"]}\n' "$1" > "$TRUSTED"; }
write_list "$WORK/moved from"
reset_docker
(cd "$WORK/moved to" && kn move-project "$WORK/moved from" "$WORK/moved to" --execute </dev/null >/dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qF "\"$WORK/moved to\"" "$TRUSTED" && ! grep -qF "\"$WORK/moved from\"" "$TRUSTED"; then
    pass 'move-project --execute swaps the root in the host list before the command runs'
else
    fail "move-project swap: $status, $(cat "$TRUSTED")"
fi
write_list "$WORK/moved from"
cp "$TRUSTED" "$WORK/list.before"
(cd "$WORK/moved to" && FAKE_EXEC_EXIT=3 kn move-project "$WORK/moved from" "$WORK/moved to" --execute </dev/null >/dev/null 2>&1); status=$?
if [ "$status" -eq 3 ] && cmp -s "$WORK/list.before" "$TRUSTED"; then pass 'a failed move-project puts the host list back as it was'; else fail "move-project restore: $status, $(cat "$TRUSTED")"; fi
# The launcher itself gives up after the swap (here: no daemon to start the container).
reset_docker
: > "$FAKE_DOCKER_STATE/daemon-down"
out=$(cd "$WORK/moved to" && kn move-project "$WORK/moved from" "$WORK/moved to" --execute </dev/null 2>&1); status=$?
rm -f "$FAKE_DOCKER_STATE/daemon-down"
if [ "$status" -eq 1 ] && cmp -s "$WORK/list.before" "$TRUSTED"; then pass 'a move-project the launcher gives up on after the swap puts the host list back too'; else fail "move-project refusal restore: $status, $out, $(cat "$TRUSTED")"; fi
out=$(cd "$WORK/moved to" && kn move-project project_abc "$WORK/moved to" --execute </dev/null 2>&1); status=$?
case $out in *'names the old root by its path'*) pass 'move-project --execute from a project id is refused, the list cannot follow it' ;; *) fail "move-project id: $status, $out" ;; esac
cp "$WORK/trusted.kept" "$TRUSTED"

# ---- a path with a double quote is mounted as one CSV field --------------------------------

SAID="$WORK/say \"hi\" there"
mkdir -p "$SAID"
reset_docker
(cd "$SAID" && kn version </dev/null >/dev/null 2>&1); status=$?
(cd "$SAID" && kn version </dev/null >/dev/null 2>&1); status2=$?
SAID_CSV=$(printf '%s' "$SAID" | sed 's/"/""/g')
if [ "$status" -eq 0 ] && [ "$status2" -eq 0 ] && grep -qF "[--mount] [type=bind,\"source=$SAID_CSV\",\"target=$SAID_CSV\",readonly]" "$FAKE_DOCKER_STATE/calls" && [ "$(count_calls run)" -eq 1 ]; then
    pass 'a project whose path holds a double quote is mounted through a quoted CSV field, and its container is reused'
else
    fail "quoted path: $status $status2, $(calls)"
fi

# ---- a root with a colon is never passed as an extra allowed root ------------------------

mkdir -p "$WORK/c:olon/proj"
ln -s "$WORK/c:olon/proj" "$WORK/colon link"
printf '{"roots": ["%s"]}\n' "$WORK/colon link" > "$INSTALL/roots.json"
reset_docker
out=$(cd "$WORK/c:olon/proj" && kn version </dev/null 2>&1); status=$?
exec_line=$(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls" | head -n 1)
if [ "$status" -eq 0 ] && grep -qF "[--mount] [type=bind,source=$WORK/c:olon/proj,target=$WORK/c:olon/proj,readonly]" "$FAKE_DOCKER_STATE/calls"; then
    case $exec_line in
        *KNOSSOS_ALLOWED_ROOTS*) fail "a root with a colon was passed in KNOSSOS_ALLOWED_ROOTS: $exec_line" ;;
        *) pass 'a root with a colon, listed through a symlink, is mounted but not added to KNOSSOS_ALLOWED_ROOTS' ;;
    esac
else
    fail "colon root: $status, $out"
fi
printf '{\n    "roots": [\n        "%s"\n    ]\n}\n' "$PROJECT" > "$INSTALL/roots.json"

# ---- install.json problems -------------------------------------------------------------

printf '{"mode": "container", "layout": "separate", "runtime": "docker"}\n' > "$INSTALL/install.json"
out=$(cd "$PROJECT" && kn version 2>&1); status=$?
case $out in *'names no image'*) pass 'a record without an image says to re-run the installer' ;; *) fail "no image: $status $out" ;; esac
printf '{"mode": "container", "layout": "separate", "image": "x", "runtime": "lxc"}\n' > "$INSTALL/install.json"
out=$(cd "$PROJECT" && kn version 2>&1); status=$?
case $out in *"unknown container runtime 'lxc'"*) pass 'an unknown runtime is refused' ;; *) fail "unknown runtime: $status $out" ;; esac
printf '{"mode":"container","layout":"separate","image":"%s"}' "$IMAGE" > "$INSTALL/install.json"
reset_docker
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -q '^call \[run\]' "$FAKE_DOCKER_STATE/calls"; then pass 'a compact record without a runtime means docker'; else fail "compact record: $status $out"; fi
write_record "$IMAGE"
# A PATH with the tools the launcher uses but no docker: never the system's
# PATH, which may hold a real docker this test must not reach.
NODOCKER="$WORK/no docker"
mkdir -p "$NODOCKER"
for tool in env sh awk sed cut sha256sum shasum mkdir dirname id date sort stat ls; do
    found=$(command -v "$tool" 2>/dev/null || true)
    case $found in /*) ln -s "$found" "$NODOCKER/$tool" ;; esac
done
out=$(cd "$PROJECT" && PATH="$NODOCKER" kn version 2>&1); status=$?
case $out in *'docker is not installed'*) pass 'a missing runtime binary is named' ;; *) fail "missing docker: $status $out" ;; esac

# ---- stop goes through the roots-file check every other command gets --------------------

# A roots file a container can write is never opened for stop: not a FIFO
# planted there (which would hang it), not a link (which would be followed).
mkdir -p "$DATA/r"
mkfifo "$DATA/r/roots.json"
printf '{"roots":["%s"]}\n' "$PROJECT" > "$HOME/secret.json"
chmod 600 "$HOME/secret.json"
ln -s "$HOME/secret.json" "$DATA/r/s.json"
# unblock FIFO: let a reader still stuck on FIFO go, should one be.
unblock() { (printf '' > "$1") 2>/dev/null & sleep 1; kill "$!" 2>/dev/null; wait "$!" 2>/dev/null; }
stop_with_roots() { sh -c 'cd "$1" && KNOSSOS_ROOTS_FILE="$2" timeout 10 env -u KNOSSOS_DATA_DIR "$3" stop </dev/null' _ "$PROJECT/src" "$1" "$LAUNCHER"; }
reset_docker
out=$(stop_with_roots "$DATA/r/roots.json" 2>&1); status=$?
case $out in *"KNOSSOS_ROOTS_FILE ($DATA/r/roots.json) lies in the graph directory $DATA, which a container can write;"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls ps)" -eq 0 ]; then
    pass 'stop with a roots file in the graph directory is refused without opening it (a FIFO there does not hang it)'
else
    fail "stop with a planted FIFO as roots file: status $status, $out, $(calls)"
    unblock "$DATA/r/roots.json"
fi
reset_docker
out=$(stop_with_roots "$DATA/r/s.json" 2>&1); status=$?
case $out in *"KNOSSOS_ROOTS_FILE ($DATA/r/s.json) lies in the graph directory $DATA, which a container can write;"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$said" = yes ] && [ "$(count_calls ps)" -eq 0 ]; then
    pass 'stop with a roots file that is a link planted in the graph directory is refused, and the link is not followed'
else
    fail "stop with a planted link as roots file: status $status, $out, $(calls)"
fi
# Another spelling of the graph's roots.json stands for the host's list, as for every command.
mkfifo "$DATA/roots.json"
reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
out=$(stop_with_roots "$DATA/./roots.json" 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qF "[--filter] [label=io.knossos.root=$(hash12 "$PROJECT")]" "$FAKE_DOCKER_STATE/calls"; then
    pass "stop with <data>/./roots.json uses the host's list and never opens the file in the graph directory"
else
    fail "stop with another spelling of the graph roots.json: status $status, $out, $(calls)"
    unblock "$DATA/roots.json"
fi
rm -rf "$DATA/r" "$DATA/roots.json" "$HOME/secret.json"
# A roots file outside every graph directory is read for stop, as for every command.
OUT_ROOTS="$WORK/outside roots/roots.json"
mkdir -p "$(dirname "$OUT_ROOTS")"
printf '{"roots":["%s"]}\n' "$PROJECT" > "$OUT_ROOTS"
reset_docker
out=$(stop_with_roots "$OUT_ROOTS" 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qF "[--filter] [label=io.knossos.root=$(hash12 "$PROJECT")]" "$FAKE_DOCKER_STATE/calls"; then
    pass 'stop reads a roots file you named outside every graph directory'
else
    fail "stop with your own roots file: status $status, $out, $(calls)"
fi
rm -rf "$WORK/outside roots"

# ---- stop: a container already gone is stopped; markers go with it ----------------------

reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
: > "$FAKE_DOCKER_STATE/stop-gone"
out=$(cd "$PROJECT" && kn stop 2>&1); status=$?
case $out in *'Stopped 1 Knossos container(s) for '"$PROJECT"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'a stop that finds the container already removed by another stop succeeds'; else fail "concurrent stop: status $status, $out"; fi
if [ ! -e "$INSTALL/containers/$NAME" ]; then pass 'stop removes the marker of the container it stopped'; else fail 'the marker was left after stop'; fi
reset_docker
(cd "$PROJECT" && kn version </dev/null >/dev/null 2>&1)
: > "$FAKE_DOCKER_STATE/stop-fails"
out=$(cd "$PROJECT" && kn stop 2>&1); status=$?
if [ "$status" -eq 1 ] && [ "$out" = "knossos: could not stop container $NAME" ]; then pass 'a stop that leaves the container running is still a failure'; else fail "failed stop: status $status, $out"; fi
rm -f "$FAKE_DOCKER_STATE/stop-fails"
(cd "$PROJECT" && kn stop >/dev/null 2>&1)
# Concurrent stops of several containers: every one ends with 0.
reset_docker
for project in "$PROJECT" "$OTHER" "$LOOSE"; do (cd "$project" && kn version </dev/null >/dev/null 2>&1); done
pids=''
for n in 1 2 3 4 5 6; do
    (cd / && kn stop --all >/dev/null 2>&1; echo "$?" > "$WORK/stop-$n") &
    pids="$pids $!"
done
for pid in $pids; do wait "$pid"; done
bad=0
for n in 1 2 3 4 5 6; do [ "$(cat "$WORK/stop-$n")" = 0 ] || bad=$((bad + 1)); done
if [ "$bad" -eq 0 ] && [ -z "$("$FAKE_BIN/docker" ps -q --filter "label=io.knossos.install=$(hash12 "$INSTALL")")" ]; then
    pass 'six stops at once all succeed and leave nothing running'
else
    fail "$bad of six concurrent stops failed"
fi
rm -f "$WORK"/stop-?

# ---- a data directory that holds things of yours is never mounted -----------------------

# ~/code with repositories in it: as a graph directory, every container could
# plant a git hook in each of them.
CODE="$WORK/code"
mkdir -p "$CODE/repo2/.git/hooks"
refused 'a KNOSSOS_DATA_DIR that holds folders of yours' \
    "KNOSSOS_DATA_DIR ($CODE) holds repo2, which is not something Knossos puts in a graph directory, and every container would be able to write all of it; choose an empty directory with KNOSSOS_DATA_DIR (or --data-dir when you install)." \
    data_call "$CODE"
if [ ! -e "$INSTALL/containers/graph-$(hash12 "$CODE")" ] && [ "$(ls -A "$CODE")" = repo2 ] && [ -z "$(ls -A "$CODE/repo2/.git/hooks")" ]; then
    pass 'and it is neither recorded as a graph directory nor changed'
else
    fail "refused data dir changed: $(ls -A "$CODE")"
fi
# A native install's data directory: the host runs what is in it.
NATIVE_DATA="$WORK/native data"
mkdir -p "$NATIVE_DATA/versions/1.0.0"
ln -s versions/1.0.0 "$NATIVE_DATA/current"
printf '{"mode": "native"}\n' > "$NATIVE_DATA/install.json"
: > "$NATIVE_DATA/knossos.sqlite"
refused 'a KNOSSOS_DATA_DIR that holds a native install' \
    "KNOSSOS_DATA_DIR ($NATIVE_DATA) holds a native Knossos install (current), whose release and plugin the host runs, and every container would be able to replace them; choose an empty directory with KNOSSOS_DATA_DIR (or --data-dir when you install)." \
    data_call "$NATIVE_DATA"
# One that holds only what Knossos puts in a graph directory is taken.
KNOWN="$WORK/known graph"
mkdir -p "$KNOWN/watch" "$KNOWN/backups" "$KNOWN/.knossos-files.AbC123" "$KNOWN/install"
for entry in knossos.sqlite knossos.sqlite-wal knossos.sqlite-shm knossos.sqlite-journal knossos.sqlite.migrate.lock \
    scan-failures.jsonl scan-failures.jsonl.lock scan-failures.jsonl.42.tmp roots.json roots.json.lock .roots.json.0123abcd.tmp \
    enrichers.json .enrichers.json.0123abcd.tmp pane-autostart.json .pane-autostart.json.0123abcd.tmp mcp-serve.log; do
    : > "$KNOWN/$entry"
done
reset_docker
out=$(data_call "$KNOWN" 2>&1); status=$?
if [ "$status" -eq 0 ] && grep -qF "[--mount] [type=bind,source=$KNOWN,target=$KNOWN]" "$FAKE_DOCKER_STATE/calls" && [ -f "$INSTALL/containers/graph-$(hash12 "$KNOWN")" ]; then
    pass 'a KNOSSOS_DATA_DIR holding only what Knossos puts in a graph directory is mounted and recorded'
else
    fail "known entries: status $status, $out"
fi
# Once used, what its containers wrote there is theirs, and it stays usable.
mkdir -p "$KNOWN/written by a container"
reset_docker
out=$(data_call "$KNOWN" 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'a graph directory already in use is not refused for what its containers wrote'; else fail "used graph refused: $status, $out"; fi
EMPTY_GRAPH="$WORK/empty graph"
mkdir -p "$EMPTY_GRAPH"
reset_docker
out=$(data_call "$EMPTY_GRAPH" 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'an empty KNOSSOS_DATA_DIR is taken'; else fail "empty data dir: $status, $out"; fi
find "$INSTALL/containers" -name 'graph-*' -exec rm -f {} +
rm -rf "$CODE" "$NATIVE_DATA" "$KNOWN" "$EMPTY_GRAPH"

# ---- nothing is created before a refusal --------------------------------------------------

refused 'a KNOSSOS_DATA_DIR inside the project that does not exist yet' \
    "the project root $PROJECT contains the Knossos data directory $PROJECT/new graph" \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$1/new graph" env -u KNOSSOS_ROOTS_FILE "$2" version </dev/null' _ "$PROJECT" "$LAUNCHER"
if [ ! -e "$PROJECT/new graph" ]; then pass 'and no folder is left in the project'; else fail "$PROJECT/new graph was created"; fi
refused 'a KNOSSOS_DATA_DIR inside the install directory that does not exist yet' \
    "$INSTALL/g lies inside the install directory $INSTALL, which a container may only read" \
    data_call "$INSTALL/g"
if [ ! -e "$INSTALL/g" ]; then pass 'and nothing is created in the install directory'; else fail "$INSTALL/g was created"; fi
refused 'a roots file in a new folder, with a data directory refused' \
    "the project root $PROJECT contains the Knossos data directory $PROJECT/new graph" \
    sh -c 'cd "$1" && KNOSSOS_DATA_DIR="$1/new graph" KNOSSOS_ROOTS_FILE="$2/new roots/roots.json" "$3" version </dev/null' _ "$PROJECT" "$WORK" "$LAUNCHER"
if [ ! -e "$WORK/new roots" ] && [ ! -e "$PROJECT/new graph" ]; then pass 'and neither the roots folder nor the data folder is created'; else fail 'a folder was created before the refusal'; fi
rm -rf "$PROJECT/new graph" "$INSTALL/g" "$WORK/new roots"

# ---- a call during an update never keeps a container of the old image --------------------

reset_docker
cp "$INSTALL/install.json" "$WORK/install.json.old"
write_record "ghcr.io/araneadev/knossos@sha256:2222222222222222222222222222222222222222222222222222222222222222"
cp "$INSTALL/install.json" "$WORK/install.json.new"
cp "$WORK/install.json.old" "$INSTALL/install.json"
printf 'cp "%s" "%s"\n' "$WORK/install.json.new" "$INSTALL/install.json" > "$FAKE_DOCKER_STATE/after-run"
out=$(cd "$PROJECT" && kn version </dev/null 2>&1); status=$?
if [ "$status" -eq 1 ] && [ "$out" = 'knossos: Knossos was updated while this call started its container, so that container was removed; run the command again.' ] \
    && has_call "call [rm] [-f] [$NAME]" && [ "$(count_calls exec)" -eq 0 ] && [ ! -f "$FAKE_DOCKER_STATE/containers/$NAME" ]; then
    pass 'a container started from the image an update just replaced is removed, never used'
else
    fail "update during start: status $status, $out, $(calls)"
fi
write_record "$IMAGE"
rm -f "$WORK/install.json.old" "$WORK/install.json.new"

# ---- a caller's KNOSSOS_* settings, by name, and only names that are set ------------------

reset_docker
(cd "$PROJECT" && KNOSSOS_NOTE="$(printf 'one\nKNOSSOS_INJECTED=1')" kn version </dev/null >/dev/null 2>&1)
exec_line=$(grep '^call \[exec\]' "$FAKE_DOCKER_STATE/calls")
case $exec_line in
    *'[KNOSSOS_INJECTED]'*) fail "a line inside a value was passed as a variable: $exec_line" ;;
    *'[-e] [KNOSSOS_NOTE]'*) pass 'a value holding a newline passes as one variable, and its second line is not taken for another' ;;
    *) fail "passthrough with a newline: $exec_line" ;;
esac

# ---- a record swapped for a FIFO after it was checked never hangs a call ---------------------

# A find in front of the real one that, once it has reported install.json as
# a plain file, puts a FIFO in its place: the moment between the check and
# the read, made certain.
SWAP_BIN="$WORK/swap bin"
mkdir -p "$SWAP_BIN"
REAL_FIND=$(command -v find)
cat > "$SWAP_BIN/find" <<SWAP
#!/bin/sh
"$REAL_FIND" "\$@"
status=\$?
for argument in "\$@"; do
    if [ "\$argument" = "\${SWAP_FIFO:-}" ] && [ -f "\$argument" ] && [ ! -L "\$argument" ]; then rm -f "\$argument"; mkfifo "\$argument"; fi
done
exit \$status
SWAP
chmod +x "$SWAP_BIN/find"
OTHER_INSTALL="$WORK/other installation"
mkdir -p "$OTHER_INSTALL/install/containers"
printf '{"mode": "container", "layout": "separate"}\n' > "$OTHER_INSTALL/install/install.json"
reset_docker
out=$(cd "$PROJECT" && PATH="$SWAP_BIN:$PATH" SWAP_FIFO="$OTHER_INSTALL/install/install.json" timeout 10 \
    env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE KNOSSOS_DATA_DIR="$OTHER_INSTALL/graph" "$LAUNCHER" version </dev/null 2>&1); status=$?
if [ "$status" -ne 124 ] && [ -p "$OTHER_INSTALL/install/install.json" ]; then
    pass 'an install.json swapped for a FIFO between its check and its read does not hang the call'
else
    fail "swapped record: status $status, $out"
    unblock "$OTHER_INSTALL/install/install.json"
fi
find "$INSTALL/containers" -name 'graph-*' -exec rm -f {} +
rm -rf "$OTHER_INSTALL"

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

# Only a call counts: a process the runtime's exec started has its parent
# outside the container (PPid 0). One a command left behind has a parent
# inside it, or the init process once that parent is gone.
mkdir -p "$IDLE/proc stray/1" "$IDLE/proc stray/4242" "$IDLE/proc call/1" "$IDLE/proc call/4243"
printf 'Name:\tsleep\nPPid:\t1\n' > "$IDLE/proc stray/4242/status"
printf 'Name:\tphp\nPPid:\t0\n' > "$IDLE/proc call/4243/status"
touch -t 202001010000 "$IDLE/marker"
KNOSSOS_IDLE_PROC="$IDLE/proc stray" KNOSSOS_IDLE_RUN_DIR="$IDLE/run" sh "$IDLE_STOP" "$IDLE/marker" 2 1 &
idle=$!
sleep 4
if kill -0 "$idle" 2>/dev/null; then fail 'a process left behind by a command kept the container alive'; kill "$idle"; else pass 'a process a command left behind does not keep the container alive'; fi
wait "$idle" 2>/dev/null
touch -t 202001010000 "$IDLE/marker"
KNOSSOS_IDLE_PROC="$IDLE/proc call" KNOSSOS_IDLE_RUN_DIR="$IDLE/run" sh "$IDLE_STOP" "$IDLE/marker" 2 1 &
idle=$!
sleep 4
if kill -0 "$idle" 2>/dev/null; then pass 'a call the runtime started keeps the container alive'; else fail 'idle-stop exited while a call ran'; fi
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

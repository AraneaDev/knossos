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
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
TEMPLATE="$HERE/../../tools/launcher/knossos"
IDLE_STOP="$HERE/../../tools/launcher/idle-stop"
failures=0
WORK=$(mktemp -d)
WORK=$(CDPATH='' cd -P -- "$WORK" && pwd -P)
trap 'rm -rf "$WORK"' EXIT

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
count_calls() { grep -c "^call \\[$1\\]" "$FAKE_DOCKER_STATE/calls" 2>/dev/null || true; }

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
expected_run="call [run] [-d] [--rm] [--init] [--name] [$NAME] [--label] [io.knossos.install=$(hash12 "$INSTALL")] [--label] [io.knossos.root=$(hash12 "$PROJECT")] [--network] [none] [--user] [$UID_GID] [-e] [HOME=/tmp] [-e] [KNOSSOS_CONTAINER=0] [-e] [KNOSSOS_CONTAINER_INSTALL_DIR=$INSTALL] [-e] [KNOSSOS_DATA_DIR=$INSTALL] [-e] [KNOSSOS_ROOTS_FILE=$INSTALL/roots.json] [--mount] [type=bind,source=$PROJECT,target=$PROJECT] [--mount] [type=bind,source=$INSTALL,target=$INSTALL] [--entrypoint] [/opt/knossos/tools/launcher/idle-stop] [$IMAGE] [$INSTALL/containers/$NAME]"
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
case $out in *'filesystem root cannot be a project root'*) said=yes ;; *) said=no ;; esac
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
sleep 30 &
sleeper=$!
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

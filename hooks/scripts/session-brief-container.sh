#!/bin/sh
# Session brief for a containerised Knossos installation.
#
# Emitted by `knossos install-agent-plugin --out`, with __KNOSSOS_IMAGE__ and
# __KNOSSOS_DATA__ substituted at emit time. Not used in place.
#
# The project is mounted at the same path inside the container as outside. That
# is not cosmetic: projects are keyed by `root_realpath`, so a project scanned
# as /work would never match a session starting in /home/me/project. It is
# also named as git's safe directory: the container's user does not own it.
set -u

# The scripts this one sources sit beside it. Builtins only: PATH is not safe
# to search until knossos_guard_path has run. Resolved to an absolute path
# before the cd below, since $0 may be relative to the starting directory. A
# missing script is silent like every other failure: a plain `.` of a missing
# file would abort the shell with a diagnostic.
case $0 in */*) KNOSSOS_HOOK_DIR=${0%/*}/ ;; *) KNOSSOS_HOOK_DIR=./ ;; esac
KNOSSOS_HOOK_DIR=$(CDPATH='' cd -- "$KNOSSOS_HOOK_DIR" 2>/dev/null && pwd) || exit 0
[ -r "$KNOSSOS_HOOK_DIR/guard.sh" ] || exit 0
# shellcheck source=hooks/scripts/guard.sh
. "$KNOSSOS_HOOK_DIR/guard.sh" 2>/dev/null || exit 0
# The project the hook works in, or the working directory when that does not resolve.
knossos_guard_path "${CLAUDE_PROJECT_DIR:-$PWD}" "$PWD"

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$PWD}"
IMAGE='__KNOSSOS_IMAGE__'
DATA='__KNOSSOS_DATA__'

# Same reason as the local hook: the working directory and the argument must
# name the same place. `docker run` gives the container its image's own working
# directory rather than this one, so the bind mount below is what actually
# carries the project in; entering it here keeps a caller that passes no
# CLAUDE_PROJECT_DIR from mounting one directory and reading another.
CDPATH='' cd -- "$PROJECT_DIR" 2>/dev/null || exit 0
# Absolute from here on: a relative path would mean something else to the binary, the mount and the find below once the directory changes.
PROJECT_DIR=$(pwd -P) || exit 0

command -v docker >/dev/null 2>&1 || exit 0

# Longer than the local timeout because container startup is not free, but still
# bounded: a session start must never wait on a stuck daemon.
#
# `timeout` stops only the docker client; the container it started keeps running
# and keeps writing the shared data directory. So the run is named, the name is
# removed when the limit fires, and `--init` makes the container's first process
# reap and forward signals. `docker run` forwards the first signal to the
# container and keeps waiting, so `-k 2` is the path that actually ends a stuck
# client. The removal is bounded too: the 10 s run, the 2 s kill and the 2 s
# removal stay inside the 15 s ceiling hooks.json sets, which would otherwise
# kill this script before the container is removed.
#
# The name carries the start time as well as the process id, so a reused
# process id never addresses another run's container. `date` is optional here:
# without it the name falls back to the process id alone.
NAME_SUFFIX=$(date +%s 2>/dev/null) || NAME_SUFFIX=''
NAME="knossos-hook-$$${NAME_SUFFIX:+-$NAME_SUFFIX}"
if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" -k 2 10 docker run --rm --init --name "$NAME" \
        -e "KNOSSOS_GIT_SAFE_DIRECTORY=$PROJECT_DIR" \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" session-brief "$PROJECT_DIR" 2>/dev/null)"
    STATUS=$?
    if [ "$STATUS" -ne 0 ]; then
        "$TIMEOUT_BIN" 2 docker rm -f "$NAME" >/dev/null 2>&1
        # As in session-brief.sh, a brief cut off by the bound says so in one line.
        # 124 from GNU timeout and gtimeout, 143 from BusyBox (see session-brief.sh),
        # and 137 from either when the client ignored the TERM and -k killed it.
        if [ "$STATUS" -eq 124 ] || [ "$STATUS" -eq 137 ] || { [ "$STATUS" -eq 143 ] && "$TIMEOUT_BIN" --help 2>&1 | grep -q BusyBox; }; then
            printf "Knossos brief timed out after 10 s; run knossos session-brief '%s' to see why.\n" "$PROJECT_DIR"
        fi
        exit 0
    fi
else
    # Neither `timeout` nor `gtimeout` exists here, so this one call has no
    # bound of its own. The backstop is the harness: hooks.json sets this
    # hook's own "timeout" to 15, and Claude Code enforces that ceiling on
    # the whole process regardless of what runs inside it.
    OUTPUT="$(docker run --rm --init --name "$NAME" \
        -e "KNOSSOS_GIT_SAFE_DIRECTORY=$PROJECT_DIR" \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" session-brief "$PROJECT_DIR" 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0

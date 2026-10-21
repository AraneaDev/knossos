#!/bin/sh
# Brief commands for the Claude Code mod, against a containerised Knossos installation.
#
# Usage: knossos-run-container.sh <turn-brief|dashboard> <project-dir> [options...]
#        knossos-run-container.sh component-detail <project-dir> <name>
#        knossos-run-container.sh file-detail <project-dir> <file>
#        knossos-run-container.sh scan <project-dir>
#        knossos-run-container.sh watch <project-dir> [--poll-ms=N]
#        knossos-run-container.sh allow-root <root>
#        knossos-run-container.sh session-changes <project-dir> --since=<snapshot>
#        knossos-run-container.sh session-changes <project-dir> --working-tree
#        knossos-run-container.sh session-head <project-dir>
#        knossos-run-container.sh session-diff <project-dir> --rev=<commit> --file=<file>
#        knossos-run-container.sh boundary-couplings <project-dir> --from=<boundary> --to=<boundary>
#        knossos-run-container.sh graph-search <project-dir> --query=<text>
#        knossos-run-container.sh file-context <project-dir> <file>
#        knossos-run-container.sh branch-diff <project-dir>
#        knossos-run-container.sh churn <project-dir>
#        knossos-run-container.sh blast-radius <project-dir> --component=<name>
#        knossos-run-container.sh path-between <project-dir> --from=<name> --to=<name>
#        knossos-run-container.sh annotate <project-dir> --component=<name> --value=<note> [--execute]
#        knossos-run-container.sh annotate <project-dir> --component=<name> --remove [--author=user|model] [--kind=<kind>] [--execute]
#        knossos-run-container.sh notes <project-dir> [--filter=<filter>] [--text=<text>]
#        knossos-run-container.sh note-adopt <project-dir> --component=<name> [--value=<note>] [--execute]
#        knossos-run-container.sh note-move <project-dir> --component=<name> --to=<name> [--author=user|model] [--kind=<kind>] [--execute]
#        knossos-run-container.sh note-check <project-dir> --component=<name> [--value=<reason> | --remove] [--execute]
#        knossos-run-container.sh note-confirm <project-dir> --component=<name> [--execute]
#
# Emitted by `knossos install-agent-plugin --out`, with __KNOSSOS_IMAGE__ and
# __KNOSSOS_DATA__ substituted at emit time. Not used in place.
#
# Same contract as knossos-run.sh: every failure exits 0 with nothing on stdout,
# except a missing docker, which prints {"status":"no-binary"}, and a call it
# refuses, which prints {"status":"refused","by":"wrapper","reason":"..."}.
#
# The session commands take exactly what the local wrapper lets through. The
# two that read git (`session-head`, `session-diff`) run as the caller's own
# user and group: git refuses a repository another user owns, and the image's
# user owns none of the caller's. They read the project only, never the data.
# `file-context`, `branch-diff` and `churn` read the graph as well, so they run
# as the image's user like the rest. Every run names the mounted project as
# git's safe directory (`KNOSSOS_GIT_SAFE_DIRECTORY`, passed to git as
# `-c safe.directory=`), so git reads it whoever owns it.
#
# `watch` runs the live watcher in a container of its own, attached to this
# script, until the session stops it (see the end of this file). It is never
# bounded by a timeout.
#
# The project is mounted at the same path inside the container as outside. That
# is not cosmetic: projects are keyed by `root_realpath`, so a project scanned
# as /work would never match a session starting in /home/me/project.
#
# The argument checks and the PATH guard are the local wrapper's own, sourced
# from guard.sh and run-args.sh beside this script, which the emit ships with
# it.
set -u

# The scripts this one sources sit beside it. Builtins only: PATH is not safe
# to search until knossos_guard_path has run. Resolved to an absolute path
# before the cd in run_target, since $0 may be relative to the starting
# directory. A missing script is silent like every other failure: a plain `.`
# of a missing file would abort the shell with a diagnostic.
case $0 in */*) KNOSSOS_HOOK_DIR=${0%/*}/ ;; *) KNOSSOS_HOOK_DIR=./ ;; esac
KNOSSOS_HOOK_DIR=$(CDPATH='' cd -- "$KNOSSOS_HOOK_DIR" 2>/dev/null && pwd) || exit 0
[ -r "$KNOSSOS_HOOK_DIR/guard.sh" ] || exit 0
# shellcheck source=hooks/scripts/guard.sh
. "$KNOSSOS_HOOK_DIR/guard.sh" 2>/dev/null || exit 0
# The project the hook works in is the second argument.
knossos_guard_path "${2:-}"

IMAGE='__KNOSSOS_IMAGE__'
DATA='__KNOSSOS_DATA__'

# Checks the arguments, refusing any it does not let through, and sets
# SUBCOMMAND, PROJECT_DIR, COMMAND and LIMIT: the same bounds as the local
# wrapper, so a stuck daemon never stalls the mod.
[ -r "$KNOSSOS_HOOK_DIR/run-args.sh" ] || exit 0
# shellcheck source=hooks/scripts/run-args.sh
. "$KNOSSOS_HOOK_DIR/run-args.sh" 2>/dev/null || exit 0
# The working directory and the argument must name the same place. `docker run`
# gives the container its image's own working directory rather than this one,
# so the bind mount below is what actually carries the project in; entering it
# here keeps a caller from mounting one directory and reading another.
run_target

# The one failure that is not silent: with no docker there is nothing to
# retry, so the mod is told and turns itself off. Every other failure
# (a timeout, a crash, empty output) is silence and is asked again later.
command -v docker >/dev/null 2>&1 || {
    printf '%s\n' '{"status":"no-binary"}'
    exit 0
}

# Git reads the project as the user who owns it (see above); everything else runs as the image's user.
USER_ARG=
case "$SUBCOMMAND" in
    session-head|session-diff) USER_ARG="--user=$(id -u):$(id -g)" ;;
esac

# `timeout` stops only the docker client; the container it started keeps running
# and keeps writing the shared data directory. So the run is named, the name is
# removed when the limit fires, and `--init` makes the container's first process
# reap and forward signals. `docker run` forwards the first signal to the
# container and keeps waiting, so `-k 2` is the path that actually ends a stuck
# client. The removal is bounded too: the mod allows the limit plus 5 s, and
# the 2 s kill and the 2 s removal fit in that, where a longer wait would have
# the mod kill this script before the container is removed.
#
# The name carries the start time as well as the process id, so a reused
# process id never addresses another run's container. `date` is optional here:
# without it the name falls back to the process id alone.
NAME_SUFFIX=$(date +%s 2>/dev/null) || NAME_SUFFIX=''
NAME="knossos-hook-$$${NAME_SUFFIX:+-$NAME_SUFFIX}"
# The watcher replaces this shell with the docker client, so the session
# stopping the child it holds stops the client, which forwards the signal. The
# watcher inside cannot tell that the session is gone by its parent (that is
# the container's init) nor name itself by its own id (that is another process
# on the host), so it is told the client's id, which exec makes this shell's,
# and the session's, this shell's parent, which is what a local watcher sees as
# its own parent; it runs attached: when the client goes, its stdin closes and
# it stops.
if [ "$SUBCOMMAND" = watch ]; then
    exec docker run --rm -i --init --name "$NAME" \
        -e "KNOSSOS_GIT_SAFE_DIRECTORY=$PROJECT_DIR" \
        -e "KNOSSOS_WATCH_PID=$$" \
        -e "KNOSSOS_WATCH_SESSION=$PPID" \
        -e KNOSSOS_WATCH_ALIVE=stdin \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" watch "$TARGET" "$@" 2>/dev/null
fi

if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" -k 2 "$LIMIT" docker run --rm --init --name "$NAME" ${USER_ARG:+"$USER_ARG"} \
        -e "KNOSSOS_GIT_SAFE_DIRECTORY=$PROJECT_DIR" \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || { "$TIMEOUT_BIN" 2 docker rm -f "$NAME" >/dev/null 2>&1; exit 0; }
else
    # No timeout tool: the mod's own $.process.run timeoutMs is the bound.
    OUTPUT="$(docker run --rm --init --name "$NAME" ${USER_ARG:+"$USER_ARG"} \
        -e "KNOSSOS_GIT_SAFE_DIRECTORY=$PROJECT_DIR" \
        -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
        -v "$DATA:/data" \
        "$IMAGE" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0

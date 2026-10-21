#!/bin/sh
# Injects a Knossos orientation brief at session start.
#
# Read-only: it never scans, writes, or executes project code. Every failure
# path exits 0, and all but one print nothing, because a hook that breaks a
# session start costs more than the brief was ever worth. The exception is a
# brief cut off by the timeout, which prints one line saying so.
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

# Run from the project directory so the working directory and the argument
# agree. The command derives its database from the path it is given, but a
# caller that passes no path at all falls back to the working directory, and a
# session started in a subdirectory would then answer out of a different graph.
# Guarded like every other failure path here: a directory that cannot be
# entered is silent, not fatal.
CDPATH='' cd -- "$PROJECT_DIR" 2>/dev/null || exit 0
# Absolute from here on: a relative path would mean something else to the binary, the mount and the find below once the directory changes.
PROJECT_DIR=$(pwd -P) || exit 0

# Shared discovery helpers.
[ -r "$KNOSSOS_HOOK_DIR/lib.sh" ] || exit 0
# shellcheck source=hooks/scripts/lib.sh
. "$KNOSSOS_HOOK_DIR/lib.sh" 2>/dev/null || exit 0

BIN="$(find_knossos)" || exit 0

# Bound the worst case so a locked SQLite file cannot stall a session start.
if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" 3 "$BIN" session-brief "$PROJECT_DIR" 2>/dev/null)"
    STATUS=$?
    # The one failure that says so: a brief cut off by the bound leaves the
    # session without one, and silence would give no hint why. Still exit 0.
    # GNU and Homebrew's gtimeout exit 124 when the bound fires; BusyBox exits
    # 143, a status a brief could also end with, so that one counts only from BusyBox.
    if [ "$STATUS" -eq 124 ] || { [ "$STATUS" -eq 143 ] && "$TIMEOUT_BIN" --help 2>&1 | grep -q BusyBox; }; then
        printf "Knossos brief timed out after 3 s; run knossos session-brief '%s' to see why.\n" "$PROJECT_DIR"
        exit 0
    fi
    [ "$STATUS" -eq 0 ] || exit 0
else
    # Neither `timeout` nor `gtimeout` exists here, so this one call has no
    # bound of its own. The backstop is the harness: hooks.json sets this
    # hook's own "timeout" to 15, and Claude Code enforces that ceiling on
    # the whole process regardless of what runs inside it.
    OUTPUT="$("$BIN" session-brief "$PROJECT_DIR" 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0

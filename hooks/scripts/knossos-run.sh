#!/bin/sh
# Runs one Knossos brief command for the Claude Code mod.
#
# Usage: knossos-run.sh <turn-brief|dashboard> <project-dir> [options...]
#        knossos-run.sh component-detail <project-dir> <name>
#        knossos-run.sh file-detail <project-dir> <file>
#        knossos-run.sh scan <project-dir>
#        knossos-run.sh allow-root <root>
#        knossos-run.sh watch <project-dir> [--poll-ms=N]
#        knossos-run.sh session-changes <project-dir> --since=<snapshot>
#        knossos-run.sh session-changes <project-dir> --working-tree
#        knossos-run.sh session-head <project-dir>
#        knossos-run.sh session-diff <project-dir> --rev=<commit> --file=<file>
#        knossos-run.sh boundary-couplings <project-dir> --from=<boundary> --to=<boundary>
#        knossos-run.sh graph-search <project-dir> --query=<text>
#        knossos-run.sh file-context <project-dir> <file>
#        knossos-run.sh branch-diff <project-dir>
#        knossos-run.sh churn <project-dir>
#        knossos-run.sh blast-radius <project-dir> --component=<name>
#        knossos-run.sh path-between <project-dir> --from=<name> --to=<name>
#        knossos-run.sh annotate <project-dir> --component=<name> --value=<note> [--execute]
#        knossos-run.sh annotate <project-dir> --component=<name> --remove [--author=user|model] [--kind=<kind>] [--execute]
#        knossos-run.sh notes <project-dir> [--filter=<filter>] [--text=<text>]
#        knossos-run.sh note-adopt <project-dir> --component=<name> [--value=<note>] [--execute]
#        knossos-run.sh note-move <project-dir> --component=<name> --to=<name> [--author=user|model] [--kind=<kind>] [--execute]
#        knossos-run.sh note-check <project-dir> --component=<name> [--value=<reason> | --remove] [--execute]
#        knossos-run.sh note-confirm <project-dir> --component=<name> [--execute]
#
# Every failure exits 0. All but two print nothing: the mod reads silence as
# "no data", keeps its last figures with their age and asks again later, so a
# broken install can never break a session. A missing binary prints
# {"status":"no-binary"} so the mod can turn itself off, and a call this
# wrapper refuses (a subcommand it does not know, an argument it does not let
# through, a KNOSSOS_RUN_TIMEOUT it cannot use) prints
# {"status":"refused","by":"wrapper","reason":"..."} so the mod can log it:
# the mod asking for something the wrapper will never run is a defect, not
# a quiet moment.
#
# `watch` is the one that does not end on its own: it becomes the live
# watcher (`knossos watch --shared`), which prints one JSON event per line
# for as long as the session keeps it, and stops when the session stops it
# or goes away. It is never bounded by a timeout.
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

# Checks the arguments, refusing any it does not let through, and sets
# SUBCOMMAND, PROJECT_DIR, COMMAND and LIMIT.
[ -r "$KNOSSOS_HOOK_DIR/run-args.sh" ] || exit 0
# shellcheck source=hooks/scripts/run-args.sh
. "$KNOSSOS_HOOK_DIR/run-args.sh" 2>/dev/null || exit 0
run_target

# Shared discovery helpers and the install-time data location.
[ -r "$KNOSSOS_HOOK_DIR/lib.sh" ] || exit 0
# shellcheck source=hooks/scripts/lib.sh
. "$KNOSSOS_HOOK_DIR/lib.sh" 2>/dev/null || exit 0
# A grant goes only into the roots file the installation baked in (or one the
# environment names): without it the binary would fall back to a file beside
# the working directory, which no server reads.
if [ "$SUBCOMMAND" = allow-root ] && [ -z "${KNOSSOS_ROOTS_FILE:-}" ]; then
    exit 0
fi

# The one failure that is not silent: with no binary there is nothing to
# retry, so the mod is told and turns itself off. Every other failure
# (a timeout, a crash, empty output) is silence and is asked again later.
BIN="$(find_knossos)" || {
    printf '%s\n' '{"status":"no-binary"}'
    exit 0
}

# The watcher replaces this shell, so stopping the child the session holds stops the watcher itself.
if [ "$SUBCOMMAND" = watch ]; then
    exec "$BIN" watch "$TARGET" "$@" 2>/dev/null
fi

if TIMEOUT_BIN="$(find_timeout)"; then
    OUTPUT="$("$TIMEOUT_BIN" "$LIMIT" "$BIN" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
else
    # No timeout tool: the mod's own $.process.run timeoutMs is the bound.
    OUTPUT="$("$BIN" "$COMMAND" "$TARGET" "$@" --json 2>/dev/null)" || exit 0
fi

[ -n "$OUTPUT" ] || exit 0
printf '%s\n' "$OUTPUT"
exit 0

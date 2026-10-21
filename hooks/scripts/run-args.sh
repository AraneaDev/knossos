# shellcheck shell=sh
# shellcheck disable=SC2034 # COMMAND and the other values it sets are read by the wrapper that sources it
# What the brief wrappers, local and container, let through to the binary.
# Sourced, never executed, with the wrapper's own arguments still in place:
# `<subcommand> <project-dir> [options...]`. It checks them, and on any
# argument it does not let through prints the refusal (see refuse below) and
# exits; otherwise it leaves
#
#   SUBCOMMAND   the subcommand, one of the fixed names below
#   PROJECT_DIR  the project directory as the caller spelled it
#   COMMAND      the binary's command for it
#   LIMIT        its bound in seconds, 0 for the watcher, which has none
#   FILE_ARG     for file-detail and file-context, the file (taken off the arguments)
#
# and the positional parameters set to the options the binary receives.
# run_target, called once the wrapper is in the project directory, then sets
# TARGET. One copy for both wrappers, so the container never lets through
# what the local wrapper refuses, or the other way round.
#
# Callers run under `set -u`, after knossos_guard_path.

# A call this wrapper does not let through is said, not swallowed: one JSON
# line naming the wrapper as the one that refused, so the mod can tell it
# from a binary that had nothing to say (and from the binary's own
# `refused`), and nothing runs. The reason names only the subcommand, which by
# then is one of the fixed names below, never what the caller passed.
refuse() {
    printf '{"status":"refused","by":"wrapper","reason":"%s"}\n' "${1:-$SUBCOMMAND does not take these arguments}"
    exit 0
}

[ "$#" -ge 2 ] || refuse 'a subcommand and a project directory are required'
SUBCOMMAND=$1
PROJECT_DIR=$2
shift 2

# A stuck binary, or a stuck daemon, never stalls the mod: every run but the
# watcher is bounded.
# The binary's command is the subcommand's name, except `scan`: the pane's
# rescan runs `knossos rescan`, never `knossos scan`, so it can only rescan an
# existing project in an allowed root and never create one.
COMMAND=$SUBCOMMAND
case "$SUBCOMMAND" in
    turn-brief) LIMIT=${KNOSSOS_RUN_TIMEOUT:-60} ;;
    scan) LIMIT=${KNOSSOS_RUN_TIMEOUT:-60}; COMMAND=rescan ;;
    # A cold first dashboard of a large project walks the whole graph.
    dashboard) LIMIT=${KNOSSOS_RUN_TIMEOUT:-30} ;;
    component-detail|file-detail|file-context|graph-search) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    # Two whole graphs read and compared.
    branch-diff) LIMIT=${KNOSSOS_RUN_TIMEOUT:-30} ;;
    # A git log of the last 30 days, a search out from one component, two route searches, a note.
    churn|blast-radius|path-between|annotate) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    # The Notes tab: every note read with its staleness, or one note written.
    notes|note-adopt|note-move|note-check|note-confirm) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    allow-root) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    session-changes|session-head|session-diff|boundary-couplings) LIMIT=${KNOSSOS_RUN_TIMEOUT:-15} ;;
    watch) LIMIT=0 ;;
    *) refuse 'unknown subcommand' ;;
esac
# The bound is a whole number of seconds above 0: `timeout` reads 0 as no
# bound at all, and a value it cannot read makes every run fail, which would
# look like a binary with nothing to say.
# Leading zeros go first, so the length below counts digits that matter; a day
# is the most any run may take, and a longer bound would overflow the shell's
# arithmetic before it meant anything.
case $LIMIT in
    '' | *[!0-9]*) refuse 'KNOSSOS_RUN_TIMEOUT is not a whole number of seconds' ;;
esac
LIMIT=${LIMIT#"${LIMIT%%[!0]*}"}
LIMIT=${LIMIT:-0}
if [ "$SUBCOMMAND" != watch ]; then
    [ "$LIMIT" != 0 ] || refuse 'KNOSSOS_RUN_TIMEOUT is not above 0'
    { [ "${#LIMIT}" -le 5 ] && [ "$LIMIT" -le 86400 ]; } || refuse 'KNOSSOS_RUN_TIMEOUT is more than a day'
fi

# component-detail takes exactly one component name, which may not read as an
# option: `--db=...` would point the read at another graph.
if [ "$SUBCOMMAND" = component-detail ]; then
    [ "$#" -eq 1 ] || refuse
    case "$1" in -*) refuse ;; esac
fi
# file-detail takes exactly one file, relative to the project directory: an
# option such as `--db=...` would point the read at another graph, and an
# absolute path or a `..` step would read outside the directory the call names.
if [ "$SUBCOMMAND" = file-detail ] || [ "$SUBCOMMAND" = file-context ]; then
    [ "$#" -eq 1 ] || refuse
    case "$1" in -* | /* | '' | .. | ../* | */../* | */..) refuse ;; esac
fi
# Whether "$1" is one line of text of at most $2 characters: no control
# character, counted by its characters. Read byte by byte (LC_ALL=C), so a
# non-ASCII name is text whatever locale the hook runs in, and a UTF-8
# continuation byte is not counted as a character of its own.
is_text_line() (
    LC_ALL=C
    export LC_ALL
    case "$1" in *[[:cntrl:]]*) exit 1 ;; esac
    chars=$(printf '%s' "$1" | tr -d '\200-\277')
    [ "${#chars}" -le "$2" ]
)
# graph-search takes exactly what was typed into the finder, one line of
# text of at most 200 characters: any other option (`--db=...`) would read
# another graph.
if [ "$SUBCOMMAND" = graph-search ]; then
    [ "$#" -eq 1 ] || refuse
    case "$1" in --query=) refuse ;; --query=*) ;; *) refuse ;; esac
    is_text_line "$1" 208 || refuse
fi
# branch-diff takes nothing but the project.
if [ "$SUBCOMMAND" = branch-diff ]; then
    [ "$#" -eq 0 ] || refuse
fi
# churn takes nothing but the project.
if [ "$SUBCOMMAND" = churn ]; then
    [ "$#" -eq 0 ] || refuse
fi
# blast-radius takes exactly one component, by its name, one line of text: any other
# option (`--db=...`) would read another graph.
if [ "$SUBCOMMAND" = blast-radius ]; then
    [ "$#" -eq 1 ] || refuse
    case "$1" in --component=) refuse ;; --component=*) ;; *) refuse ;; esac
    is_text_line "$1" 520 || refuse
fi
# path-between takes exactly the two components, the one the route starts at
# and the one it ends at, in that order, each one line of text.
if [ "$SUBCOMMAND" = path-between ]; then
    [ "$#" -eq 2 ] || refuse
    case "$1" in --from=) refuse ;; --from=*) ;; *) refuse ;; esac
    case "$2" in --to=) refuse ;; --to=*) ;; *) refuse ;; esac
    { is_text_line "$1" 520 && is_text_line "$2" 520; } || refuse
fi
# Whether "$1" is a kind of note the pane may name. Not `check`: the person's
# request has its own command, note-check.
is_kind() {
    case "$1" in note | intended_boundary | confirmed_dead | false_positive | intentional) return 0 ;; esac
    return 1
}
# Whether the options from the "$1"th on are, each at most once and in this
# order, among: `--author=user|model` (a), `--kind=<kind>` (k) and `--execute`
# (e), as "$2" lists the ones allowed (say `ake`). Positions count from 1, the
# remaining arguments follow.
in_order() (
    from=$1
    allowed=$2
    shift 2
    at=0
    stage=0
    for arg in "$@"; do
        at=$((at + 1))
        [ "$at" -ge "$from" ] || continue
        case "$arg" in
            --author=user | --author=model) step=1 letter=a ;;
            --kind=*) is_kind "${arg#--kind=}" || exit 1; step=2 letter=k ;;
            --execute) step=3 letter=e ;;
            *) exit 1 ;;
        esac
        case "$allowed" in *"$letter"*) ;; *) exit 1 ;; esac
        [ "$step" -gt "$stage" ] || exit 1
        stage=$step
    done
)
# annotate takes the component, a note of one line of text (at most 2,000
# characters) and, last, `--execute` when the person confirmed the preview in
# the pane: without it nothing is written. Or, to remove one, the component,
# `--remove`, then the author and the kind of the note it names. Nothing else:
# an option such as `--db=...` would write into another graph.
if [ "$SUBCOMMAND" = annotate ]; then
    { [ "$#" -ge 2 ] && [ "$#" -le 5 ]; } || refuse
    case "$1" in --component=) refuse ;; --component=*) ;; *) refuse ;; esac
    is_text_line "$1" 520 || refuse
    if [ "$2" = --remove ]; then
        in_order 3 ake "$@" || refuse
    else
        [ "$#" -le 3 ] || refuse
        case "$2" in --value=) refuse ;; --value=*) ;; *) refuse ;; esac
        is_text_line "$2" 2008 || refuse
        in_order 3 e "$@" || refuse
    fi
fi
# notes takes, each at most once and in this order, the filter (one of a fixed
# set) and the text the list is narrowed by, one line of at most 200 characters.
if [ "$SUBCOMMAND" = notes ]; then
    [ "$#" -le 2 ] || refuse
    stage=0
    for arg in "$@"; do
        case "$arg" in
            --filter=all | --filter=yours | --filter=model | --filter=stale | --filter=missing | --filter=flagged) step=1 ;;
            --text=*) is_text_line "$arg" 207 || refuse; step=2 ;;
            *) refuse ;;
        esac
        [ "$step" -gt "$stage" ] || refuse
        stage=$step
    done
fi
# note-adopt takes the component, the note as the person edited it (one line,
# at most 2,000 characters) and, last, `--execute`.
if [ "$SUBCOMMAND" = note-adopt ]; then
    { [ "$#" -ge 1 ] && [ "$#" -le 3 ]; } || refuse
    case "$1" in --component=) refuse ;; --component=*) ;; *) refuse ;; esac
    is_text_line "$1" 520 || refuse
    from=2
    case "${2:-}" in --value=) refuse ;; --value=*) is_text_line "$2" 2008 || refuse; from=3 ;; esac
    in_order "$from" e "$@" || refuse
fi
# note-move takes the component, the one the note moves to, then the author and
# the kind of the note, and, last, `--execute`.
if [ "$SUBCOMMAND" = note-move ]; then
    { [ "$#" -ge 2 ] && [ "$#" -le 5 ]; } || refuse
    case "$1" in --component=) refuse ;; --component=*) ;; *) refuse ;; esac
    case "$2" in --to=) refuse ;; --to=*) ;; *) refuse ;; esac
    { is_text_line "$1" 520 && is_text_line "$2" 520; } || refuse
    in_order 3 ake "$@" || refuse
fi
# note-check takes the component, the reason (one line, at most 2,000
# characters) or `--remove` to take the request back, and, last, `--execute`.
if [ "$SUBCOMMAND" = note-check ]; then
    { [ "$#" -ge 1 ] && [ "$#" -le 3 ]; } || refuse
    case "$1" in --component=) refuse ;; --component=*) ;; *) refuse ;; esac
    is_text_line "$1" 520 || refuse
    from=2
    case "${2:-}" in --value=) refuse ;; --value=*) is_text_line "$2" 2008 || refuse; from=3 ;; --remove) from=3 ;; esac
    in_order "$from" e "$@" || refuse
fi
# note-confirm takes the component and, last, `--execute`: the note's text and
# its flag stay as they are, so nothing else is asked of it.
if [ "$SUBCOMMAND" = note-confirm ]; then
    { [ "$#" -ge 1 ] && [ "$#" -le 2 ]; } || refuse
    case "$1" in --component=) refuse ;; --component=*) ;; *) refuse ;; esac
    is_text_line "$1" 520 || refuse
    in_order 2 e "$@" || refuse
fi
# scan takes nothing but the project: an option such as `--db=...` would point the write at another graph.
if [ "$SUBCOMMAND" = scan ]; then
    [ "$#" -eq 0 ] || refuse
fi
# session-changes takes exactly the snapshot the session began at, a plain id,
# or `--working-tree` (what git says is not committed yet): any other option
# (`--db=...`) would read another graph.
if [ "$SUBCOMMAND" = session-changes ]; then
    [ "$#" -eq 1 ] || refuse
    case "$1" in --working-tree) ;; --since=*[!A-Za-z0-9_.:-]* | --since=) refuse ;; --since=*) ;; *) refuse ;; esac
fi
# session-head takes nothing but the project.
if [ "$SUBCOMMAND" = session-head ]; then
    [ "$#" -eq 0 ] || refuse
fi
# session-diff takes exactly the commit the session began at (a hex id) and one
# file relative to the project directory, in that order: no other option, no
# absolute path and no step out of the directory.
if [ "$SUBCOMMAND" = session-diff ]; then
    [ "$#" -eq 2 ] || refuse
    case "$1" in --rev=*[!0-9a-f]* | --rev=) refuse ;; --rev=*) ;; *) refuse ;; esac
    { [ "${#1}" -ge 13 ] && [ "${#1}" -le 70 ]; } || refuse
    case "$2" in --file=-* | --file=/* | --file= | --file=.. | --file=../* | --file=*/../* | --file=*/..) refuse ;; --file=*) ;; *) refuse ;; esac
fi
# boundary-couplings takes exactly the two boundaries of one heat map cell, the
# one it runs from and the one it runs to, in that order, each one line of text:
# any other option (`--db=...`) would read another graph.
if [ "$SUBCOMMAND" = boundary-couplings ]; then
    [ "$#" -eq 2 ] || refuse
    case "$1" in --from=) refuse ;; --from=*) ;; *) refuse ;; esac
    case "$2" in --to=) refuse ;; --to=*) ;; *) refuse ;; esac
    { is_text_line "$1" 520 && is_text_line "$2" 520; } || refuse
fi
# allow-root takes nothing but the root, and always writes: the pane runs it
# only after the person confirmed, so a preview would answer a question nobody
# asked. An option such as `--db=...` would grant the root in another file.
if [ "$SUBCOMMAND" = allow-root ]; then
    [ "$#" -eq 0 ] || refuse
    set -- --execute
fi
# watch takes at most its poll interval, in milliseconds: an option such as
# `--db=...` would point it at another graph.
if [ "$SUBCOMMAND" = watch ]; then
    [ "$#" -le 1 ] || refuse
    if [ "$#" -eq 1 ]; then
        case "$1" in --poll-ms=*[!0-9]* | --poll-ms=) refuse ;; --poll-ms=*) ;; *) refuse ;; esac
    fi
    set -- --shared "$@"
fi
# file-detail and file-context read a file, not the project: it is checked
# again once the wrapper is in the project directory, by run_target.
FILE_ARG=
if [ "$SUBCOMMAND" = file-detail ] || [ "$SUBCOMMAND" = file-context ]; then
    FILE_ARG=$1
    shift
fi

# Whether "$1", a path under the project directory, stays inside it once every
# symbolic link on the way is followed: `src/x.php` may be a link to a file
# anywhere, and the binary would read that file. The directories are resolved
# physically, each link's target read in turn (at most 16), and the path is
# refused once one lands outside. A path whose directory does not exist names
# nothing to read, so it stays.
inside_project() (
    path=$1
    hops=0
    while :; do
        dir=$(CDPATH='' cd -P -- "$(dirname -- "$path")" 2>/dev/null && pwd -P) || exit 0
        case $dir/ in "$PROJECT_DIR"/*) ;; *) exit 1 ;; esac
        name=${path##*/}
        [ -L "$dir/$name" ] || exit 0
        hops=$((hops + 1))
        [ "$hops" -le 16 ] || exit 1
        link=$(readlink -- "$dir/$name") || exit 1
        case $link in /*) path=$link ;; *) path=$dir/$link ;; esac
    done
)

# Enter the project directory and set TARGET, what the binary reads: the
# project directory, or for file-detail and file-context the file in it. The
# working directory and the argument then name the same place. A directory
# that cannot be entered ends the run in silence; a file that leads out of the
# project is refused.
run_target() {
    CDPATH='' cd -- "$PROJECT_DIR" 2>/dev/null || exit 0
    # Absolute from here on: a relative path would mean something else to the binary, the mount and the find below once the directory changes.
    PROJECT_DIR=$(pwd -P) || exit 0
    TARGET=$PROJECT_DIR
    if [ -n "$FILE_ARG" ]; then
        TARGET="$PROJECT_DIR/$FILE_ARG"
        inside_project "$TARGET" || refuse "$SUBCOMMAND reads nothing outside the project"
    fi
}

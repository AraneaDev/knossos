# shellcheck shell=sh
# The guards every Knossos hook script, local or container, runs first.
# Sourced, never executed, by an absolute path: `.` looks a bare name up on
# PATH, which is the very thing not yet safe to search. Function definitions
# only, so sourcing it runs nothing.
#
# Callers run under `set -u`.

# Only absolute PATH entries that lie outside the project survive. The hook
# runs inside the project, so a relative entry (`node_modules/.bin`, `.`, or an
# empty one, which means the working directory) would resolve dirname, tr,
# timeout, knossos or docker to a program the repository ships. So would an
# absolute entry that points into the project, such as the
# `<project>/node_modules/.bin` a tool manager adds, whether it names the
# project directly or reaches it through a symbolic link. Each entry is
# therefore compared by its physical path. One that does not resolve is dropped:
# it names no directory to compare, and a dangling link could start to resolve
# into the project later. A project that itself holds a bin directory on PATH
# (the project is $HOME) drops that entry on purpose. Builtins only, run
# first, before any program is looked up.
#
# The arguments are the directories the hook may work in, in order: the first
# that resolves to a physical path is the project. An empty one stands for the
# filesystem root, and none resolving means no project to filter by.
knossos_guard_path() {
    KNOSSOS_PATH_PROJECT=''
    for KNOSSOS_PATH_ENTRY do
        KNOSSOS_PATH_PROJECT=$(CDPATH='' cd -P -- "${KNOSSOS_PATH_ENTRY:-/}" 2>/dev/null && pwd -P) && break
        KNOSSOS_PATH_PROJECT=''
    done
    # The filesystem root holds every entry, so it is no directory to filter by.
    case $KNOSSOS_PATH_PROJECT in
        /) KNOSSOS_PATH_PROJECT='' ;;
    esac
    KNOSSOS_SAFE_PATH=''
    KNOSSOS_SAVED_IFS=$IFS
    IFS=:
    set -f
    for KNOSSOS_PATH_ENTRY in ${PATH:-}; do
        case $KNOSSOS_PATH_ENTRY in
            /*) ;;
            *) continue ;;
        esac
        KNOSSOS_PATH_REAL=$(CDPATH='' cd -P -- "$KNOSSOS_PATH_ENTRY" 2>/dev/null && pwd -P) || continue
        case $KNOSSOS_PATH_PROJECT in
            '') ;;
            *)
                case $KNOSSOS_PATH_REAL/ in
                    "$KNOSSOS_PATH_PROJECT"/*) continue ;;
                esac
                ;;
        esac
        KNOSSOS_SAFE_PATH="${KNOSSOS_SAFE_PATH:+$KNOSSOS_SAFE_PATH:}$KNOSSOS_PATH_ENTRY"
    done
    set +f
    IFS=$KNOSSOS_SAVED_IFS
    # An empty PATH would itself mean the working directory, so it never stays empty.
    PATH=${KNOSSOS_SAFE_PATH:-/usr/bin:/bin}
    export PATH
    unset KNOSSOS_SAFE_PATH KNOSSOS_SAVED_IFS KNOSSOS_PATH_ENTRY KNOSSOS_PATH_REAL KNOSSOS_PATH_PROJECT
}

# `timeout` is GNU coreutils. A plain macOS ships none of it, and Homebrew's
# coreutils installs the same tool as `gtimeout` so it never shadows a BSD
# tool of the same name. Try both names before giving up the bound.
find_timeout() {
    if command -v timeout >/dev/null 2>&1; then
        command -v timeout
        return 0
    fi
    if command -v gtimeout >/dev/null 2>&1; then
        command -v gtimeout
        return 0
    fi
    return 1
}

# What the exit status of a bounded run says about how the run ended, for a
# caller that has something to report when it did not end by itself. Sets
# KNOSSOS_FAILURE_CLASS to `timed-out`, `killed` or `crashed`, KNOSSOS_FAILURE_SIGNAL
# to the signal that ended a killed run, and KNOSSOS_FAILURE_EXIT to the status of
# a crashed one; all stay empty for a run that printed its own answer, which is
# the binary's own failure and stays silent.
#
#   knossos_classify_run <status> <timeout tool> <output> [<seconds elapsed> <limit>]
#
# 124 is the bound firing, from GNU timeout and gtimeout. A status above 128 is
# a signal, the signal being the status minus 128. Two of those are also what a
# bound that was not obeyed ends with, 143 (BusyBox's timeout, or a TERM that
# did) and 137 (`-k` after a TERM was ignored): a run that lasted its whole
# bound was stopped by it, and where the time is not known 143 counts as the
# bound only from BusyBox. Any other failure that printed nothing the mod can
# read is a crash, such as a PHP fatal.
knossos_classify_run() {
    KNOSSOS_FAILURE_CLASS=
    KNOSSOS_FAILURE_SIGNAL=
    KNOSSOS_FAILURE_EXIT=
    case ${1:-} in
        '' | *[!0-9]*) return 0 ;;
    esac
    if [ "$1" -eq 124 ]; then
        KNOSSOS_FAILURE_CLASS=timed-out
    elif { [ "$1" -eq 137 ] || [ "$1" -eq 143 ]; } && [ -n "${4:-}" ] && [ -n "${5:-}" ] && [ "$4" -ge "$5" ]; then
        KNOSSOS_FAILURE_CLASS=timed-out
    elif [ "$1" -eq 143 ] && [ -z "${4:-}" ] && [ -n "${2:-}" ] && "$2" --help 2>&1 | grep -q BusyBox; then
        KNOSSOS_FAILURE_CLASS=timed-out
    elif [ "$1" -gt 128 ] && [ "$1" -le 192 ]; then
        KNOSSOS_FAILURE_CLASS=killed
        KNOSSOS_FAILURE_SIGNAL=$(($1 - 128))
    else
        case ${3:-} in
            '{'*) ;;
            *)
                KNOSSOS_FAILURE_CLASS=crashed
                KNOSSOS_FAILURE_EXIT=$1
                ;;
        esac
    fi
    return 0
}

# The one line the mod reads for a run knossos_classify_run found ended
# unexpectedly: nothing when it found nothing.
knossos_say_failure() {
    case $KNOSSOS_FAILURE_CLASS in
        timed-out) printf '%s\n' '{"status":"timed-out"}' ;;
        killed) printf '{"status":"killed","signal":%s}\n' "$KNOSSOS_FAILURE_SIGNAL" ;;
        crashed) printf '{"status":"crashed","exit":%s}\n' "$KNOSSOS_FAILURE_EXIT" ;;
    esac
    return 0
}

# Keeps a failure in the log beside the database, from a container wrapper:
#
#   knossos_record_in_container <project dir> <class> [<signal> [<started by>]]
#
# The caller has set IMAGE, DATA and PROJECT_DIR as it runs its own container.
# Run in the background with its output dropped and its own bound, so the
# recording never lengthens a call that already used its time: the mod allows a
# scan its limit plus 5 s, and the brief hook has no more than its 15 s ceiling.
knossos_record_in_container() {
    command -v docker >/dev/null 2>&1 || return 0
    _knossos_signal=${3:-}
    _knossos_started=${4:-}
    (
        if _knossos_timeout=$(find_timeout); then
            "$_knossos_timeout" -k 1 3 docker run --rm --init \
                -e "KNOSSOS_GIT_SAFE_DIRECTORY=$PROJECT_DIR" \
                -v "$PROJECT_DIR:$PROJECT_DIR:ro" \
                -v "$DATA:/data" \
                "$IMAGE" record-scan-failure "$1" --class="$2" ${_knossos_signal:+"--signal=$_knossos_signal"} ${_knossos_started:+"--started-by=$_knossos_started"}
        fi
    ) </dev/null >/dev/null 2>&1 &
    return 0
}

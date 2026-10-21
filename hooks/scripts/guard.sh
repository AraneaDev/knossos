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

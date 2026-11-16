#!/bin/sh
# Remove a Knossos installation that install.sh made.
#
#   uninstall.sh [--data-dir=DIR] [--install-dir=DIR] [--bin-dir=DIR]
#                [--purge] [--no-plugin] [--dry-run]
#
# Removes what the installer and self-update put on the host: the releases,
# the record of the installation, the plugin's files, the launcher and, when
# Claude Code is there, the plugin and the MCP server registration that point
# at this installation. Your graph (knossos.sqlite and what belongs to it) and
# your settings (roots.json, enrichers.json, pane-autostart.json) stay unless
# --purge is given.
#
# Which installation: a native one keeps everything in its data directory
# (--data-dir, else KNOSSOS_DATA_DIR, else ~/.knossos), and its install.json
# says so. A container install keeps its record in the install directory
# (--install-dir, else KNOSSOS_INSTALL_DIR, else
# ${XDG_DATA_HOME:-~/.local/share}/knossos) and its graph in the data
# directory that record names. A native install in the data directory is the
# one removed when both are found; the run says that the other one is there.
#
# Only entries Knossos itself makes are removed, each by its own name from the
# lists below, which are install.sh's (tests/shell/uninstall.sh compares
# them). A directory is removed only once it is empty, so a file of yours in
# the data directory stays, and the run names it. The launcher is removed only
# when it is the launcher of this installation, and the MCP server and the
# plugin are unregistered only when Claude Code has them pointing here: a
# registration of a checkout or of another installation is left alone.
#
# Container mode: the installation's running containers are stopped first
# (`knossos stop --all`). The image stays in the container runtime; the run
# says how to remove it.
#
# Environment:
#   KNOSSOS_DATA_DIR      the data directory, when --data-dir is not given
#                                                              (default: ~/.knossos)
#   KNOSSOS_INSTALL_DIR   container mode: the install directory, when
#                         --install-dir is not given
#                         (default: ${XDG_DATA_HOME:-~/.local/share}/knossos)
#   KNOSSOS_BIN_DIR       where the launcher is, when --bin-dir is not given
#                         (default: what install.json records, else ~/.local/bin)
#   KNOSSOS_MCP_NAME      MCP server name                       (default: knossos)
#   KNOSSOS_MCP_SCOPE     claude scope: local|user|project      (default: user)
#
# Everything below sits in one brace group that ends with `main "$@"; exit`,
# as in install.sh: a download cut off anywhere runs nothing.
{
set -eu

say() { printf '%s\n' "${1-}"; }
log() { printf '  %s\n' "$1"; }
warn() { printf 'warning: %s\n' "$1" >&2; }
die() {
    printf 'error: %s\n' "$1" >&2
    exit 1
}

usage() {
    cat <<'USAGE'
usage: uninstall.sh [--data-dir=DIR] [--install-dir=DIR] [--bin-dir=DIR]
                    [--purge] [--no-plugin] [--dry-run]

  --data-dir     the data directory of the installation (default: ~/.knossos)
  --install-dir  container mode: the install directory (default:
                 ${XDG_DATA_HOME:-~/.local/share}/knossos)
  --bin-dir      where the launcher is (default: what the installation
                 recorded, else ~/.local/bin)
  --purge        also remove the graph and the settings: knossos.sqlite, its
                 backups, roots.json, enrichers.json and pane-autostart.json
  --no-plugin    leave Claude Code alone: claude is never called
  --dry-run      say what would be removed and remove nothing
USAGE
}

absolute() {
    case $1 in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$(pwd)" "$1" ;;
    esac
}

# What a native install keeps in its data directory besides the graph and the
# settings: the program and its bookkeeping. With SETTINGS_ENTRIES this is
# install.sh's NATIVE_ENTRIES.
# shellcheck disable=SC2016 # the lists are data, never expanded
PROGRAM_ENTRIES='
install.json
install.json.tmp.*
versions
current
current.tmp.*
previous-version
previous-version.tmp.*
plugin
update-check.json
update-check.json.tmp.*
self-update.lock
self-update.tmp
roots.json.lock
.roots.json.*.tmp
.enrichers.json.*.tmp
.pane-autostart.json.*.tmp
'
# What you chose: kept unless --purge.
SETTINGS_ENTRIES='
roots.json
enrichers.json
pane-autostart.json
'
# The graph: the names of install.sh's GRAPH_ENTRIES. Kept unless --purge.
GRAPH_ENTRIES='
knossos.sqlite
knossos.sqlite-wal
knossos.sqlite-shm
knossos.sqlite-journal
knossos.sqlite.migrate.lock
scan-failures.jsonl
scan-failures.jsonl.lock
scan-failures.jsonl.*.tmp
watch
backups
mcp-serve.log
.knossos-files.*
'
# What a container install keeps in its install directory: install.sh's
# INSTALL_ENTRIES.
INSTALL_ENTRIES='
install.json
container-update
plugin
containers
settings
tmp
roots.json
roots.json.lock.d
.roots.*
update-check.json
self-update.lock.d
self-update.lock.d.takeover
.work.*
.install.*
.knossos-taken.*
'

# remove_path PATH: remove one entry, or say it would be. A directory a
# release left without its read or search bits is opened up first, as the
# installer does; neither chmod -R nor rm -rf follows a link.
remove_path() {
    if [ "$DRY_RUN" = 1 ]; then
        log "would remove $1"
        REMOVED=$((REMOVED + 1))
        return 0
    fi
    if [ -d "$1" ] && [ ! -L "$1" ]; then chmod -R u+rwx "$1" 2>/dev/null || true; fi
    if rm -rf -- "$1"; then
        log "removed $1"
        REMOVED=$((REMOVED + 1))
    else
        warn "could not remove $1"
        FAILED=1
    fi
}

# remove_entries DIR LIST: remove every entry directly in DIR whose name is on
# LIST. The directory is quoted, so only the listed pattern is ever a pattern.
remove_entries() {
    [ -d "$1" ] || return 0
    while read -r pattern; do
        [ -n "$pattern" ] || continue
        # shellcheck disable=SC2231 # the pattern is meant to expand as a pattern
        for found in "${1%/}"/$pattern; do
            if [ -e "$found" ] || [ -L "$found" ]; then remove_path "$found"; fi
        done
    done <<LIST
$2
LIST
}

# remove_if_empty DIR: remove a directory that holds nothing any more, and
# name what is left in one that does.
remove_if_empty() {
    [ -d "$1" ] && [ ! -L "$1" ] || return 0
    left=''
    more=0
    for found in "${1%/}"/* "${1%/}"/.[!.]* "${1%/}"/..?*; do
        if [ ! -e "$found" ] && [ ! -L "$found" ]; then continue; fi
        more=$((more + 1))
        if [ "$more" -le 5 ]; then left="$left ${found##*/}"; fi
    done
    if [ "$more" -gt 0 ]; then
        if [ "$more" -gt 5 ]; then left="$left and $((more - 5)) more"; fi
        LEFT="$LEFT
  $1:$left"
        return 0
    fi
    if [ "$DRY_RUN" = 1 ]; then
        log "would remove $1 (empty)"
    elif rmdir -- "$1" 2>/dev/null; then
        log "removed $1 (empty)"
    fi
}

# A string field of an install.json, as install.sh and the update helper
# write them: one field per line, quote and backslash escaped.
json_field() {
    sed -n 's/^[[:space:]]*"'"$2"'"[[:space:]]*:[[:space:]]*"\(.*\)",\{0,1\}$/\1/p' "$1" 2>/dev/null | head -n 1 | sed 's/\\\(.\)/\1/g'
}

# Whether install.json in DIR is a plain file recording MODE.
records_mode() {
    [ -f "$1/install.json" ] && [ ! -L "$1/install.json" ] || return 1
    grep -q '"mode"[[:space:]]*:[[:space:]]*"'"$2"'"' "$1/install.json" 2>/dev/null
}

# The install directory a launcher was written for, as install.sh reads it.
launcher_home() {
    sed -n "s/^INSTALL_DIR='\(.*\)'\$/\1/p" "$1" 2>/dev/null | head -n 1 | sed "s/'\\\\''/'/g"
}

# Whether the launcher in the bin directory is this installation's: a plain
# file whose install directory line names HOME_DIR.
launcher_is_ours() {
    [ -f "$LAUNCHER" ] && [ ! -L "$LAUNCHER" ] || return 1
    [ "$(launcher_home "$LAUNCHER")" = "$HOME_DIR" ]
}

# run_claude ARGS: one claude call, or what it would be.
run_claude() {
    if [ "$DRY_RUN" = 1 ]; then
        log "would run: claude $*"
        return 0
    fi
    claude "$@" >/dev/null 2>&1 </dev/null
}

# Unregister the MCP server and the plugin, each only when Claude Code has it
# pointing at this installation.
unregister_claude() {
    [ "$PLUGIN" = 1 ] || return 0
    if ! command -v claude >/dev/null 2>&1; then
        log "Claude CLI not found; if Claude Code used this installation, run: claude mcp remove $NAME -s $SCOPE; claude plugin uninstall knossos@knossos --scope $SCOPE; claude plugin marketplace remove knossos"
        return 0
    fi
    say 'Unregistering from Claude Code...'
    if claude mcp get "$NAME" 2>/dev/null </dev/null | grep -F -q -- "$LAUNCHER"; then
        if run_claude mcp remove "$NAME" -s "$SCOPE" || run_claude mcp remove "$NAME"; then
            log "MCP server '$NAME' unregistered"
        else
            warn "could not unregister the MCP server; run: claude mcp remove $NAME -s $SCOPE"
        fi
    else
        log "no MCP server '$NAME' that runs $LAUNCHER; nothing unregistered"
    fi
    if claude plugin marketplace list 2>/dev/null </dev/null | grep -F -q -- "$HOME_DIR/plugin"; then
        if run_claude plugin uninstall knossos@knossos --scope "$SCOPE" || run_claude plugin uninstall knossos@knossos; then
            log 'plugin uninstalled'
        else
            warn "could not uninstall the plugin; run: claude plugin uninstall knossos@knossos --scope $SCOPE"
        fi
        run_claude plugin marketplace remove knossos \
            || warn 'could not remove the plugin marketplace; run: claude plugin marketplace remove knossos'
    else
        log "no plugin marketplace at $HOME_DIR/plugin; nothing uninstalled"
    fi
}

# A directory that is / or the home directory is never a Knossos directory,
# whatever it holds.
refuse_wide() {
    wide=$1
    while [ "${#wide}" -gt 1 ] && [ "${wide%/}" != "$wide" ]; do wide=${wide%/}; done
    if [ "$wide" = / ] || [ "$wide" = "${HOME:-}" ]; then
        die "$2 $1 is the filesystem root or your home directory; name the Knossos directory itself. Nothing was changed."
    fi
}

main() {
    DATA_DIR=''
    DATA_DIR_GIVEN=0
    INSTALL_DIR=''
    BIN_DIR="${KNOSSOS_BIN_DIR:-}"
    NAME="${KNOSSOS_MCP_NAME:-knossos}"
    SCOPE="${KNOSSOS_MCP_SCOPE:-user}"
    PURGE=0
    PLUGIN=1
    DRY_RUN=0
    REMOVED=0
    FAILED=0
    LEFT=''

    while [ $# -gt 0 ]; do
        case $1 in
            --data-dir=*)
                DATA_DIR=${1#--data-dir=}
                DATA_DIR_GIVEN=1
                [ -n "$DATA_DIR" ] || die '--data-dir needs a directory'
                ;;
            --install-dir=*)
                INSTALL_DIR=${1#--install-dir=}
                [ -n "$INSTALL_DIR" ] || die '--install-dir needs a directory'
                ;;
            --bin-dir=*)
                BIN_DIR=${1#--bin-dir=}
                [ -n "$BIN_DIR" ] || die '--bin-dir needs a directory'
                ;;
            --purge) PURGE=1 ;;
            --no-plugin) PLUGIN=0 ;;
            --dry-run) DRY_RUN=1 ;;
            -h | --help)
                usage
                exit 0
                ;;
            *)
                usage >&2
                die "unknown argument: $1"
                ;;
        esac
        shift
    done

    [ -n "$DATA_DIR" ] || DATA_DIR="${KNOSSOS_DATA_DIR:-}"
    if [ -z "$DATA_DIR" ]; then
        [ -n "${HOME:-}" ] || die 'HOME is not set; pass --data-dir'
        DATA_DIR="$HOME/.knossos"
    fi
    DATA_DIR=$(absolute "$DATA_DIR")
    [ -n "$INSTALL_DIR" ] || INSTALL_DIR=${KNOSSOS_INSTALL_DIR:-}
    if [ -z "$INSTALL_DIR" ]; then
        case ${XDG_DATA_HOME:-} in
            /*) INSTALL_DIR="${XDG_DATA_HOME%/}/knossos" ;;
            *) if [ -n "${HOME:-}" ]; then INSTALL_DIR="$HOME/.local/share/knossos"; fi ;;
        esac
    fi
    [ -z "$INSTALL_DIR" ] || INSTALL_DIR=$(absolute "$INSTALL_DIR")
    case $DATA_DIR$INSTALL_DIR$BIN_DIR in
        *[[:cntrl:]]*) die 'the directories must not contain a newline or another control character' ;;
    esac
    refuse_wide "$DATA_DIR" 'the data directory'
    [ -z "$INSTALL_DIR" ] || refuse_wide "$INSTALL_DIR" 'the install directory'

    # ---- which installation ------------------------------------------------

    # HOME_DIR is the directory the launcher was written for: the data
    # directory of a native install, the install directory of a container one.
    OTHER=''
    if records_mode "$DATA_DIR" native; then
        MODE=native
        HOME_DIR=$DATA_DIR
        if [ -n "$INSTALL_DIR" ] && records_mode "$INSTALL_DIR" container; then OTHER=$INSTALL_DIR; fi
    elif [ -n "$INSTALL_DIR" ] && records_mode "$INSTALL_DIR" container; then
        MODE=container
        HOME_DIR=$INSTALL_DIR
        # The graph is where the record says, unless --data-dir named it.
        if [ "$DATA_DIR_GIVEN" = 0 ]; then
            recorded=$(json_field "$INSTALL_DIR/install.json" data_dir)
            case $recorded in /?*) DATA_DIR=$recorded ;; esac
        fi
    else
        # No record: what an interrupted install or an earlier uninstall left.
        MODE=none
        HOME_DIR=$DATA_DIR
    fi

    if [ -z "$BIN_DIR" ] && [ "$MODE" != none ]; then
        BIN_DIR=$(json_field "$HOME_DIR/install.json" bin_dir)
        case $BIN_DIR in /?*) ;; *) BIN_DIR='' ;; esac
    fi
    if [ -z "$BIN_DIR" ]; then
        [ -n "${HOME:-}" ] || die 'HOME is not set; pass --bin-dir'
        BIN_DIR="$HOME/.local/bin"
    fi
    BIN_DIR=$(absolute "$BIN_DIR")
    LAUNCHER="$BIN_DIR/knossos"

    case $MODE in
        native) say "Removing the native Knossos installation in $DATA_DIR" ;;
        container) say "Removing the container Knossos installation in $INSTALL_DIR (graph: $DATA_DIR)" ;;
        none) say "No installation record in $DATA_DIR${INSTALL_DIR:+ or $INSTALL_DIR}; removing what an installation left there" ;;
    esac
    [ "$DRY_RUN" = 0 ] || say 'Dry run: nothing is removed or changed.'

    # ---- stop, unregister --------------------------------------------------

    IMAGE=''
    if [ "$MODE" = container ]; then
        IMAGE=$(json_field "$INSTALL_DIR/install.json" image)
        if launcher_is_ours; then
            if [ "$DRY_RUN" = 1 ]; then
                log "would run: $LAUNCHER stop --all"
            elif "$LAUNCHER" stop --all >/dev/null 2>&1 </dev/null; then
                log 'containers stopped'
            else
                warn "could not stop the containers of this installation; see what runs with: docker ps --filter label=io.knossos.install"
            fi
        fi
    fi
    if [ "$MODE" != none ]; then unregister_claude; fi

    # ---- files -------------------------------------------------------------

    if [ "$MODE" = container ]; then
        remove_entries "$INSTALL_DIR" "$INSTALL_ENTRIES"
        remove_if_empty "$INSTALL_DIR"
    else
        remove_entries "$DATA_DIR" "$PROGRAM_ENTRIES"
    fi
    if [ "$PURGE" = 1 ]; then
        [ "$MODE" = container ] || remove_entries "$DATA_DIR" "$SETTINGS_ENTRIES"
        remove_entries "$DATA_DIR" "$GRAPH_ENTRIES"
    fi
    remove_if_empty "$DATA_DIR"

    if launcher_is_ours; then
        remove_path "$LAUNCHER"
    elif [ -e "$LAUNCHER" ] || [ -L "$LAUNCHER" ]; then
        log "$LAUNCHER is not the launcher of this installation; left alone"
    fi

    # ---- what is left ------------------------------------------------------

    say
    if [ "$REMOVED" = 0 ]; then
        say 'Nothing of Knossos was found to remove.'
    elif [ "$DRY_RUN" = 1 ]; then
        say "Dry run: entries that would be removed: $REMOVED."
    else
        say 'Knossos is uninstalled.'
    fi
    if [ -n "$LEFT" ]; then
        if [ "$PURGE" = 1 ]; then
            say 'Left in place, since Knossos does not put it there:'
        else
            say 'Kept, your graph and settings (remove them too with --purge):'
        fi
        printf '%s\n' "${LEFT#?}"
    fi
    if [ -n "$IMAGE" ]; then
        say "The image stays in the container runtime; remove it with: docker image rm $IMAGE"
    fi
    if [ -n "$OTHER" ]; then
        say "A container install is in $OTHER too; run this again to remove it."
    fi
    [ "$FAILED" = 0 ] || die 'some entries could not be removed (see the warnings above)'
}

main "$@"; exit
}

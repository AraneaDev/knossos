#!/bin/sh
# Install Knossos from a release, without a checkout.
#
#   install.sh [--mode=native|container] [--version=V] [--data-dir=DIR]
#              [--bin-dir=DIR] [--no-plugin] [--no-roots] [--dry-run]
#
# Native mode needs PHP 8.3+, Node 24+ and Python 3.11+ on the host. It
# downloads the release tarball for this platform, checks it against the
# release's SHA256SUMS, unpacks it under <data dir>/versions/<version>, points
# <data dir>/current at it and writes one launcher, <bin dir>/knossos, that the
# MCP registration and the plugin's hooks call.
#
# Environment:
#   KNOSSOS_DATA_DIR      data directory                        (default: ~/.knossos)
#   KNOSSOS_BIN_DIR       where the launcher goes               (default: ~/.local/bin)
#   KNOSSOS_RELEASE_BASE  download base: a releases URL, a file:// URL or a
#                         plain directory holding the tarball and SHA256SUMS
#                         (default: https://github.com/AraneaDev/knossos/releases)
#   KNOSSOS_ALLOW_ROOT    a project root to add to the roots file (default: the
#                         directory the installer is run from, see --no-roots)
#   KNOSSOS_MCP_NAME      MCP server name                       (default: knossos)
#   KNOSSOS_MCP_SCOPE     claude mcp scope: local|user|project  (default: user)
#
# Nothing changes until the download is checked and the new release has passed
# `knossos doctor` (against a throwaway data dir); a failed install leaves the
# previous release working. The checksum guards against a corrupted download;
# it comes from the same origin as the tarball, so it does not protect against
# a compromised release origin.
#
# Everything below sits in one brace group that ends with `main "$@"; exit`, so
# a download cut off anywhere runs nothing. The group is not indented.
# shellcheck disable=SC2317 # cleanup runs from a trap, which the `; exit` below hides from the check.
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
usage: install.sh [--mode=native|container] [--version=V] [--data-dir=DIR]
                  [--bin-dir=DIR] [--no-plugin] [--no-roots] [--dry-run]
USAGE
}

# Absolute paths, so a relative argument survives any cd; nothing is resolved.
absolute() {
    case $1 in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$(pwd)" "$1" ;;
    esac
}

# Digits and dots, optional pre-release after a hyphen; it lands in paths and URLs.
validate_version() {
    case $1 in
        '' | *[!0-9A-Za-z.-]* | *..* | -* | .* | [!0-9]*) die "not a valid version: $1" ;;
    esac
    core=${1%%-*}
    case $core in
        *[!0-9.]* | *.) die "not a valid version: $1" ;;
    esac
}

# Where an asset lives: download/v<version>/ on a release URL, flat in a directory.
asset_location() {
    if [ "$REMOTE" = 1 ]; then
        printf '%s/download/v%s/%s' "$BASE" "$VERSION" "$1"
    else
        printf '%s/%s' "$BASE" "$1"
    fi
}

# fetch ASSET DESTINATION: copy or download one release asset (a plain copy for
# a directory: `curl file://` is not everywhere). Children get /dev/null as
# stdin: under `curl | sh` a child that read it would eat the script.
fetch() {
    location=$(asset_location "$1")
    if [ "$REMOTE" = 1 ]; then
        curl --fail --silent --show-error --location --retry 3 --output "$2" "$location" </dev/null \
            || die "could not download $1 from $location"
    else
        [ -f "$location" ] || die "release asset $1 not found at $location"
        cp "$location" "$2" || die "could not copy $location"
    fi
}

resolve_latest() {
    if [ "$REMOTE" = 1 ]; then
        # /releases/latest redirects to /releases/tag/v<version>.
        final=$(curl --fail --silent --show-error --location --output /dev/null \
            --write-out '%{url_effective}' "$BASE/latest" </dev/null) \
            || die "could not find the latest release at $BASE/latest"
        tag=${final##*/}
        VERSION=${tag#v}
    else
        newest=$(
            for file in "$BASE"/knossos-*-"$OS"-"$ARCH".tar.gz; do
                [ -e "$file" ] || continue
                stem=${file##*/knossos-}
                printf '%s\n' "${stem%-"$OS"-"$ARCH".tar.gz}"
            done | sort -t. -k1,1n -k2,2n -k3,3n | tail -n 1
        )
        [ -n "$newest" ] || die "no release asset knossos-<version>-$OS-$ARCH.tar.gz in $BASE"
        VERSION=$newest
    fi
    validate_version "$VERSION"
}

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d ' ' -f 1
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | cut -d ' ' -f 1
    else
        die 'sha256sum or shasum is required to check the download'
    fi
}

# A complete version is reused: it was checked and doctored when it went in.
version_complete() { [ -x "$VERSION_DIR/bin/knossos" ] && [ -f "$VERSION_DIR/VERSION" ]; }

# Walk the release without following links: files, directories and relative
# links that resolve inside the tree (the rule of VersionSwitch, in PHP). The
# path is an argument, never code; -I imports nothing from the cwd.
check_release_links() {
    python3 -I -c '
import os, sys
root = os.path.realpath(sys.argv[1])
inside = lambda p: p == root or p.startswith(root + "/")
def ok(cur, t):
    q, miss, hops = t.split("/"), 0, 0
    if not t or t[0] == "/": return False
    while q:
        s = q.pop(0)
        n = cur + "/" + s
        if s in ("", "."): continue
        if s == "..":
            if miss: miss -= 1
            else:
                cur = os.path.dirname(cur)
                if not inside(cur): return False
        elif miss: miss += 1
        elif not os.path.lexists(n): miss = 1
        else:
            try: os.stat(n)
            except OSError:
                l = os.readlink(n) if os.path.islink(n) else ""
                hops += 1
                if hops > 40 or not l or l[0] == "/": return False
                q[:0] = l.split("/")
                continue
            cur = os.path.realpath(n)
            if not inside(cur): return False
    return True
todo = [""]
while todo:
    d = todo.pop()
    here = os.path.realpath(os.path.join(root, d))
    try:
        if not os.access(here, os.R_OK | os.X_OK): raise OSError
        entries = list(os.scandir(here))
    except OSError:
        sys.exit("unreadable directory " + ascii(d))
    for e in entries:
        m = os.path.join(d, e.name)
        if e.is_symlink():
            if not ok(here, os.readlink(e.path)): sys.exit("link " + ascii(m) + " -> " + ascii(os.readlink(e.path)))
        elif e.is_dir(follow_symlinks=False): todo.append(m)
        elif not e.is_file(follow_symlinks=False): sys.exit("not a file, directory or link: " + ascii(m))
' "$1" 2>&1 </dev/null
}

# Delete the partial unpack. A release can hold a directory with no read or
# search bits, which rm -rf cannot enter, so open the tree up first (chmod -R
# does not follow links). Only ever the partial's own path, and never when unset.
remove_partial() {
    [ -n "$PARTIAL" ] || return 0
    chmod -R u+rwx "$PARTIAL" 2>/dev/null || true
    rm -rf "$PARTIAL"
}

# Runs on every exit. After the switch has begun (SWITCHED=1) any failure,
# interrupts included, puts the previous release back; temporary files go either way.
cleanup() {
    if [ "$SWITCHED" = 1 ]; then rollback_switch || true; fi
    rm -rf "$TMP"
    remove_partial
    if [ -n "$BIN_DIR" ]; then rm -f "$BIN_DIR/.knossos.tmp.$$"; fi
    if [ -n "$DATA_DIR" ]; then rm -f "$DATA_DIR/install.json.tmp.$$" "$DATA_DIR/current.tmp.$$"; fi
}

# Point a link at a target atomically: build it under a temporary name, rename
# it over the old one. `mv -T` (GNU) never moves into the directory the old link
# points at; BSD and macOS have no -T, so python3 os.replace does the rename.
# `ln -sfn` is the last, non-atomic resort.
atomic_link() {
    target=$1
    link=$2
    tmp_link="$link.tmp.$$"
    rm -f "$tmp_link"
    ln -s "$target" "$tmp_link"
    if mv -T "$tmp_link" "$link" 2>/dev/null; then
        return 0
    fi
    if python3 -c 'import os, sys; os.replace(sys.argv[1], sys.argv[2])' "$tmp_link" "$link" 2>/dev/null </dev/null; then
        return 0
    fi
    rm -f "$tmp_link"
    ln -sfn "$target" "$link"
}

# Write the launcher from the release's template to PATH. The data directory
# goes in inside single quotes, a quote in the path closed, escaped, reopened.
write_launcher() {
    quoted=$(printf '%s' "$DATA_DIR" | sed "s/'/'\\\\''/g")
    while IFS= read -r line || [ -n "$line" ]; do
        case $line in
            "INSTALLED_DATA_DIR='__KNOSSOS_DATA_DIR__'") printf "INSTALLED_DATA_DIR='%s'\n" "$quoted" ;;
            *) printf '%s\n' "$line" ;;
        esac
    done < "$TEMPLATE" > "$1"
    chmod 0755 "$1"
}

# Undo the switch after a failure that came after it: put the previous link,
# install.json and launcher back, or remove what did not exist before.
rollback_switch() {
    if [ -n "$PREV_LINK" ]; then
        atomic_link "$PREV_LINK" "$DATA_DIR/current"
    else
        rm -f "$DATA_DIR/current"
    fi
    if [ -f "$TMP/install.json.prev" ]; then
        cp "$TMP/install.json.prev" "$DATA_DIR/install.json"
    else
        rm -f "$DATA_DIR/install.json"
    fi
    if [ -f "$TMP/launcher.prev" ]; then
        cp "$TMP/launcher.prev" "$BIN_DIR/knossos"
    else
        rm -f "$BIN_DIR/knossos"
    fi
}

# Merge a project root into the roots file.
merge_root() {
    mkdir -p "$(dirname -- "$ROOTS_FILE")"
    # shellcheck disable=SC2016 # the $-prefixed names in this php -r program are PHP variables.
    KNOSSOS_ROOTS_FILE="$ROOTS_FILE" KNOSSOS_NEW_ROOT="$1" php -r '
$file = getenv("KNOSSOS_ROOTS_FILE");
$new = rtrim(getenv("KNOSSOS_NEW_ROOT"), "/");
$roots = [];
if (is_file($file)) {
    $decoded = json_decode((string) file_get_contents($file), true);
    if (is_array($decoded)) {
        $existing = $decoded["roots"] ?? $decoded;
        if (is_array($existing)) {
            foreach ($existing as $root) {
                if (is_string($root) && trim($root) !== "") { $roots[] = rtrim(trim($root), "/"); }
            }
        }
    }
}
if (!in_array($new, $roots, true)) { $roots[] = $new; }
$roots = array_values(array_unique($roots));
try {
    $payload = json_encode(["roots" => $roots], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (JsonException $error) {
    fwrite(STDERR, "  unable to encode the roots file: " . $error->getMessage() . "\n");
    exit(1);
}
if (@file_put_contents($file, $payload) === false) {
    fwrite(STDERR, "  unable to write the roots file: " . $file . "\n");
    exit(1);
}
printf("  %d root(s) granted\n", count($roots));
' </dev/null || warn "could not update the roots file $ROOTS_FILE"
}

# Which directory to grant: KNOSSOS_ALLOW_ROOT, else the one run from (not / or home without a terminal).
choose_root() {
    if [ -n "${KNOSSOS_ALLOW_ROOT:-}" ]; then
        printf '%s' "$KNOSSOS_ALLOW_ROOT"
        return 0
    fi
    [ "$ROOTS" = 1 ] || return 0
    here=$(pwd)
    if [ ! -t 0 ]; then
        if [ "$here" = / ] || [ "$here" = "${HOME:-}" ]; then
            return 0
        fi
    fi
    printf '%s' "$here"
}

main() {
    MODE=auto
    VERSION=''
    DATA_DIR="${KNOSSOS_DATA_DIR:-}"
    BIN_DIR="${KNOSSOS_BIN_DIR:-}"
    BASE="${KNOSSOS_RELEASE_BASE:-https://github.com/AraneaDev/knossos/releases}"
    NAME="${KNOSSOS_MCP_NAME:-knossos}"
    SCOPE="${KNOSSOS_MCP_SCOPE:-user}"
    PLUGIN=1
    ROOTS=1
    DRY_RUN=0
    PARTIAL=''
    TMP=''
    PREV_LINK=''
    SWITCHED=0

    while [ $# -gt 0 ]; do
        case $1 in
            --mode=*) MODE=${1#--mode=} ;;
            --version=*) VERSION=${1#--version=} ;;
            --data-dir=*) DATA_DIR=${1#--data-dir=} ;;
            --bin-dir=*) BIN_DIR=${1#--bin-dir=} ;;
            --no-plugin) PLUGIN=0 ;;
            --no-roots) ROOTS=0 ;;
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

    case $MODE in
        auto | native) ;;
        container) die 'container mode is not available in this version of the installer' ;;
        *) die "--mode must be native or container, not: $MODE" ;;
    esac

    # HOME is only needed when a directory was not given.
    if [ -z "$DATA_DIR" ]; then
        [ -n "${HOME:-}" ] || die 'HOME is not set; pass --data-dir'
        DATA_DIR="$HOME/.knossos"
    fi
    if [ -z "$BIN_DIR" ]; then
        [ -n "${HOME:-}" ] || die 'HOME is not set; pass --bin-dir'
        BIN_DIR="$HOME/.local/bin"
    fi
    VERSION=${VERSION#v}
    [ -z "$VERSION" ] || validate_version "$VERSION"
    DATA_DIR=$(absolute "$DATA_DIR")
    BIN_DIR=$(absolute "$BIN_DIR")

    # What is wrong with each runtime, by version and not just by presence.
    missing=''
    add_missing() { missing="$missing
  - $1"; }

    if ! command -v php >/dev/null 2>&1; then
        add_missing 'PHP 8.3 or newer (php not found)'
    elif ! php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' >/dev/null 2>&1 </dev/null; then
        add_missing "PHP 8.3 or newer (found $(php -r 'echo PHP_VERSION;' 2>/dev/null </dev/null))"
    fi
    if ! command -v node >/dev/null 2>&1; then
        add_missing 'Node 24 or newer (node not found)'
    elif ! node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 24 ? 0 : 1)' >/dev/null 2>&1 </dev/null; then
        add_missing "Node 24 or newer (found $(node --version 2>/dev/null </dev/null))"
    fi
    if ! command -v python3 >/dev/null 2>&1; then
        add_missing 'Python 3.11 or newer (python3 not found)'
    elif ! python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)' >/dev/null 2>&1 </dev/null; then
        add_missing "Python 3.11 or newer (found $(python3 --version 2>&1 </dev/null))"
    fi

    # A container runtime counts only when its daemon answers: a docker binary
    # with a stopped daemon cannot run anything.
    container_runtime=''
    for candidate in docker podman; do
        if command -v "$candidate" >/dev/null 2>&1 && "$candidate" info >/dev/null 2>&1 </dev/null; then
            container_runtime=$candidate
            break
        fi
    done

    if [ -n "$missing" ]; then
        {
            say 'This host cannot run Knossos natively. Missing:'
            printf '%s\n' "$missing"
        } >&2
        if [ -n "$container_runtime" ]; then
            die "$container_runtime is available, but container mode is not available in this version of the installer"
        fi
        if [ "$MODE" = native ]; then
            die 'install the runtimes listed above and run the installer again'
        fi
        die 'install the runtimes listed above (or Docker or Podman, with its daemon running) and run the installer again'
    fi
    MODE=native

    case $(uname -s) in
        Linux) OS=linux ;;
        Darwin) OS=darwin ;;
        *) die "unsupported operating system: $(uname -s)" ;;
    esac
    case $(uname -m) in
        x86_64 | amd64) ARCH=x86_64 ;;
        arm64 | aarch64) ARCH=arm64 ;;
        *) die "unsupported architecture: $(uname -m)" ;;
    esac

    case $BASE in
        http://* | https://*) REMOTE=1 ;;
        file://*)
            REMOTE=0
            BASE=${BASE#file://}
            ;;
        *) REMOTE=0 ;;
    esac
    BASE=${BASE%/}

    if [ "$REMOTE" = 1 ]; then
        command -v curl >/dev/null 2>&1 || die 'curl is required to download a release'
    fi

    [ -n "$VERSION" ] || resolve_latest
    ASSET="knossos-$VERSION-$OS-$ARCH.tar.gz"
    VERSION_DIR="$DATA_DIR/versions/$VERSION"
    ROOTS_FILE="${KNOSSOS_ROOTS_FILE:-$DATA_DIR/roots.json}"
    ROOT_TO_GRANT=$(choose_root)

    if [ "$DRY_RUN" = 1 ]; then
        say 'Dry run: nothing is created or changed.'
        log "mode:       $MODE"
        log "version:    $VERSION"
        log "asset:      $ASSET"
        log "from:       $(asset_location "$ASSET")"
        log "data dir:   $DATA_DIR"
        log "unpack to:  $VERSION_DIR"
        log "launcher:   $BIN_DIR/knossos"
        if [ -n "$ROOT_TO_GRANT" ]; then
            log "grants:     $ROOT_TO_GRANT"
        else
            log 'grants:     no project root'
        fi
        if [ "$PLUGIN" = 1 ]; then
            log 'plugin:     install the plugin and register the MCP server with Claude Code'
        else
            log 'plugin:     skipped (--no-plugin)'
        fi
        exit 0
    fi

    TMP=$(mktemp -d "${TMPDIR:-/tmp}/knossos-install.XXXXXX")
    trap cleanup EXIT
    trap 'exit 1' INT TERM HUP

    say "Installing Knossos $VERSION ($OS-$ARCH, native)"

    if version_complete; then
        log "version $VERSION is already unpacked; reusing it"
    else
        log "fetching $ASSET"
        fetch SHA256SUMS "$TMP/SHA256SUMS"
        fetch "$ASSET" "$TMP/$ASSET"

        expected=''
        while IFS= read -r line || [ -n "$line" ]; do
            case $line in
                *"  $ASSET") expected=${line%%  *} ;;
            esac
        done < "$TMP/SHA256SUMS"
        [ -n "$expected" ] || die "SHA256SUMS has no entry for $ASSET; this release has no build for $OS-$ARCH"
        actual=$(sha256_of "$TMP/$ASSET")
        [ "$actual" = "$expected" ] || die "checksum mismatch for $ASSET (expected $expected, got $actual); nothing was changed"
        log 'checksum verified'

        # Everything must sit under knossos-<version>/ and stay inside it.
        tar -tzf "$TMP/$ASSET" > "$TMP/listing" 2>/dev/null </dev/null || die "$ASSET is not a readable archive; nothing was changed"
        while IFS= read -r entry; do
            case $entry in
                "knossos-$VERSION" | "knossos-$VERSION/"*) ;;
                *) die "unexpected entry in $ASSET: $entry" ;;
            esac
            case $entry in
                */../* | */.. | ../*) die "unsafe path in $ASSET: $entry" ;;
            esac
        done < "$TMP/listing"

        mkdir -p "$DATA_DIR/versions"
        # A name of its own per process, so two installers never share a directory.
        PARTIAL="$DATA_DIR/versions/$VERSION.partial.$$"
        remove_partial
        mkdir "$PARTIAL"
        tar -xzf "$TMP/$ASSET" -C "$PARTIAL" --strip-components=1 </dev/null || die "could not unpack $ASSET; nothing was changed"
        if ! bad_link=$(check_release_links "$PARTIAL"); then
            die "unsafe release, ${bad_link:-the link check could not run}; nothing was changed"
        fi
        if [ ! -x "$PARTIAL/bin/knossos" ] || [ "$(cat "$PARTIAL/VERSION" 2>/dev/null)" != "$VERSION" ]; then
            die "$ASSET does not hold a complete release of $VERSION; nothing was changed"
        fi

        # The new release must run before anything points at it, against a
        # throwaway data dir: doctor migrates the database it finds.
        log 'running knossos doctor on the new release'
        mkdir "$TMP/doctor-data"
        if ! KNOSSOS_DATA_DIR="$TMP/doctor-data" "$PARTIAL/bin/knossos" doctor > "$TMP/doctor.out" 2>&1 </dev/null; then
            cat "$TMP/doctor.out" >&2
            die 'knossos doctor failed on the new release; the previous release, if any, is untouched'
        fi

        # Rename into place; an existing target would swallow the directory, so deal with it first.
        if [ -e "$VERSION_DIR" ] && ! version_complete; then rm -rf "$VERSION_DIR"; fi
        if [ -e "$VERSION_DIR" ]; then
            remove_partial
        else
            mv "$PARTIAL" "$VERSION_DIR"
        fi
        PARTIAL=''
    fi

    TEMPLATE="$VERSION_DIR/launcher/knossos"
    [ -f "$TEMPLATE" ] || die "the release has no launcher template at $TEMPLATE; nothing was switched"
    mkdir -p "$BIN_DIR"
    LAUNCHER="$BIN_DIR/knossos"
    NEW_LAUNCHER="$BIN_DIR/.knossos.tmp.$$"
    write_launcher "$NEW_LAUNCHER"
    # Run the new launcher against a scratch data dir, so one that cannot start the release is caught first.
    mkdir "$TMP/probe"
    ln -s "$VERSION_DIR" "$TMP/probe/current"
    if ! probe=$(KNOSSOS_DATA_DIR="$TMP/probe" "$NEW_LAUNCHER" version 2>&1 </dev/null); then
        rm -f "$NEW_LAUNCHER"
        die "the launcher does not run the new release: $probe; nothing was switched"
    fi

    if [ -L "$DATA_DIR/current" ]; then PREV_LINK=$(readlink "$DATA_DIR/current"); fi
    if [ -f "$DATA_DIR/install.json" ]; then cp "$DATA_DIR/install.json" "$TMP/install.json.prev"; fi
    if [ -f "$LAUNCHER" ]; then cp "$LAUNCHER" "$TMP/launcher.prev"; fi

    mkdir -p "$DATA_DIR"
    SWITCHED=1
    atomic_link "versions/$VERSION" "$DATA_DIR/current"
    log "current -> versions/$VERSION"

    installed_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    printf '{\n  "mode": "native",\n  "version": "%s",\n  "image": null,\n  "installed_at": "%s"\n}\n' \
        "$VERSION" "$installed_at" > "$DATA_DIR/install.json.tmp.$$"
    mv -f "$DATA_DIR/install.json.tmp.$$" "$DATA_DIR/install.json"
    mv -f "$NEW_LAUNCHER" "$LAUNCHER"
    log "launcher written to $LAUNCHER"

    # The real thing, end to end. If it fails, everything goes back.
    if installed=$("$LAUNCHER" version 2>&1 </dev/null); then
        # Installed and working: the steps below are best effort and never undo it.
        SWITCHED=0
        log "$installed"
    else
        die "the installed launcher does not run ($installed); the previous state was restored"
    fi

    if [ -n "$ROOT_TO_GRANT" ]; then
        merge_root "$ROOT_TO_GRANT"
    elif [ "$ROOTS" = 1 ] && [ -z "${KNOSSOS_ALLOW_ROOT:-}" ]; then
        log "no project root granted: the working directory is / or your home directory; add one to $ROOTS_FILE"
    fi

    if [ "$PLUGIN" = 1 ]; then
        say 'Installing the Claude Code plugin...'
        if KNOSSOS_DATA_DIR="$DATA_DIR" "$LAUNCHER" install-agent-plugin --data-dir="$DATA_DIR" --execute >/dev/null 2>&1 </dev/null; then
            log 'plugin installed'
        else
            warn "the plugin was not installed; run: $LAUNCHER install-agent-plugin --data-dir=\"$DATA_DIR\" --execute"
        fi
        if command -v claude >/dev/null 2>&1; then
            say 'Registering the MCP server with Claude Code...'
            claude mcp remove "$NAME" -s "$SCOPE" >/dev/null 2>&1 </dev/null || true
            # No --allow-root: the roots file governs. KNOSSOS_ROOTS_FILE is set
            # too, so the server reads the file named here.
            if claude mcp add "$NAME" -s "$SCOPE" \
                -e "KNOSSOS_DATA_DIR=$DATA_DIR" -e "KNOSSOS_ROOTS_FILE=$ROOTS_FILE" -- \
                "$LAUNCHER" serve </dev/null; then
                log "registered as '$NAME' (scope: $SCOPE)"
            else
                warn "registering the MCP server failed; run: claude mcp add $NAME -s $SCOPE -- \"$LAUNCHER\" serve"
            fi
        else
            log "Claude CLI not found; register the MCP server later with: claude mcp add $NAME -s $SCOPE -- \"$LAUNCHER\" serve"
        fi
    fi

    case ":${PATH:-}:" in
        *":$BIN_DIR:"*) ;;
        *)
            say
            say "$BIN_DIR is not on your PATH. Add it, for example:"
            say "  export PATH=\"$BIN_DIR:\$PATH\""
            ;;
    esac

    say
    say "Knossos $VERSION is installed."
}

main "$@"; exit
}

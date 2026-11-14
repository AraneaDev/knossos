#!/bin/sh
set -eu

# Install Knossos from a release, without a checkout.
#
#   install.sh [--mode=native|container] [--version=V] [--data-dir=DIR]
#              [--bin-dir=DIR] [--no-plugin] [--dry-run]
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
#   KNOSSOS_ALLOW_ROOT    a project root to add to the roots file (default: none)
#   KNOSSOS_MCP_NAME      MCP server name                       (default: knossos)
#   KNOSSOS_MCP_SCOPE     claude mcp scope: local|user|project  (default: user)
#
# Nothing is changed until the download has been checked and the new release
# has passed `knossos doctor`; a failed install leaves the previous release
# working.

MODE=auto
VERSION=''
DATA_DIR="${KNOSSOS_DATA_DIR:-}"
BIN_DIR="${KNOSSOS_BIN_DIR:-}"
BASE="${KNOSSOS_RELEASE_BASE:-https://github.com/AraneaDev/knossos/releases}"
NAME="${KNOSSOS_MCP_NAME:-knossos}"
SCOPE="${KNOSSOS_MCP_SCOPE:-user}"
PLUGIN=1
DRY_RUN=0

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
                  [--bin-dir=DIR] [--no-plugin] [--dry-run]
USAGE
}

while [ $# -gt 0 ]; do
    case $1 in
        --mode=*) MODE=${1#--mode=} ;;
        --version=*) VERSION=${1#--version=} ;;
        --data-dir=*) DATA_DIR=${1#--data-dir=} ;;
        --bin-dir=*) BIN_DIR=${1#--bin-dir=} ;;
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
case $VERSION in
    *[!0-9A-Za-z._+-]*) die "the version may only hold letters, digits and . _ + -: $VERSION" ;;
esac

# Absolute paths, so a relative argument keeps its meaning after any cd. The
# directory may not exist yet, so only the parent is resolved.
absolute() {
    case $1 in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$(pwd)" "$1" ;;
    esac
}
DATA_DIR=$(absolute "$DATA_DIR")
BIN_DIR=$(absolute "$BIN_DIR")

# ---- detect ----------------------------------------------------------------

# What is wrong with each runtime, by version and not just by presence.
missing=''
add_missing() { missing="$missing
  - $1"; }

if ! command -v php >/dev/null 2>&1; then
    add_missing 'PHP 8.3 or newer (php not found)'
elif ! php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' >/dev/null 2>&1; then
    add_missing "PHP 8.3 or newer (found $(php -r 'echo PHP_VERSION;' 2>/dev/null))"
fi
if ! command -v node >/dev/null 2>&1; then
    add_missing 'Node 24 or newer (node not found)'
elif ! node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 24 ? 0 : 1)' >/dev/null 2>&1; then
    add_missing "Node 24 or newer (found $(node --version 2>/dev/null))"
fi
if ! command -v python3 >/dev/null 2>&1; then
    add_missing 'Python 3.11 or newer (python3 not found)'
elif ! python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)' >/dev/null 2>&1; then
    add_missing "Python 3.11 or newer (found $(python3 --version 2>&1))"
fi

# A container runtime counts only when its daemon answers: a docker binary
# with a stopped daemon cannot run anything.
container_runtime=''
for candidate in docker podman; do
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" info >/dev/null 2>&1; then
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

# ---- release source --------------------------------------------------------

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

# Where an asset of the chosen version lives. A release URL keeps each version
# under download/v<version>/; a local directory is flat.
asset_location() {
    if [ "$REMOTE" = 1 ]; then
        printf '%s/download/v%s/%s' "$BASE" "$VERSION" "$1"
    else
        printf '%s/%s' "$BASE" "$1"
    fi
}

# fetch ASSET DESTINATION: copy or download one release asset. A plain copy
# serves a local directory because `curl file://` is not available everywhere.
fetch() {
    location=$(asset_location "$1")
    if [ "$REMOTE" = 1 ]; then
        curl --fail --silent --show-error --location --retry 3 --output "$2" "$location" \
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
            --write-out '%{url_effective}' "$BASE/latest") \
            || die "could not find the latest release at $BASE/latest"
        tag=${final##*/}
        VERSION=${tag#v}
        [ -n "$VERSION" ] || die "could not read a version from $final"
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
}

[ -n "$VERSION" ] || resolve_latest
ASSET="knossos-$VERSION-$OS-$ARCH.tar.gz"

# ---- dry run ---------------------------------------------------------------

if [ "$DRY_RUN" = 1 ]; then
    say 'Dry run: nothing is created or changed.'
    log "mode:       $MODE"
    log "version:    $VERSION"
    log "asset:      $ASSET"
    log "from:       $(asset_location "$ASSET")"
    log "data dir:   $DATA_DIR"
    log "unpack to:  $DATA_DIR/versions/$VERSION"
    log "launcher:   $BIN_DIR/knossos"
    if [ "$PLUGIN" = 1 ]; then
        log 'plugin:     install the plugin and register the MCP server with Claude Code'
    else
        log 'plugin:     skipped (--no-plugin)'
    fi
    exit 0
fi

# ---- download and verify ---------------------------------------------------

TMP=$(mktemp -d "${TMPDIR:-/tmp}/knossos-install.XXXXXX")
PARTIAL=''
cleanup() {
    rm -rf "$TMP"
    if [ -n "$PARTIAL" ]; then rm -rf "$PARTIAL"; fi
}
trap cleanup EXIT
trap 'exit 1' INT TERM HUP

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d ' ' -f 1
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | cut -d ' ' -f 1
    else
        die 'sha256sum or shasum is required to check the download'
    fi
}

VERSION_DIR="$DATA_DIR/versions/$VERSION"
# A complete version is reused: it was checked and doctored when it went in.
version_complete() { [ -x "$VERSION_DIR/bin/knossos" ] && [ -f "$VERSION_DIR/VERSION" ]; }

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

    # Everything in the archive must sit under knossos-<version>/ and stay
    # inside it.
    tar -tzf "$TMP/$ASSET" > "$TMP/listing" 2>/dev/null || die "$ASSET is not a readable archive; nothing was changed"
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
    rm -rf "$PARTIAL"
    mkdir "$PARTIAL"
    tar -xzf "$TMP/$ASSET" -C "$PARTIAL" --strip-components=1 || die "could not unpack $ASSET; nothing was changed"
    if [ ! -x "$PARTIAL/bin/knossos" ] || [ "$(cat "$PARTIAL/VERSION" 2>/dev/null)" != "$VERSION" ]; then
        die "$ASSET does not hold a complete release of $VERSION; nothing was changed"
    fi

    # The new release must run before anything points at it.
    log 'running knossos doctor on the new release'
    mkdir -p "$DATA_DIR"
    if ! KNOSSOS_DATA_DIR="$DATA_DIR" "$PARTIAL/bin/knossos" doctor > "$TMP/doctor.out" 2>&1; then
        cat "$TMP/doctor.out" >&2
        die 'knossos doctor failed on the new release; the previous release, if any, is untouched'
    fi

    # Rename into place. A directory renamed onto an existing one would land
    # inside it, so an existing target (another installer won the race, or an
    # earlier incomplete unpack) is dealt with first.
    if [ -e "$VERSION_DIR" ] && ! version_complete; then rm -rf "$VERSION_DIR"; fi
    if [ -e "$VERSION_DIR" ]; then
        rm -rf "$PARTIAL"
    else
        mv "$PARTIAL" "$VERSION_DIR"
    fi
    PARTIAL=''
fi

# ---- switch ----------------------------------------------------------------

# Point current at the version in one atomic step: build the new symlink under
# a temporary name in the same directory, then rename it over the old one.
# `mv -T` (GNU) treats the destination as a plain name and never moves into the
# directory the old link points at. BSD and macOS mv have no -T, so there the
# rename is done by python3, which native mode requires anyway, through
# os.replace (rename(2), also atomic). `ln -sfn` is the last resort: it
# removes the old link before making the new one, so it is not atomic.
atomic_link() {
    target=$1
    link=$2
    tmp_link="$link.tmp.$$"
    rm -f "$tmp_link"
    ln -s "$target" "$tmp_link"
    if mv -T "$tmp_link" "$link" 2>/dev/null; then
        return 0
    fi
    if python3 -c 'import os, sys; os.replace(sys.argv[1], sys.argv[2])' "$tmp_link" "$link" 2>/dev/null; then
        return 0
    fi
    rm -f "$tmp_link"
    ln -sfn "$target" "$link"
}

atomic_link "versions/$VERSION" "$DATA_DIR/current"
log "current -> versions/$VERSION"

installed_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf '{\n  "mode": "native",\n  "version": "%s",\n  "image": null,\n  "installed_at": "%s"\n}\n' \
    "$VERSION" "$installed_at" > "$DATA_DIR/install.json.tmp.$$"
mv -f "$DATA_DIR/install.json.tmp.$$" "$DATA_DIR/install.json"

# ---- launcher --------------------------------------------------------------

TEMPLATE="$VERSION_DIR/launcher/knossos"
[ -f "$TEMPLATE" ] || die "the release has no launcher template at $TEMPLATE"
mkdir -p "$BIN_DIR"
# The data directory goes into the template inside single quotes, so a quote
# in the path is closed, escaped and reopened.
quoted=$(printf '%s' "$DATA_DIR" | sed "s/'/'\\\\''/g")
launcher_tmp="$BIN_DIR/.knossos.tmp.$$"
while IFS= read -r line || [ -n "$line" ]; do
    case $line in
        "INSTALLED_DATA_DIR='__KNOSSOS_DATA_DIR__'") printf "INSTALLED_DATA_DIR='%s'\n" "$quoted" ;;
        *) printf '%s\n' "$line" ;;
    esac
done < "$TEMPLATE" > "$launcher_tmp"
chmod 0755 "$launcher_tmp"
mv -f "$launcher_tmp" "$BIN_DIR/knossos"
log "launcher written to $BIN_DIR/knossos"

LAUNCHER="$BIN_DIR/knossos"
if installed=$("$LAUNCHER" version 2>&1); then
    log "$installed"
else
    die "the launcher does not run: $installed"
fi

# ---- roots, plugin, MCP ----------------------------------------------------

ROOTS_FILE="${KNOSSOS_ROOTS_FILE:-$DATA_DIR/roots.json}"
if [ -n "${KNOSSOS_ALLOW_ROOT:-}" ]; then
    mkdir -p "$(dirname -- "$ROOTS_FILE")"
    # Merge rather than overwrite, so a second install adds a project and
    # never revokes the first.
    # shellcheck disable=SC2016 # the $-prefixed names in this php -r program are PHP variables.
    KNOSSOS_ROOTS_FILE="$ROOTS_FILE" KNOSSOS_NEW_ROOT="$KNOSSOS_ALLOW_ROOT" php -r '
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
' || warn "could not update the roots file $ROOTS_FILE"
fi

if [ "$PLUGIN" = 1 ]; then
    say 'Installing the Claude Code plugin...'
    if KNOSSOS_DATA_DIR="$DATA_DIR" "$LAUNCHER" install-agent-plugin --data-dir="$DATA_DIR" --execute >/dev/null 2>&1; then
        log 'plugin installed'
    else
        warn "the plugin was not installed; run: $LAUNCHER install-agent-plugin --data-dir=\"$DATA_DIR\" --execute"
    fi
    if command -v claude >/dev/null 2>&1; then
        say 'Registering the MCP server with Claude Code...'
        claude mcp remove "$NAME" -s "$SCOPE" >/dev/null 2>&1 || true
        # No --allow-root: the roots file governs. KNOSSOS_ROOTS_FILE is set
        # too, so the server reads the file named here.
        if claude mcp add "$NAME" -s "$SCOPE" \
            -e "KNOSSOS_DATA_DIR=$DATA_DIR" -e "KNOSSOS_ROOTS_FILE=$ROOTS_FILE" -- \
            "$LAUNCHER" serve; then
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

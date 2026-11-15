#!/bin/sh
# Install Knossos from a release, without a checkout.
#
#   install.sh [--mode=native|container] [--version=V] [--data-dir=DIR]
#              [--install-dir=DIR] [--bin-dir=DIR] [--no-plugin] [--no-roots]
#              [--dry-run] [--force-clean]
#
# Native mode needs PHP 8.3+ with the json, mbstring, pdo, pdo_sqlite and
# tokenizer extensions, Node 24+, Python 3.11+, git, tar and sha256sum or
# shasum on the host: what the release's `knossos doctor` checks, and what
# this script unpacks and checks a release with. It downloads the release
# tarball for this platform, checks it against the release's SHA256SUMS, unpacks it under <data dir>/versions/<version>, points
# <data dir>/current at it and writes one launcher, <bin dir>/knossos, that the
# MCP registration and the plugin's hooks call.
#
# Container mode needs only Docker or Podman, with its daemon running. It pulls
# the release image (ghcr.io/araneadev/knossos:<version>), checks that it
# starts and passes doctor, and writes install.json naming the image by digest
# into the install directory, and the same launcher, which then runs each
# command in a container per project. The plugin's files come out of the
# image. The install directory (--install-dir, default
# ${XDG_DATA_HOME:-~/.local/share}/knossos) holds everything the host trusts
# and lies outside every data directory; the data directory holds the graph
# only, and must be empty, new, or hold only what Knossos puts there, since
# every container can write it. Without --mode, native mode is chosen when the
# host has all of the above, else container mode when a container runtime
# answers.
#
# --no-plugin skips both the plugin and the MCP registration, in either mode.
#
# Environment:
#   KNOSSOS_DATA_DIR      the data directory, when --data-dir is not given: the
#                         install directory and the graph in native mode, the
#                         graph only in container mode          (default: ~/.knossos)
#   KNOSSOS_INSTALL_DIR   container mode: the install directory, when
#                         --install-dir is not given; read at install time only
#                         (default: ${XDG_DATA_HOME:-~/.local/share}/knossos)
#   KNOSSOS_ROOTS_FILE    the roots file, when --data-dir is not given
#                         (default: <data dir>/roots.json)
#   KNOSSOS_BIN_DIR       where the launcher goes               (default: ~/.local/bin)
#   KNOSSOS_RELEASE_BASE  download base: a releases URL, a file:// URL or a
#                         plain directory holding the tarball and SHA256SUMS
#                         (default: https://github.com/AraneaDev/knossos/releases)
#   KNOSSOS_ALLOW_ROOT    a project root to add to the roots file (default: the
#                         directory the installer is run from, see --no-roots)
#   KNOSSOS_MCP_NAME      MCP server name                       (default: knossos)
#   KNOSSOS_MCP_SCOPE     claude mcp scope: local|user|project  (default: user)
#   KNOSSOS_IMAGE         container mode: the image to install instead of
#                         <repository>:<version>, for example one built here
#   KNOSSOS_IMAGE_REPOSITORY  container mode: where release images come from
#                         (default: ghcr.io/araneadev/knossos)
#
# Nothing changes until the download is checked and the new release has passed
# `knossos doctor` (against a throwaway data dir, so the real database is never
# migrated by a release that is not installed yet); a failed install leaves the
# previous release working. The checksum guards against a corrupted or
# truncated download; it comes from the same origin as the tarball, so it does
# not protect against a compromised release origin.
#
# Everything below sits in one brace group that ends with `main "$@"; exit`.
# The shell has to read the closing brace before it runs any of it, so a
# download cut off anywhere, even between main and its arguments, runs nothing.
# Keep `main "$@"` inside the group. The group is not indented, to keep the
# file short.
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
                  [--install-dir=DIR] [--bin-dir=DIR] [--no-plugin] [--no-roots]
                  [--dry-run] [--force-clean]

  --install-dir  container mode: where the record, the update helper, the
                 plugin, the roots list and the settings go (default:
                 ${XDG_DATA_HOME:-~/.local/share}/knossos); run the installer
                 again with the same one to repair an installation
  --no-plugin    skip the Claude Code plugin and the MCP server registration
                 with it: no plugin is written and claude is never called
  --no-roots     do not add the directory the installer runs from to the roots file
  --force-clean  remove the other mode's entries from the data directory first
USAGE
}

# Absolute paths, so a relative argument keeps its meaning after any cd. The
# directory may not exist yet, so nothing is resolved.
absolute() {
    case $1 in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$(pwd)" "$1" ;;
    esac
}

# A version is digits and dots with an optional pre-release suffix after a
# hyphen. It ends up in paths and URLs, so anything else is refused.
validate_version() {
    case $1 in
        '' | *[!0-9A-Za-z.-]* | *..* | -* | .* | [!0-9]*) die "not a valid version: $1" ;;
    esac
    core=${1%%-*}
    case $core in
        *[!0-9.]* | *.) die "not a valid version: $1" ;;
    esac
}

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
# Every child gets /dev/null as stdin: under `curl | sh` the script itself is
# stdin, and a child that read it would eat the rest of the script.
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
        # Ordered by PHP's version_compare, exactly as self-update orders a
        # directory (GitHubReleaseSource::latestInDirectory): a final release
        # comes after its pre-releases, and a name that is no version is
        # skipped.
        # shellcheck disable=SC2016 # the $-prefixed names in this php -r program are PHP variables.
        newest=$(
            for file in "$BASE"/knossos-*-"$OS"-"$ARCH".tar.gz; do
                [ -e "$file" ] || continue
                stem=${file##*/knossos-}
                printf '%s\n' "${stem%-"$OS"-"$ARCH".tar.gz}"
            done | php -r '
$newest = null;
foreach (file("php://stdin", FILE_IGNORE_NEW_LINES) ?: [] as $version) {
    if (preg_match("/^[0-9]+(\\.[0-9]+)*(-[0-9A-Za-z.-]+)?\$/", $version) === 1 && ($newest === null || version_compare($version, $newest, ">"))) {
        $newest = $version;
    }
}
echo $newest ?? "";
'
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

# Runs on every exit. After the switch has begun (SWITCHED=1) a failure of any
# kind, including an interrupt, puts the previous release back; the flag is
# cleared once the installed launcher has answered. Temporary files left beside
# the real ones go either way.
cleanup() {
    if [ "$SWITCHED" = 1 ]; then rollback_switch || true; fi
    if [ "$CONTAINER_SWITCHED" = 1 ]; then rollback_container_switch || true; fi
    rm -rf "$TMP"
    # An install directory this run made, for an install that did not
    # complete: nothing would use it.
    if [ "$CINSTALL_MADE" = 1 ] && [ -n "$CINSTALL" ]; then rm -rf "$CINSTALL"; fi
    if [ -n "$PROBE" ]; then rm -rf "$PROBE"; fi
    remove_partial
    if [ -n "$BIN_DIR" ]; then rm -f "$BIN_DIR/.knossos.tmp.$$"; fi
    if [ -n "$DATA_DIR" ]; then rm -f "$DATA_DIR/install.json.tmp.$$" "$DATA_DIR/current.tmp.$$" "$DATA_DIR/previous-version.tmp.$$"; fi
}

# Point a link at a target in one atomic step: build the new symlink under a
# temporary name in the same directory, then rename it over the old one.
# `mv -T` (GNU) treats the destination as a plain name and never moves into the
# directory the old link points at. BSD and macOS mv have no -T, so there
# python3 does the rename through os.replace (rename(2), also atomic), which
# native mode requires anyway. `ln -sfn` is the last resort: it removes the old
# link before making the new one, so it is not atomic.
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

# Write the launcher from the release's template to PATH. The install
# directory goes into the template inside single quotes, so a quote in the
# path is closed, escaped and reopened. self-update does the same in PHP
# (Knossos\Install\Launcher), byte for byte.
write_launcher() {
    quoted=$(printf '%s' "$DATA_DIR" | sed "s/'/'\\\\''/g")
    baked=0
    while IFS= read -r line || [ -n "$line" ]; do
        case $line in
            "INSTALL_DIR='__KNOSSOS_INSTALL_DIR__'")
                printf "INSTALL_DIR='%s'\n" "$quoted"
                baked=1
                ;;
            *) printf '%s\n' "$line" ;;
        esac
    done < "$TEMPLATE" > "$1"
    chmod 0755 "$1"
    [ "$baked" = 1 ]
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

# Whether a process is running. A process of another user answers kill -0
# with a permission error, which still means it is there; that is what
# VersionSwitch::alive concludes too.
process_alive() {
    kill -0 "$1" 2>/dev/null && return 0
    case $(LC_ALL=C kill -0 "$1" 2>&1) in
        *ermitted* | *ermission*) return 0 ;;
    esac
    return 1
}

# Record the version this run replaced as the one to roll back to, and drop
# every other version, the way self-update does (VersionSwitch::prune): kept
# are the new version and the previous one, and partial unpacks of runs that
# are still alive; a partial whose run is gone is removed. Reinstalling the
# version that is already current replaces nothing, so the version recorded
# before stays the previous one.
record_previous_and_prune() {
    replaced=''
    case $PREV_LINK in
        versions/*/* | ./versions/*/*) ;;
        versions/* | ./versions/*) replaced=${PREV_LINK#./}; replaced=${replaced#versions/} ;;
    esac
    if [ -n "$replaced" ] && [ "$replaced" != "$VERSION" ] && [ -d "$DATA_DIR/versions/$replaced" ]; then
        if ! { printf '%s\n' "$replaced" > "$DATA_DIR/previous-version.tmp.$$" \
            && mv -f "$DATA_DIR/previous-version.tmp.$$" "$DATA_DIR/previous-version"; }; then
            rm -f "$DATA_DIR/previous-version.tmp.$$"
            warn 'could not record the previous version for knossos self-update --rollback'
        fi
        keep=$replaced
    else
        keep=$(cat "$DATA_DIR/previous-version" 2>/dev/null || true)
    fi
    for dir in "$DATA_DIR"/versions/*; do
        if [ ! -d "$dir" ] || [ -L "$dir" ]; then continue; fi
        name=${dir##*/}
        case $name in
            *.partial.*)
                pid=${name##*.partial.}
                case $pid in '' | *[!0-9]*) continue ;; esac
                if process_alive "$pid"; then continue; fi
                ;;
            *)
                if [ "$name" = "$VERSION" ] || [ "$name" = "$keep" ]; then continue; fi
                ;;
        esac
        chmod -R u+rwx "$dir" 2>/dev/null || true
        rm -rf "$dir" || warn "could not remove the old version $dir"
    done
}

# A JSON string for a path: quote and backslash escaped. Control characters
# never get here, main refuses them in both directories.
json_string() {
    printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g')"
}

# Merge a project root into the roots file, never overwriting what is there.
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

# Which directory, if any, to grant. An explicit KNOSSOS_ALLOW_ROOT always
# wins. Otherwise the directory the installer ran from is granted, except when
# nobody is at a terminal and that directory is / or the home directory: a
# piped install from there says nothing about which project was meant.
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

# Register the MCP server with Claude Code, pointing at the launcher. Without a
# claude on PATH, say how to do it later.
register_mcp() {
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
}

# The PATH hint and the last line, for both modes.
finish() {
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

# Say what the launcher about to be written replaces, when it is not this
# installation's own launcher: another file, a link (and where it points), or
# the launcher of another installation.
note_replaced_launcher() {
    if [ -L "$LAUNCHER" ]; then
        log "replacing $LAUNCHER, which was a link to $(readlink "$LAUNCHER")"
    elif [ -d "$LAUNCHER" ]; then
        die "$LAUNCHER is a directory; move it away and run the installer again"
    elif [ -f "$LAUNCHER" ]; then
        previous=$(sed -n "s/^INSTALL_DIR='\(.*\)'\$/\1/p" "$LAUNCHER" 2>/dev/null | head -n 1 | sed "s/'\\\\''/'/g")
        if [ -z "$previous" ]; then
            log "replacing $LAUNCHER, a file that was not a Knossos launcher"
        elif [ "$previous" != "${BAKED_DIR:-$DATA_DIR}" ]; then
            log "replacing $LAUNCHER, the launcher of the Knossos installed in $previous"
        fi
    elif [ -e "$LAUNCHER" ]; then
        die "$LAUNCHER exists and is not a file or a link; move it away and run the installer again"
    fi
}

# version_gt A B: whether A is a newer version than B, without PHP: numeric
# parts as numbers, then a final release after its pre-releases, which compare
# as text. tools/launcher/container-update has the same rule.
version_gt() {
    a=${1%%-*}
    b=${2%%-*}
    while [ -n "$a" ] || [ -n "$b" ]; do
        x=${a%%.*}
        y=${b%%.*}
        case $a in *.*) a=${a#*.} ;; *) a='' ;; esac
        case $b in *.*) b=${b#*.} ;; *) b='' ;; esac
        x=${x:-0}
        y=${y:-0}
        [ "$x" -gt "$y" ] && return 0
        [ "$x" -lt "$y" ] && return 1
    done
    as=''
    bs=''
    case $1 in *-*) as=${1#*-} ;; esac
    case $2 in *-*) bs=${2#*-} ;; esac
    if [ -z "$as" ]; then [ -n "$bs" ]; return; fi
    [ -n "$bs" ] || return 1
    [ "$as" != "$bs" ] && [ "$(printf '%s\n%s\n' "$as" "$bs" | LC_ALL=C sort | tail -n 1)" = "$as" ]
}

# run_once ARGS: every run of an image this installer makes, in one place: a
# throwaway container as this user, with no network. Rootless Podman maps the
# caller's ids into the container only with --userns=keep-id, as the launcher
# and the update helper do too.
run_once() {
    if [ "$RUNTIME" = podman ]; then
        "$RUNTIME" run --rm --pull=never --network none --user "$(id -u):$(id -g)" --userns=keep-id "$@"
    else
        "$RUNTIME" run --rm --pull=never --network none --user "$(id -u):$(id -g)" "$@"
    fi
}

# A throwaway run of the image as this user: no network, no mount, a scratch
# data directory inside the container.
run_image() {
    image=$1
    shift
    run_once -e HOME=/tmp \
        -e KNOSSOS_DATA_DIR=/tmp/knossos-check -e KNOSSOS_ROOTS_FILE=/tmp/knossos-check/roots.json \
        --entrypoint /opt/knossos/bin/knossos "$image" "$@" </dev/null
}

# Undo a container install after the helper switched but the launcher did not
# answer: install.json, the launcher, the update helper's copy and the plugin
# as they were.
rollback_container_switch() {
    if [ -n "$LAUNCHER" ] && [ -f "$LAUNCHER" ]; then
        (cd "$PROBE/probe project" 2>/dev/null && "$LAUNCHER" stop >/dev/null 2>&1 </dev/null) || true
    fi
    if [ -f "$TMP/install.json.prev" ]; then cp "$TMP/install.json.prev" "$CINSTALL/install.json"; else rm -f "$CINSTALL/install.json"; fi
    if [ -f "$TMP/launcher.prev" ]; then cp "$TMP/launcher.prev" "$LAUNCHER"; else rm -f "$LAUNCHER"; fi
    if [ -f "$TMP/helper.prev" ]; then cp "$TMP/helper.prev" "$CINSTALL/container-update"; else rm -f "$CINSTALL/container-update"; fi
    if [ "$PLUGIN" = 1 ] && [ -n "$CINSTALL" ]; then
        rm -rf "$CINSTALL/plugin"
        if [ -d "$TMP/plugin.prev" ]; then cp -pR "$TMP/plugin.prev" "$CINSTALL/plugin"; else mkdir -p "$CINSTALL/plugin"; fi
    fi
}

# container_install_of DIR: the install directory of the container install
# whose graph directory DIR is, when that install lies in the install
# directory this run would use (the default one, or the one --install-dir or
# KNOSSOS_INSTALL_DIR names): its install.json records DIR as its data
# directory, or one of its graph records names DIR. Read from the install
# directory, where no container writes. Nothing otherwise.
container_install_of() {
    [ -n "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/install.json" ] && [ ! -L "$INSTALL_DIR/install.json" ] || return 0
    grep -q '"mode"[[:space:]]*:[[:space:]]*"container"' "$INSTALL_DIR/install.json" 2>/dev/null || return 0
    dir_real=$(CDPATH='' cd -P -- "$1" 2>/dev/null && pwd -P) || return 0
    recorded=$(sed -n 's/^[[:space:]]*"data_dir"[[:space:]]*:[[:space:]]*"\(.*\)",\{0,1\}$/\1/p' "$INSTALL_DIR/install.json" 2>/dev/null | head -n 1 | sed 's/\\\(.\)/\1/g')
    if [ "$recorded" = "$dir_real" ]; then printf '%s' "$INSTALL_DIR"; return 0; fi
    for record in "$INSTALL_DIR"/containers/graph-*; do
        [ -f "$record" ] || continue
        if [ "$(head -n 1 "$record" 2>/dev/null)" = "$dir_real" ]; then printf '%s' "$INSTALL_DIR"; return 0; fi
    done
}

# The entries of the other mode in the data directory, which this install
# would otherwise trust. A container install gives every container its data
# directory read-write, so once one has used it, nothing a native install
# keeps directly in it (install.json, versions/, current, plugin/,
# previous-version) can be trusted: a container could have put it there.
# Without a container install, those are trusted only when the native
# install.json beside them vouches for them. A container install, the other
# way round, does not install over a native one, and keeps its roots list and
# settings in its install directory, so a native install's roots.json,
# enrichers.json and pane-autostart.json beside the graph are listed too. The
# graph database and everything else are never touched.
other_mode_entries() {
    found=''
    native_entries='install.json versions current plugin previous-version'
    if [ "$MODE" = container ]; then
        for entry in $native_entries roots.json enrichers.json pane-autostart.json; do
            if [ -e "$DATA_DIR/$entry" ] || [ -L "$DATA_DIR/$entry" ]; then found="$found $entry"; fi
        done
    elif [ -e "$DATA_DIR/install" ] || [ -L "$DATA_DIR/install" ]; then
        found=' install'
        for entry in $native_entries; do
            if [ -e "$DATA_DIR/$entry" ] || [ -L "$DATA_DIR/$entry" ]; then found="$found $entry"; fi
        done
    elif ! grep -q '"mode"[[:space:]]*:[[:space:]]*"native"' "$DATA_DIR/install.json" 2>/dev/null; then
        for entry in $native_entries; do
            if [ -e "$DATA_DIR/$entry" ] || [ -L "$DATA_DIR/$entry" ]; then found="$found $entry"; fi
        done
    fi
    printf '%s' "${found# }"
}

refuse_other_mode() {
    if [ "$MODE" = native ] && [ -d "$DATA_DIR" ]; then
        owner=$(container_install_of "$DATA_DIR")
        if [ -n "$owner" ]; then
            die "$DATA_DIR is the graph directory of the container install in $owner, whose containers can write it, so a native install there would run what they could plant; remove that container install (its install directory $owner and its launcher) first, or pick another --data-dir. Nothing was changed."
        fi
    fi
    entries=$(other_mode_entries)
    [ -n "$entries" ] || return 0
    if [ "$MODE" = container ]; then what='a native install'; else what='a container install, or files its own install.json does not vouch for'; fi
    if [ "$FORCE_CLEAN" = 0 ] || [ "$DRY_RUN" = 1 ]; then
        die "$DATA_DIR holds $what ($entries); remove those entries or pick another --data-dir, or re-run with --force-clean to remove exactly them."
    fi
    # In container mode the data directory may lie in a directory a container
    # of some installation can write, where a link could have been planted to
    # point --force-clean at a folder of yours: it removes only in a data
    # directory named by its own physical path.
    if [ "$MODE" = container ]; then
        named=$DATA_DIR
        while [ "${#named}" -gt 1 ] && [ "${named%/}" != "$named" ]; do named=${named%/}; done
        resolved=$(CDPATH='' cd -P -- "$DATA_DIR" 2>/dev/null && pwd -P) || resolved=''
        if [ "$resolved" != "$named" ]; then
            die "--force-clean removes entries only in a data directory named by its physical path, and $DATA_DIR goes through a symbolic link${resolved:+ to $resolved}; check that this is the directory you mean and run the installer again with --data-dir=${resolved:-<its physical path>}. Nothing was changed."
        fi
    fi
    for entry in $entries; do
        rm -rf -- "${DATA_DIR:?}/$entry" || die "could not remove $DATA_DIR/$entry"
        log "removed $DATA_DIR/$entry (--force-clean)"
    done
}

# What a data directory may hold before a container install takes it as its
# graph directory, which every container gets read-write: nothing, or only
# what Knossos itself puts in one. A folder holding anything of yours (a
# folder of repositories, say) would hand every scanned project write access
# to it, git hooks included. The two lists and the two functions after them
# are the launcher's (tools/launcher/knossos, "what a graph directory may
# hold"), word for word, which says where each entry comes from;
# tests/shell/install-container.sh compares them.
# shellcheck disable=SC2016 # the lists are data, never expanded
GRAPH_ENTRIES='
knossos.sqlite file
knossos.sqlite-wal file
knossos.sqlite-shm file
knossos.sqlite-journal file
knossos.sqlite.migrate.lock file
scan-failures.jsonl file
scan-failures.jsonl.lock file
scan-failures.jsonl.*.tmp file
watch files
backups files
mcp-serve.log file
.knossos-files.* files
'
NATIVE_ENTRIES='
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
roots.json
roots.json.lock
.roots.json.*.tmp
enrichers.json
.enrichers.json.*.tmp
pane-autostart.json
.pane-autostart.json.*.tmp
'

# entry_kind NAME: `graph SHAPE` or `native` for a name on one of the lists
# above, nothing for any other.
entry_kind() {
    while read -r pattern shape; do
        [ -n "$pattern" ] || continue
        # shellcheck disable=SC2254 # the pattern is meant to match as a pattern
        case $1 in $pattern) printf 'graph %s' "$shape"; return 0 ;; esac
    done <<LIST
$GRAPH_ENTRIES
LIST
    while read -r pattern; do
        [ -n "$pattern" ] || continue
        # shellcheck disable=SC2254 # the pattern is meant to match as a pattern
        case $1 in $pattern) printf 'native'; return 0 ;; esac
    done <<LIST
$NATIVE_ENTRIES
LIST
}

# entry_has_shape PATH SHAPE: whether PATH is what Knossos makes under that
# shape (see GRAPH_ENTRIES). Nothing is followed: a link is never the shape.
entry_has_shape() {
    [ ! -L "$1" ] || return 1
    case $2 in
        file) [ -f "$1" ] ;;
        files)
            [ -d "$1" ] && [ -r "$1" ] && [ -x "$1" ] || return 1
            inner=$(find "$1/." ! -name . -prune ! -type f -print 2>/dev/null) || return 1
            [ -z "$inner" ]
            ;;
        *) return 1 ;;
    esac
}

# foreign_entry DIR [native-ok]: the first entry directly in DIR that is not
# on GRAPH_ENTRIES in its shape: `native NAME` for one on NATIVE_ENTRIES,
# `shape NAME SHAPE` for a listed name in another shape, `other NAME` for
# anything else; nothing when there is none. With `native-ok`, entries on
# NATIVE_ENTRIES are passed over. A name holding a newline is never one
# Knossos makes.
foreign_entry() {
    find "$1/." ! -name . -prune -print 2>/dev/null | LC_ALL=C sort | while IFS= read -r found; do
        case $found in
            "${1%/}/./"*) name=${found#"${1%/}/./"} ;;
            *)
                printf 'other %s' 'an entry whose name holds a newline'
                break
                ;;
        esac
        kind=$(entry_kind "$name")
        case $kind in
            graph\ *)
                entry_has_shape "${1%/}/$name" "${kind#graph }" && continue
                printf 'shape %s %s' "${kind#graph }" "$name"
                break
                ;;
            native)
                [ "${2-}" != native-ok ] || continue
                printf 'native %s' "$name"
                break
                ;;
            *) printf 'other %s' "$name"; break ;;
        esac
    done
}

# shape_text SHAPE: the shape in words, for a refusal.
shape_text() {
    case $1 in
        file) printf 'a plain file' ;;
        files) printf 'a directory that holds plain files only' ;;
        *) printf 'what Knossos makes' ;;
    esac
}

# refuse_foreign_entries DIR: refuse a container install into DIR when DIR
# holds an entry that is on neither list. A native install's entries are left
# to refuse_other_mode, which names them all and offers --force-clean.
refuse_foreign_entries() {
    [ -d "$1" ] || return 0
    foreign=$(foreign_entry "$1" native-ok)
    case $foreign in
        shape\ *)
            shape=${foreign#shape }
            die "the data directory $1 holds ${shape#* }, which Knossos only makes as $(shape_text "${shape%% *}"), and in container mode every container would be able to write all of it; choose an empty directory with --data-dir (or KNOSSOS_DATA_DIR). Nothing was changed."
            ;;
        other\ *) die "the data directory $1 holds ${foreign#other }, which is not something Knossos puts in a data directory, and in container mode every container would be able to write all of it; move it out of $1 (when a container left it there, nothing in it is used), or choose an empty directory with --data-dir (or KNOSSOS_DATA_DIR). Nothing was changed." ;;
    esac
}

# What an install directory may hold: what the installer, the launcher and the
# update helper put there. Every container is given it read-only, so a
# directory holding anything else (a folder of yours) would be shown to every
# scanned project. Patterns, one per line.
# shellcheck disable=SC2016 # the list is data, never expanded
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

# refuse_install_entries DIR: refuse an install directory that already holds
# anything an install directory never holds, or the record of a native
# install. One that holds a container install's record is that installation,
# repaired in place: its roots list and settings are kept.
refuse_install_entries() {
    [ -e "$1" ] || [ -L "$1" ] || return 0
    [ -d "$1" ] || die "the install directory $1 exists and is not a directory; choose another --install-dir. Nothing was changed."
    if { [ -e "$1/install.json" ] || [ -L "$1/install.json" ]; } && { [ -L "$1/install.json" ] || [ ! -f "$1/install.json" ]; }; then
        die "the install directory $1 holds an install.json that is not a plain file; choose another --install-dir, or remove $1/install.json if nothing uses that directory. Nothing was changed."
    fi
    if [ -f "$1/install.json" ] && ! grep -q '"mode"[[:space:]]*:[[:space:]]*"container"' "$1/install.json" 2>/dev/null; then
        die "the install directory $1 holds an install.json that records no container install (a native install keeps its release there); choose another --install-dir. Nothing was changed."
    fi
    odd=$(find "$1/." ! -name . -prune -print 2>/dev/null | LC_ALL=C sort | while IFS= read -r found; do
        name=${found#"${1%/}/./"}
        known=no
        while read -r pattern; do
            [ -n "$pattern" ] || continue
            # shellcheck disable=SC2254 # the pattern is meant to match as a pattern
            case $name in $pattern) known=yes ;; esac
        done <<LIST
$INSTALL_ENTRIES
LIST
        if [ "$known" = no ]; then printf '%s' "$name"; break; fi
    done)
    [ -z "$odd" ] || die "the install directory $1 holds $odd, which is not something Knossos keeps there, and every container would be given it read-only; choose an empty or new directory with --install-dir. Nothing was changed."
}

# dir_id DIR: "<device>:<inode>" of DIR itself, or ":<inode>", as the
# launcher reads it (a symlink is not followed).
dir_id() {
    # GNU coreutils (Linux) and BusyBox: stat -c.
    id=$(stat -c '%d:%i' -- "$1" 2>/dev/null) || id=''
    case $id in [0-9]*:[0-9]*) case $id in *[!0-9:]*) ;; *) printf '%s' "$id"; return 0 ;; esac ;; esac
    # BSD stat (macOS, FreeBSD, OpenBSD, NetBSD): stat -f, where %d is the
    # device and %i the inode, as with -c above.
    id=$(stat -f '%d:%i' -- "$1" 2>/dev/null) || id=''
    case $id in [0-9]*:[0-9]*) case $id in *[!0-9:]*) ;; *) printf '%s' "$id"; return 0 ;; esac ;; esac
    # Neither (a system without a stat command): POSIX ls -di gives the inode
    # alone; the device is left out of the comparison.
    id=$(ls -di -- "$1" 2>/dev/null) || id=''
    id=${id#"${id%%[! ]*}"}
    id=${id%%[! 0-9]*}
    id=${id%% *}
    case $id in '' | *[!0-9]*) return 1 ;; esac
    printf ':%s' "$id"
}

# physical_of PATH: the physical form of a path that may not exist yet: its
# deepest existing ancestor resolved, the rest appended as written. Fails for a
# missing part spelled `.` or `..`, which mkdir -p would resolve differently.
physical_of() {
    p=$1
    while [ "${#p}" -gt 1 ] && [ "${p%/}" != "$p" ]; do p=${p%/}; done
    rest=''
    while [ ! -d "$p" ]; do
        case ${p##*/} in . | ..) return 1 ;; esac
        rest="/${p##*/}$rest"
        p=${p%/*}
        [ -n "$p" ] || p=/
    done
    base=$(CDPATH='' cd -P -- "$p" 2>/dev/null && pwd -P) || return 1
    base="${base%/}$rest"
    printf '%s' "${base:-/}"
}

# within PATH DIR: whether PATH is DIR or lies under it.
within() {
    [ "$2" = / ] && return 0
    case $1 in "$2" | "$2"/*) return 0 ;; esac
    return 1
}

# Container mode only. A directory becomes a project root as soon as knossos
# runs there, and a project's files come from wherever it was cloned from. So
# neither the data directory, nor the install directory, nor the launcher's
# directory may lie inside the directory the installer runs from or the root
# it grants, and neither the launcher nor the install directory may lie
# inside the data directory, which every container can write. The launcher
# refuses those layouts too, at every call (see the threat note at its top).
# / and the home directory never become a project root, so directories below
# them are fine.
refuse_writable_layout() {
    data=$(physical_of "$DATA_DIR") || die "spell --data-dir without '.' or '..' in a part that does not exist yet: $DATA_DIR"
    bin=$(physical_of "$BIN_DIR") || die "spell --bin-dir without '.' or '..' in a part that does not exist yet: $BIN_DIR"
    inst=$(physical_of "$INSTALL_DIR") || die "spell --install-dir without '.' or '..' in a part that does not exist yet: $INSTALL_DIR"
    if within "$bin" "$data"; then
        die "the directory of the knossos launcher $bin lies inside the Knossos data directory $data, which a container can write; re-run the installer with --bin-dir outside the data directory."
    fi
    home=''
    if [ -n "${HOME:-}" ] && [ -d "$HOME" ]; then home=$(CDPATH='' cd -P -- "$HOME" && pwd -P); fi
    for project in "$(pwd -P)" "$ROOT_TO_GRANT"; do
        if [ -z "$project" ] || [ ! -d "$project" ]; then continue; fi
        project=$(CDPATH='' cd -P -- "$project" && pwd -P) || continue
        [ "$project" != / ] || continue
        if [ -n "$home" ] && within "$home" "$project"; then continue; fi
        if within "$data" "$project"; then
            die "$project contains the Knossos data directory $data, which a container must never be able to write once $project is a project root; re-run the installer with --data-dir outside the project, or run it from another directory."
        fi
        if within "$inst" "$project"; then
            die "$project contains the Knossos install directory $inst, which holds what the host trusts and must never be part of a project; re-run the installer with --install-dir outside the project, or run it from another directory."
        fi
        if within "$bin" "$project"; then
            die "$project contains $bin, the directory of the knossos launcher, which a container must never be able to write once $project is a project root; re-run the installer with --bin-dir outside the project, or run it from another directory."
        fi
    done
}

# refuse_install_dir: the install directory may lie in no read-write mount
# and in no data directory, and holds what every container sees read-only, so
# it is refused when it is too wide to show, the bin directory or around it,
# in or around the data directory, below a directory that holds a Knossos
# graph, in a graph directory this installation recorded, or in a read-write
# mount of a container that runs now. Asked again once the directories exist.
refuse_install_dir() {
    inst=$(physical_of "$INSTALL_DIR") || die "spell --install-dir without '.' or '..' in a part that does not exist yet: $INSTALL_DIR"
    data=$(physical_of "$DATA_DIR") || die "spell --data-dir without '.' or '..' in a part that does not exist yet: $DATA_DIR"
    bin=$(physical_of "$BIN_DIR") || die "spell --bin-dir without '.' or '..' in a part that does not exist yet: $BIN_DIR"
    advice='choose a directory of its own with --install-dir (the default is ~/.local/share/knossos)'
    home=''
    if [ -n "${HOME:-}" ] && [ -d "$HOME" ]; then home=$(CDPATH='' cd -P -- "$HOME" && pwd -P); fi
    if [ "$inst" = / ]; then
        die "the install directory / is the filesystem root, which every container would be given read-only; $advice. Nothing was changed."
    fi
    if [ -n "$home" ] && within "$home" "$inst"; then
        die "the install directory $inst is your home directory or contains it, which every container would be given read-only; $advice. Nothing was changed."
    fi
    if within "$bin" "$inst"; then
        die "the install directory $inst is or contains the bin directory $bin, whose programs every container would be given read-only; $advice. Nothing was changed."
    fi
    if within "$inst" "$data"; then
        die "the install directory $inst lies in the data directory $data, which every container can write; $advice, outside every data directory. Nothing was changed."
    fi
    if within "$data" "$inst"; then
        die "the data directory $data lies in the install directory $inst; choose a --data-dir outside it, or another --install-dir. Nothing was changed."
    fi
    # From / down to the install directory: a directory holding a graph
    # database is some installation's data directory.
    at=/
    rest=${inst#/}
    while :; do
        for entry in knossos.sqlite knossos.sqlite-wal; do
            if [ -e "${at%/}/$entry" ] || [ -L "${at%/}/$entry" ]; then
                die "the install directory $inst lies in $at, which holds a Knossos graph ($entry): a data directory, which containers can write; $advice, outside every data directory. Nothing was changed."
            fi
        done
        [ -n "$rest" ] || break
        at="${at%/}/${rest%%/*}"
        case $rest in */*) rest=${rest#*/} ;; *) rest='' ;; esac
        [ -d "$at" ] || break
    done
    for record in "$inst"/containers/graph-*; do
        [ -f "$record" ] || continue
        graph=$(head -n 1 "$record" 2>/dev/null)
        case $graph in /?*) ;; *) continue ;; esac
        if within "$inst" "$graph"; then
            die "the install directory $inst lies in $graph, a graph directory this installation used ($record), which containers can write; $advice, outside it. Nothing was changed."
        fi
    done
    # What the runtime says: a running Knossos container whose read-write
    # mount holds the install directory could rewrite it.
    [ -n "${container_runtime:-}" ] || return 0
    # A runtime that cannot be asked gives no answer, which is not "none".
    names=$("$container_runtime" ps --filter label=io.knossos.install --format '{{.Names}}' 2>/dev/null </dev/null) \
        || die "could not ask $container_runtime which containers run, so the installer cannot tell whether one can write the install directory $inst; check that $container_runtime ps works and run the installer again. Nothing was changed."
    for name in $names; do
        sources=$("$container_runtime" container inspect --format '{{range .Mounts}}{{if .RW}}{{.Source}}{{"\n"}}{{end}}{{end}}' "$name" 2>/dev/null </dev/null) || continue
        while IFS= read -r source; do
            case $source in /*) ;; *) continue ;; esac
            if within "$inst" "$source"; then
                die "the install directory $inst lies in $source, which the running Knossos container $name can write; $advice, outside every data directory. Nothing was changed."
            fi
        done <<SOURCES
$sources
SOURCES
    done
}

# Container mode: pull the release image, check it, and let the helper it
# carries (tools/launcher/container-update, the same one self-update runs)
# write install.json with the image's digest, the launcher and the plugin.
install_container() {
    refuse_writable_layout
    refuse_install_dir
    refuse_install_entries "$INSTALL_DIR"
    RUNTIME=$container_runtime
    REPOSITORY=${KNOSSOS_IMAGE_REPOSITORY:-ghcr.io/araneadev/knossos}
    if [ -n "${KNOSSOS_IMAGE:-}" ]; then
        REF=$KNOSSOS_IMAGE
    else
        if [ -z "$VERSION" ]; then
            if [ "$REMOTE" = 1 ]; then
                command -v curl >/dev/null 2>&1 || die 'curl is required to find the latest release; install it or pass --version'
                resolve_latest
            else
                # A local release directory, as the tests use: the newest knossos-<version>-*.tar.gz in it.
                for file in "$BASE"/knossos-*-*.tar.gz; do
                    [ -e "$file" ] || continue
                    stem=${file##*/knossos-}
                    candidate=${stem%-*-*.tar.gz}
                    (validate_version "$candidate") >/dev/null 2>&1 || continue
                    if [ -z "$VERSION" ] || version_gt "$candidate" "$VERSION"; then VERSION=$candidate; fi
                done
                [ -n "$VERSION" ] || die "no release asset knossos-<version>-*.tar.gz in $BASE; pass --version"
            fi
        fi
        REF="$REPOSITORY:$VERSION"
    fi

    if [ "$DRY_RUN" = 1 ]; then
        say 'Dry run: nothing is created or changed.'
        log "mode:       container ($RUNTIME)"
        log "version:    ${VERSION:-the one the image reports}"
        log "image:      $REF"
        log "data dir:   $DATA_DIR (the graph, read-write in every container)"
        if [ -f "$INSTALL_DIR/install.json" ]; then
            log "install:    $INSTALL_DIR (repaired in place: roots and settings kept; read-only to every container)"
        else
            log "install:    $INSTALL_DIR (read-only to every container)"
        fi
        log "launcher:   $LAUNCHER"
        if [ -n "$ROOT_TO_GRANT" ]; then
            log "grants:     $ROOT_TO_GRANT"
        elif [ -n "$ROOT_SKIPPED" ]; then
            log "grants:     no project root ($ROOT_SKIPPED is never mounted in container mode)"
        else
            log 'grants:     no project root'
        fi
        if [ "$PLUGIN" = 1 ]; then
            log "plugin:     write $INSTALL_DIR/plugin from the image, install it and register the MCP server with Claude Code"
        else
            log 'plugin:     skipped (--no-plugin)'
        fi
        exit 0
    fi

    # The install directory: everything the host reads, runs or trusts, made
    # here as real directories before any container exists, outside every
    # data directory. This run's own work files go in it too, never in
    # TMPDIR, which could lie where a container writes. Running the installer
    # again with the same install directory repairs that installation in
    # place: its roots list, settings and graph records stay.
    CINSTALL=$(physical_of "$INSTALL_DIR")
    if [ ! -e "$CINSTALL" ] && [ ! -L "$CINSTALL" ]; then CINSTALL_MADE=1; fi
    trap cleanup EXIT
    trap 'exit 1' INT TERM HUP
    # settings/ holds the enricher list and the pane setting, which only the
    # launcher's own short run of the image may write.
    for entry in "" /plugin /containers /settings /tmp; do
        [ ! -L "$CINSTALL$entry" ] || die "$CINSTALL$entry is a symlink; remove it and run the installer again. Nothing was changed"
        mkdir -p "$CINSTALL$entry" || die "could not create $CINSTALL$entry"
    done
    CINSTALL=$(CDPATH='' cd -P -- "$CINSTALL" && pwd -P) || die "could not enter $CINSTALL"
    INSTALL_DIR=$CINSTALL
    TMP=$(mktemp -d "$CINSTALL/.install.XXXXXX") || die "could not create a work directory in $CINSTALL"

    say "Installing Knossos ${VERSION:-from $REF} (container, $RUNTIME)"
    if "$RUNTIME" image inspect "$REF" >/dev/null 2>&1 </dev/null; then
        log "image $REF is already on this host"
    else
        log "pulling $REF"
        if ! "$RUNTIME" pull "$REF" > "$TMP/pull.out" 2>&1 </dev/null; then
            sed 's/^/  /' "$TMP/pull.out" >&2
            die "could not pull $REF; nothing was changed"
        fi
    fi

    # The image must answer with the version asked for and pass doctor before
    # anything points at it.
    said=$(run_image "$REF" version 2>&1) || die "the image $REF does not start ($said); nothing was changed"
    image_version=${said#Knossos }
    if [ "$said" = "$image_version" ] || ! (validate_version "$image_version") >/dev/null 2>&1; then
        die "the image $REF answered '$said' instead of its version; nothing was changed"
    fi
    if [ -n "$VERSION" ] && [ "$image_version" != "$VERSION" ]; then
        die "the image $REF holds Knossos $image_version, not $VERSION; nothing was changed"
    fi
    VERSION=$image_version
    log 'running knossos doctor in the image'
    if ! run_image "$REF" doctor > "$TMP/doctor.out" 2>&1; then
        cat "$TMP/doctor.out" >&2
        die "knossos doctor failed in $REF; nothing was changed"
    fi
    if ! run_once --entrypoint cat "$REF" /opt/knossos/tools/launcher/container-update \
        > "$TMP/container-update" 2>/dev/null </dev/null || [ ! -s "$TMP/container-update" ]; then
        die "the image $REF has no install helper; it is older than container mode. Nothing was changed"
    fi

    # The graph directory, made now and baked into install.json by its
    # physical path.
    mkdir -p "$DATA_DIR" || die "could not create $DATA_DIR"
    logical_data=$DATA_DIR
    DATA_DIR=$(CDPATH='' cd -P -- "$DATA_DIR" && pwd -P) || die "could not enter $DATA_DIR"
    # Again on the directories as they now are, should a link have moved one.
    refuse_writable_layout
    refuse_install_dir
    refuse_foreign_entries "$DATA_DIR"
    # The allow-list of a container install is the host's, in the install
    # directory, where no container can write it: the launcher chooses a
    # project from it and hands it to every container read-only. A roots
    # file the caller named elsewhere stays theirs.
    if [ "$ROOTS_FILE" = "$logical_data/roots.json" ] || [ "$ROOTS_FILE" = "$DATA_DIR/roots.json" ]; then ROOTS_FILE="$CINSTALL/roots.json"; fi
    KNOSSOS_DATA_DIR=$DATA_DIR
    KNOSSOS_ROOTS_FILE=$ROOTS_FILE
    export KNOSSOS_DATA_DIR KNOSSOS_ROOTS_FILE
    BAKED_DIR=$CINSTALL
    if [ -f "$CINSTALL/install.json" ]; then cp "$CINSTALL/install.json" "$TMP/install.json.prev"; fi
    if [ -f "$LAUNCHER" ]; then cp "$LAUNCHER" "$TMP/launcher.prev"; fi
    if [ -f "$CINSTALL/container-update" ]; then cp "$CINSTALL/container-update" "$TMP/helper.prev"; fi
    if [ "$PLUGIN" = 1 ] && [ -f "$CINSTALL/plugin/hooks/scripts/lib.sh" ]; then cp -pR "$CINSTALL/plugin" "$TMP/plugin.prev"; fi
    # A reinstall that moves the launcher: the old one still names this
    # install directory, so it says where it is.
    old_bin=$(sed -n 's/^[[:space:]]*"bin_dir"[[:space:]]*:[[:space:]]*"\(.*\)",\{0,1\}$/\1/p' "$CINSTALL/install.json" 2>/dev/null | head -n 1 | sed 's/\\\(.\)/\1/g')
    note_replaced_launcher
    set -- install "--install-dir=$CINSTALL" "--bin-dir=$BIN_DIR" "--data-dir=$DATA_DIR" "--runtime=$RUNTIME" "--image=$REF" "--version=$VERSION"
    if [ "$PLUGIN" = 1 ]; then set -- "$@" --plugin; fi
    # The helper puts back what it changed when one of its own steps fails.
    if ! digest=$(sh "$TMP/container-update" "$@" </dev/null); then
        die 'the install did not complete; the message above says what was put back'
    fi
    CONTAINER_SWITCHED=1
    if [ "$ROOTS_FILE" = "$CINSTALL/roots.json" ] && [ ! -e "$ROOTS_FILE" ]; then
        (umask 077 && printf '{\n    "roots": []\n}\n' > "$ROOTS_FILE") || die "could not write $ROOTS_FILE"
    fi
    log "image recorded as $digest"
    log "install directory $CINSTALL"
    log "launcher written to $LAUNCHER"
    [ "$PLUGIN" = 0 ] || log "plugin written to $CINSTALL/plugin"

    # The real thing: a call through the launcher, from a scratch directory so
    # no project of yours is mounted for it, and that container stopped after.
    # The scratch project lies in TMPDIR: it is mounted read-only and nothing
    # is read from it.
    PROBE=$(mktemp -d "${TMPDIR:-/tmp}/knossos-probe.XXXXXX") || die 'could not create a temporary directory'
    mkdir "$PROBE/probe project"
    if installed=$(cd "$PROBE/probe project" && "$LAUNCHER" version 2>&1 </dev/null); then
        (cd "$PROBE/probe project" && "$LAUNCHER" stop >/dev/null 2>&1 </dev/null) || true
        case $installed in
            "Knossos $VERSION") ;;
            *) die "the installed launcher answered '$installed', not Knossos $VERSION; the previous state was restored" ;;
        esac
        CONTAINER_SWITCHED=0
        CINSTALL_MADE=0
        log "$installed"
    else
        die "the installed launcher does not run ($installed); the previous state was restored"
    fi
    if [ -n "$old_bin" ] && [ "$old_bin" != "$BIN_DIR" ] && [ -f "$old_bin/knossos" ]; then
        log "the launcher in $old_bin still runs this installation; remove $old_bin/knossos if nothing calls it"
    fi

    # Roots, through the launcher: the host writes the list in the install
    # directory, and no container runs for it.
    if [ -n "$ROOT_TO_GRANT" ]; then
        if granted=$(cd "$ROOT_TO_GRANT" 2>/dev/null && "$LAUNCHER" allow-root "$ROOT_TO_GRANT" --execute 2>&1 </dev/null); then
            log "granted $ROOT_TO_GRANT"
        else
            printf '%s\n' "$granted" | sed 's/^/  /' >&2
            warn "could not add $ROOT_TO_GRANT to the roots file; run: knossos allow-root \"$ROOT_TO_GRANT\" --execute"
        fi
    elif [ -n "$ROOT_SKIPPED" ]; then
        log "no project root granted: $ROOT_SKIPPED is /, your home directory or a directory above it, which container mode never mounts; grant a project inside it with knossos allow-root <project> --execute"
    elif [ "$ROOTS" = 1 ] && [ -z "${KNOSSOS_ALLOW_ROOT:-}" ]; then
        log "no project root granted: the working directory is / or your home directory; add one with knossos allow-root <path> --execute"
    fi

    if [ "$PLUGIN" = 1 ]; then
        # The image wrote the plugin; registering it is done here, on the
        # host, because claude does not exist inside the image. The same two
        # commands install-agent-plugin --execute runs in native mode. The
        # directory registered, <install dir>/plugin, keeps its path across
        # every update: an update rewrites what is in it.
        if command -v claude >/dev/null 2>&1; then
            say 'Installing the Claude Code plugin...'
            if claude plugin marketplace add "$CINSTALL/plugin" --scope user </dev/null \
                && claude plugin install knossos@knossos --scope user --yes </dev/null; then
                log 'plugin installed'
            else
                warn "the plugin was not installed; run: claude plugin marketplace add \"$CINSTALL/plugin\" --scope user && claude plugin install knossos@knossos --scope user --yes"
            fi
        fi
        register_mcp
    fi
}

main() {
    MODE=auto
    VERSION=''
    DATA_DIR=''
    DATA_DIR_GIVEN=0
    BIN_DIR="${KNOSSOS_BIN_DIR:-}"
    BASE="${KNOSSOS_RELEASE_BASE:-https://github.com/AraneaDev/knossos/releases}"
    NAME="${KNOSSOS_MCP_NAME:-knossos}"
    SCOPE="${KNOSSOS_MCP_SCOPE:-user}"
    PLUGIN=1
    ROOTS=1
    DRY_RUN=0
    FORCE_CLEAN=0
    PARTIAL=''
    TMP=''
    PREV_LINK=''
    SWITCHED=0
    CONTAINER_SWITCHED=0
    CINSTALL=""
    CINSTALL_MADE=0
    BAKED_DIR=""
    PROBE=''
    INSTALL_DIR=''
    INSTALL_DIR_GIVEN=0

    while [ $# -gt 0 ]; do
        case $1 in
            --mode=*) MODE=${1#--mode=} ;;
            --version=*) VERSION=${1#--version=} ;;
            --data-dir=*)
                DATA_DIR=${1#--data-dir=}
                DATA_DIR_GIVEN=1
                ;;
            --bin-dir=*) BIN_DIR=${1#--bin-dir=} ;;
            --install-dir=*)
                INSTALL_DIR=${1#--install-dir=}
                INSTALL_DIR_GIVEN=1
                [ -n "$INSTALL_DIR" ] || die '--install-dir needs a directory'
                ;;
            --no-plugin) PLUGIN=0 ;;
            --no-roots) ROOTS=0 ;;
            --dry-run) DRY_RUN=1 ;;
            --force-clean) FORCE_CLEAN=1 ;;
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
        auto | native | container) ;;
        *) die "--mode must be native or container, not: $MODE" ;;
    esac

    # HOME is only needed when a directory was not given. --data-dir wins over
    # an exported KNOSSOS_DATA_DIR, which only fills in when it is absent.
    [ -n "$DATA_DIR" ] || DATA_DIR="${KNOSSOS_DATA_DIR:-}"
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
    # The install directory of a container install: --install-dir, else
    # KNOSSOS_INSTALL_DIR, else ${XDG_DATA_HOME:-~/.local/share}/knossos. A
    # relative XDG_DATA_HOME is ignored, as the XDG base directory rules say.
    # Worked out for native mode too, which only reads it, to tell whether its
    # data directory is a container install's graph directory.
    [ -n "$INSTALL_DIR" ] || INSTALL_DIR=${KNOSSOS_INSTALL_DIR:-}
    if [ -z "$INSTALL_DIR" ]; then
        case ${XDG_DATA_HOME:-} in
            /*) INSTALL_DIR="${XDG_DATA_HOME%/}/knossos" ;;
            *) if [ -n "${HOME:-}" ]; then INSTALL_DIR="$HOME/.local/share/knossos"; fi ;;
        esac
    fi
    [ -z "$INSTALL_DIR" ] || INSTALL_DIR=$(absolute "$INSTALL_DIR")
    # They end up in install.json, the launcher and the hooks, which are line
    # based: a newline or another control character would break them. The
    # install directory is checked once the mode is known: native mode only
    # reads it, and ignores one it cannot use.
    case $DATA_DIR$BIN_DIR in
        *[[:cntrl:]]*) die 'the data and bin directories must not contain a newline or another control character' ;;
    esac

    # ---- detect ------------------------------------------------------------

    # What native mode lacks on this host: each runtime by version and not
    # just by presence, and everything else the release's `knossos doctor`
    # fails without (the PHP extensions it checks, and git), plus the tools
    # this script unpacks and checks a release with. A host that would fail
    # doctor is never chosen for native mode.
    missing=''
    add_missing() { missing="$missing
  - $1"; }

    if ! command -v php >/dev/null 2>&1; then
        add_missing 'PHP 8.3 or newer (php not found)'
    elif ! php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' >/dev/null 2>&1 </dev/null; then
        add_missing "PHP 8.3 or newer (found $(php -r 'echo PHP_VERSION;' 2>/dev/null </dev/null))"
    else
        # The extensions DoctorService::REQUIRED_EXTENSIONS lists, by the name
        # extension_loaded() takes (tests/phpunit/Install/InstallDetectionTest
        # ties the two). The answer is one marked line, and PHP's own startup
        # warnings go to stderr: a stale extension= line in php.ini prints one
        # on stdout by default, which must never read as a missing extension.
        # shellcheck disable=SC2016 # the $-prefixed names in this php -r program are PHP variables.
        probe=$(php -d display_errors=stderr -d display_startup_errors=0 -r '$missing = []; foreach (["json", "mbstring", "pdo", "pdo_sqlite", "tokenizer"] as $e) { if (!extension_loaded($e)) { $missing[] = $e; } } echo "\n", "KNOSSOS-MISSING-EXTENSIONS:", implode(" ", $missing), "\n";' 2>/dev/null </dev/null) || probe=''
        lacking=$(printf '%s\n' "$probe" | sed -n 's/^KNOSSOS-MISSING-EXTENSIONS://p' | head -n 1)
        if ! printf '%s\n' "$probe" | grep -q '^KNOSSOS-MISSING-EXTENSIONS:'; then
            add_missing 'the PHP extensions json, mbstring, pdo, pdo_sqlite and tokenizer (php could not be asked which are loaded)'
        elif [ -n "$lacking" ]; then
            lacking=$(printf '%s' "$lacking" | sed 's/ /, /g')
            add_missing "the PHP extension(s) $lacking (not loaded by php)"
        fi
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
    if ! command -v git >/dev/null 2>&1; then
        add_missing 'Git (git not found)'
    elif ! git --version >/dev/null 2>&1 </dev/null; then
        add_missing 'Git (git --version failed)'
    fi
    command -v tar >/dev/null 2>&1 || add_missing 'tar, to unpack the release (tar not found)'
    if ! command -v sha256sum >/dev/null 2>&1 && ! command -v shasum >/dev/null 2>&1; then
        add_missing 'sha256sum or shasum, to check the download (neither found)'
    fi

    # A container runtime counts only when its daemon answers: a docker binary
    # with a stopped daemon cannot run anything, and says so on its own line.
    container_runtime=''
    stopped_runtime=''
    for candidate in docker podman; do
        command -v "$candidate" >/dev/null 2>&1 || continue
        if "$candidate" info >/dev/null 2>&1 </dev/null; then
            container_runtime=$candidate
            break
        fi
        [ -n "$stopped_runtime" ] || stopped_runtime=$candidate
    done
    no_container_runtime() {
        if [ -n "$stopped_runtime" ]; then
            if [ -n "$missing" ] && [ "$MODE" != container ]; then
                die "$stopped_runtime is installed, but its daemon is not running ($stopped_runtime info failed): start it and run the installer again, or install what is listed above"
            fi
            die "$stopped_runtime is installed, but its daemon is not running ($stopped_runtime info failed): start it and run the installer again"
        fi
        die "$1"
    }

    # The launcher names each container by a SHA-256, in either mode.
    no_checksum_tool() {
        if ! command -v sha256sum >/dev/null 2>&1 && ! command -v shasum >/dev/null 2>&1; then
            die 'container mode needs sha256sum or shasum on the host, and neither was found; install one (coreutils or perl) and run the installer again'
        fi
    }
    case $MODE in
        container)
            [ -n "$container_runtime" ] || no_container_runtime 'container mode needs Docker or Podman, with its daemon running; neither was found'
            no_checksum_tool
            ;;
        native | auto)
            if [ -n "$missing" ]; then
                {
                    say 'This host cannot run Knossos natively. Missing:'
                    printf '%s\n' "$missing"
                } >&2
                if [ "$MODE" = native ]; then
                    if [ -n "$container_runtime" ]; then
                        die "install what is listed above and run the installer again, or re-run it with --mode=container: $container_runtime is available"
                    fi
                    die 'install what is listed above and run the installer again, or install Docker or Podman and re-run it with --mode=container'
                fi
                [ -n "$container_runtime" ] \
                    || no_container_runtime 'install what is listed above and run the installer again, or install Docker or Podman (with its daemon running) and re-run it with --mode=container'
                no_checksum_tool
                say "Using container mode: $container_runtime is available."
                MODE=container
            else
                MODE=native
            fi
            ;;
    esac

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

    # ---- release source ----------------------------------------------------

    case $BASE in
        http://* | https://*) REMOTE=1 ;;
        file://*)
            REMOTE=0
            BASE=${BASE#file://}
            ;;
        *) REMOTE=0 ;;
    esac
    BASE=${BASE%/}

    if [ "$MODE" = native ]; then
        if [ "$REMOTE" = 1 ]; then
            command -v curl >/dev/null 2>&1 || die 'curl is required to download a release'
        fi
        [ -n "$VERSION" ] || resolve_latest
        ASSET="knossos-$VERSION-$OS-$ARCH.tar.gz"
        VERSION_DIR="$DATA_DIR/versions/$VERSION"
    fi
    # With --data-dir, the roots file sits beside it whatever the caller
    # exported; without, an exported KNOSSOS_ROOTS_FILE is honoured.
    if [ "$DATA_DIR_GIVEN" = 1 ] || [ -z "${KNOSSOS_ROOTS_FILE:-}" ]; then
        ROOTS_FILE="$DATA_DIR/roots.json"
    else
        ROOTS_FILE=$KNOSSOS_ROOTS_FILE
    fi
    ROOT_TO_GRANT=$(choose_root)
    # Container mode never mounts /, the home directory or a directory above
    # it, so granting one would only be refused, and so would the command a
    # refusal suggests. It is skipped, and the end of the run says why.
    ROOT_SKIPPED=''
    if [ "$MODE" = container ] && [ -n "$ROOT_TO_GRANT" ] && [ -d "$ROOT_TO_GRANT" ]; then
        granted=$(CDPATH='' cd -P -- "$ROOT_TO_GRANT" 2>/dev/null && pwd -P) || granted=''
        home_real=''
        if [ -n "${HOME:-}" ] && [ -d "$HOME" ]; then home_real=$(CDPATH='' cd -P -- "$HOME" 2>/dev/null && pwd -P) || home_real=''; fi
        if [ "$granted" = / ] || { [ -n "$home_real" ] && [ -n "$granted" ] && within "$home_real" "$granted"; }; then
            ROOT_SKIPPED=$ROOT_TO_GRANT
            ROOT_TO_GRANT=''
        fi
    fi
    # Every child from here on (the launcher, the release, the plugin step)
    # sees this installation's directories, never what the caller exported.
    # KNOSSOS_INSTALL_DIR is the launcher's test override and must not steer
    # the launcher this run writes.
    KNOSSOS_DATA_DIR=$DATA_DIR
    KNOSSOS_ROOTS_FILE=$ROOTS_FILE
    export KNOSSOS_DATA_DIR KNOSSOS_ROOTS_FILE
    unset KNOSSOS_INSTALL_DIR
    LAUNCHER="$BIN_DIR/knossos"

    if [ "$MODE" = container ]; then
        [ -n "$INSTALL_DIR" ] || die 'HOME is not set; pass --install-dir'
        case $INSTALL_DIR in
            *[[:cntrl:]]*) die 'the install directory must not contain a newline or another control character' ;;
        esac
        refuse_foreign_entries "$DATA_DIR"
    elif [ "$INSTALL_DIR_GIVEN" = 1 ]; then
        die '--install-dir is for container mode; a native install keeps its release in the data directory (--data-dir)'
    else
        # Native mode: KNOSSOS_INSTALL_DIR and XDG_DATA_HOME only say where a
        # container install could be; one that cannot name a directory names none.
        case $INSTALL_DIR in *[[:cntrl:]]*) INSTALL_DIR='' ;; esac
    fi
    refuse_other_mode
    if [ "$MODE" = container ]; then
        install_container
        finish
        return 0
    fi

    # ---- dry run -----------------------------------------------------------

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
            log "plugin:     write $DATA_DIR/plugin, install it and register the MCP server with Claude Code"
        else
            log 'plugin:     skipped (--no-plugin)'
        fi
        exit 0
    fi

    # ---- download and verify -----------------------------------------------

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

        # Everything in the archive must sit under knossos-<version>/ and stay
        # inside it.
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

        # The new release must run before anything points at it. It runs against
        # a throwaway data dir: doctor opens and migrates the database it finds,
        # and the live one must not be migrated by a release that is not
        # installed yet.
        log 'running knossos doctor on the new release'
        mkdir "$TMP/doctor-data"
        if ! KNOSSOS_DATA_DIR="$TMP/doctor-data" KNOSSOS_ROOTS_FILE="$TMP/doctor-data/roots.json" "$PARTIAL/bin/knossos" doctor > "$TMP/doctor.out" 2>&1 </dev/null; then
            cat "$TMP/doctor.out" >&2
            die 'knossos doctor failed on the new release; the previous release, if any, is untouched'
        fi

        # Rename into place. A directory renamed onto an existing one would land
        # inside it, so an existing target (another installer won the race, or an
        # earlier incomplete unpack) is dealt with first.
        if [ -e "$VERSION_DIR" ] && ! version_complete; then rm -rf "$VERSION_DIR"; fi
        if [ -e "$VERSION_DIR" ]; then
            remove_partial
        else
            mv "$PARTIAL" "$VERSION_DIR"
        fi
        PARTIAL=''
    fi

    # ---- the launcher, checked before anything is switched -----------------

    TEMPLATE="$VERSION_DIR/launcher/knossos"
    [ -f "$TEMPLATE" ] || die "the release has no launcher template at $TEMPLATE; nothing was switched"
    mkdir -p "$BIN_DIR"
    NEW_LAUNCHER="$BIN_DIR/.knossos.tmp.$$"
    write_launcher "$NEW_LAUNCHER" || die "the launcher template at $TEMPLATE has no install directory line; nothing was switched"
    # Run the new launcher against a scratch install dir whose current already
    # points at the new release, so a launcher that cannot start the release is
    # caught while the real current is untouched.
    mkdir "$TMP/probe"
    ln -s "$VERSION_DIR" "$TMP/probe/current"
    if ! probe=$(KNOSSOS_INSTALL_DIR="$TMP/probe" KNOSSOS_DATA_DIR="$TMP/probe" KNOSSOS_ROOTS_FILE="$TMP/probe/roots.json" "$NEW_LAUNCHER" version 2>&1 </dev/null); then
        rm -f "$NEW_LAUNCHER"
        die "the launcher does not run the new release: $probe; nothing was switched"
    fi

    # ---- switch ------------------------------------------------------------

    if [ -L "$DATA_DIR/current" ]; then PREV_LINK=$(readlink "$DATA_DIR/current"); fi
    if [ -f "$DATA_DIR/install.json" ]; then cp "$DATA_DIR/install.json" "$TMP/install.json.prev"; fi
    if [ -f "$LAUNCHER" ]; then cp "$LAUNCHER" "$TMP/launcher.prev"; fi

    mkdir -p "$DATA_DIR"
    SWITCHED=1
    atomic_link "versions/$VERSION" "$DATA_DIR/current"
    log "current -> versions/$VERSION"

    installed_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    # The same fields, order and layout as VersionSwitch::switchTo writes.
    printf '{\n  "mode": "native",\n  "version": "%s",\n  "image": null,\n  "bin_dir": %s,\n  "installed_at": "%s"\n}\n' \
        "$VERSION" "$(json_string "$BIN_DIR")" "$installed_at" > "$DATA_DIR/install.json.tmp.$$"
    mv -f "$DATA_DIR/install.json.tmp.$$" "$DATA_DIR/install.json"
    note_replaced_launcher
    mv -f "$NEW_LAUNCHER" "$LAUNCHER"
    log "launcher written to $LAUNCHER"

    # The real thing, end to end, with this installation's directories. If it
    # fails, everything goes back.
    if installed=$(KNOSSOS_DATA_DIR="$DATA_DIR" KNOSSOS_ROOTS_FILE="$ROOTS_FILE" "$LAUNCHER" version 2>&1 </dev/null); then
        # Installed and working: the steps below are best effort and never undo it.
        SWITCHED=0
        log "$installed"
        record_previous_and_prune
    else
        die "the installed launcher does not run ($installed); the previous state was restored"
    fi

    # ---- roots, plugin, MCP ------------------------------------------------

    if [ -n "$ROOT_TO_GRANT" ]; then
        merge_root "$ROOT_TO_GRANT"
    elif [ "$ROOTS" = 1 ] && [ -z "${KNOSSOS_ALLOW_ROOT:-}" ]; then
        log "no project root granted: the working directory is / or your home directory; add one to $ROOTS_FILE"
    fi

    if [ "$PLUGIN" = 1 ]; then
        say 'Installing the Claude Code plugin...'
        # The plugin belongs to the installation, not to one version: it goes
        # to <data dir>/plugin and its hooks call the launcher, so a later
        # self-update, which rewrites that same directory, never leaves them
        # on an old release or pointing at a deleted one.
        # What it printed is kept, and shown only when it fails.
        if KNOSSOS_DATA_DIR="$DATA_DIR" "$LAUNCHER" install-agent-plugin --data-dir="$DATA_DIR" \
            --plugin-dir="$DATA_DIR/plugin" --bin="$LAUNCHER" --execute > "$TMP/plugin.out" 2>&1 </dev/null; then
            log 'plugin installed'
        else
            sed 's/^/  /' "$TMP/plugin.out" >&2
            warn "the plugin was not installed (output above); run: $LAUNCHER install-agent-plugin --data-dir=\"$DATA_DIR\" --execute"
        fi
        register_mcp
    fi
    finish
}

main "$@"; exit
}

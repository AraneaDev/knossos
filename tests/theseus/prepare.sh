#!/bin/sh
# Prepare the Theseus lifecycle rehearsals of install.sh and uninstall.sh.
#
#   sh tests/theseus/prepare.sh [--base-version=V] [--claude=PATH | --no-claude]
#                               [--container]
#
# Theseus (https://github.com/AraneaDev/theseus) runs the installer, an
# upgrade and the uninstaller in a disposable container and records what each
# step left on the filesystem. It installs nothing itself and gives the guest
# no network, so everything a step needs is put together here:
#
#   artifacts/base    the previous published release with the install.sh it
#                     shipped with, downloaded from GitHub
#   artifacts/target  this checkout: a release built by tools/release-tarball,
#                     install.sh, uninstall.sh and a small project to scan,
#                     and tools/claude, a copy of this host's Claude Code
#   theseus.toml      the native recipe, from theseus.toml.in
#   the guest image   knossos-theseus-guest:local, from guest.Dockerfile
#
# With --container also:
#
#   container.toml    the container-mode recipe, from container.toml.in
#   the release image knossos-theseus:<version>, the runtime image of this
#                     checkout, which Theseus loads into the guest's private
#                     Docker daemon
#
# Then:
#
#   theseus run --headless --recipe tests/theseus/theseus.toml
#   theseus run --headless --recipe tests/theseus/container.toml
#
# Claude Code is the binary `claude` on PATH resolves to (through mise, when
# that is how it is installed), or --claude=PATH. It has to be a Linux binary
# for this machine's architecture; it runs in the guest with no network and no
# credentials, so it registers the plugin and the MCP server but makes no
# model call. With --no-claude the guest has none, and the installer's
# "Claude CLI not found" path is the one rehearsed.
#
# Set KNOSSOS_TEST_RELEASE_DIR to a directory that already holds this
# checkout's tarball and SHA256SUMS to skip the build, as tests/shell does.
#
# Needs: docker, curl, and what tools/release-tarball needs. The container
# recipe needs Theseus with recipe schema 10 (a private Docker daemon) and a
# Docker host that allows privileged containers.
set -eu

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH='' cd -- "$HERE/../.." && pwd)
RELEASES="${KNOSSOS_RELEASE_BASE:-https://github.com/AraneaDev/knossos/releases}"
IMAGE=knossos-theseus-guest:local

die() {
    printf 'prepare: %s\n' "$1" >&2
    exit 1
}

BASE_VERSION=''
CLAUDE=''
NO_CLAUDE=0
CONTAINER=0
while [ $# -gt 0 ]; do
    case $1 in
        --base-version=*) BASE_VERSION=${1#--base-version=} ;;
        --claude=*) CLAUDE=${1#--claude=} ;;
        --no-claude) NO_CLAUDE=1 ;;
        --container) CONTAINER=1 ;;
        -h | --help)
            echo 'usage: sh tests/theseus/prepare.sh [--base-version=V] [--claude=PATH | --no-claude] [--container]'
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

TARGET_VERSION=$(sed -n "s/^.*const CURRENT = '\\([^']*\\)'.*\$/\\1/p" "$ROOT/src/Runtime/Version.php")
[ -n "$TARGET_VERSION" ] || die 'could not read the version from src/Runtime/Version.php'
# The release before this one: the second version heading of the changelog.
if [ -z "$BASE_VERSION" ]; then
    BASE_VERSION=$(sed -n 's/^## \[\([0-9][0-9A-Za-z.-]*\)\].*$/\1/p' "$ROOT/CHANGELOG.md" | grep -v -x -F "$TARGET_VERSION" | head -n 1)
fi
[ -n "$BASE_VERSION" ] || die 'could not find the previous release in CHANGELOG.md; pass --base-version'
BASE_VERSION=${BASE_VERSION#v}
case $BASE_VERSION in *[!0-9A-Za-z.-]*) die "not a valid version: $BASE_VERSION" ;; esac

case $(uname -s) in Linux) OS=linux ;; *) die 'the guest is a Linux container of this machine; run this on Linux' ;; esac
case $(uname -m) in
    x86_64 | amd64) ARCH=x86_64 ;;
    arm64 | aarch64) ARCH=arm64 ;;
    *) die "unsupported architecture: $(uname -m)" ;;
esac

# The Claude Code binary itself, not a version manager's shim in front of it.
if [ "$NO_CLAUDE" = 0 ] && [ -z "$CLAUDE" ]; then
    if command -v mise >/dev/null 2>&1 && found=$(mise which claude 2>/dev/null); then
        CLAUDE=$found
    elif found=$(command -v claude 2>/dev/null); then
        CLAUDE=$found
    else
        die 'no claude on PATH; pass --claude=PATH, or --no-claude to rehearse without Claude Code'
    fi
fi
if [ -n "$CLAUDE" ]; then
    CLAUDE=$(readlink -f -- "$CLAUDE") || die "cannot resolve $CLAUDE"
    if [ ! -f "$CLAUDE" ] || [ ! -x "$CLAUDE" ]; then die "$CLAUDE is not an executable file"; fi
    case $(head -c 4 "$CLAUDE" | od -An -c | tr -d ' ') in
        177ELF) ;;
        *) die "$CLAUDE is not a Linux binary (a script or a shim?); pass the binary itself with --claude=PATH" ;;
    esac
fi

ARTIFACTS="$HERE/artifacts"
rm -rf "$ARTIFACTS"
mkdir -p "$ARTIFACTS/base" "$ARTIFACTS/target"

echo "base:   Knossos $BASE_VERSION, the published release"
for asset in "knossos-$BASE_VERSION-$OS-$ARCH.tar.gz" SHA256SUMS install.sh; do
    curl --fail --silent --show-error --location --retry 3 \
        --output "$ARTIFACTS/base/$asset" "$RELEASES/download/v$BASE_VERSION/$asset" \
        || die "could not download $asset of v$BASE_VERSION"
done

echo "target: Knossos $TARGET_VERSION, this checkout"
if [ -n "${KNOSSOS_TEST_RELEASE_DIR:-}" ]; then
    cp "$KNOSSOS_TEST_RELEASE_DIR/knossos-$TARGET_VERSION-$OS-$ARCH.tar.gz" "$KNOSSOS_TEST_RELEASE_DIR/SHA256SUMS" "$ARTIFACTS/target/" \
        || die "KNOSSOS_TEST_RELEASE_DIR holds no release of $TARGET_VERSION for $OS-$ARCH"
elif ! sh "$ROOT/tools/release-tarball" --out "$ARTIFACTS/target" > "$ARTIFACTS/build.log" 2>&1; then
    cat "$ARTIFACTS/build.log" >&2
    die 'the release build failed'
fi
rm -f "$ARTIFACTS/build.log"
cp "$ROOT/install.sh" "$ROOT/uninstall.sh" "$ARTIFACTS/target/"
cp -R "$ROOT/tests/Fixtures/mixed" "$ARTIFACTS/target/project"
mkdir -p "$ARTIFACTS/target/tools"
# What every step sources: Claude Code on PATH, and its configuration and its
# cache (where it logs each MCP health check) outside the observed home, so
# what the installer registers is read back through claude and never counted
# as a leftover of Knossos. Knossos reads neither variable.
cat > "$ARTIFACTS/target/tools/env.sh" <<'ENV'
PATH=/inputs/target/tools:/home/theseus/.local/bin:/usr/local/bin:/usr/bin:/bin
CLAUDE_CONFIG_DIR=/var/tmp/claude-config
XDG_CACHE_HOME=/var/tmp/claude-cache
export PATH CLAUDE_CONFIG_DIR XDG_CACHE_HOME
ENV
if [ -n "$CLAUDE" ]; then
    cp "$CLAUDE" "$ARTIFACTS/target/tools/claude"
    echo "claude: $("$CLAUDE" --version 2>/dev/null </dev/null || echo "$CLAUDE")"
else
    echo 'claude: none (--no-claude)'
fi

# render TEMPLATE OUTPUT: fill in the versions, and drop the Claude Code
# checks and requirement (the lines between the CLAUDE markers) without one.
render() {
    if [ -n "$CLAUDE" ]; then
        sed -e '/^# @CLAUDE@/d' "$1"
    else
        sed -e '/^# @CLAUDE@ begin/,/^# @CLAUDE@ end/d' "$1"
    fi | sed -e "s/@BASE_VERSION@/$BASE_VERSION/g" -e "s/@TARGET_VERSION@/$TARGET_VERSION/g" > "$2"
}
render "$HERE/theseus.toml.in" "$HERE/theseus.toml"
rm -f "$HERE/container.toml"
if [ "$CONTAINER" = 1 ]; then
    echo "image:  knossos-theseus:$TARGET_VERSION, the runtime image of this checkout"
    docker build --quiet --target runtime --tag "knossos-theseus:$TARGET_VERSION" "$ROOT" >/dev/null \
        || die 'the runtime image build failed'
    render "$HERE/container.toml.in" "$HERE/container.toml"
fi

echo "guest:  $IMAGE"
docker build --quiet --tag "$IMAGE" --file "$HERE/guest.Dockerfile" "$HERE" >/dev/null \
    || die 'the guest image build failed'

echo
echo 'Prepared. Run:'
echo '  theseus run --headless --recipe tests/theseus/theseus.toml'
if [ "$CONTAINER" = 1 ]; then
    echo '  theseus run --headless --recipe tests/theseus/container.toml'
fi

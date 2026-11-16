#!/bin/sh
# Prepare the Theseus lifecycle rehearsal of install.sh and uninstall.sh.
#
#   sh tests/theseus/prepare.sh [--base-version=V]
#
# Theseus (https://github.com/AraneaDev/theseus) runs the installer, an
# upgrade and the uninstaller in a disposable container and records what each
# step left on the filesystem. It installs nothing itself and gives the guest
# no network, so everything a step needs is put together here:
#
#   artifacts/base    the previous published release with the install.sh it
#                     shipped with, downloaded from GitHub
#   artifacts/target  this checkout: a release built by tools/release-tarball,
#                     install.sh, uninstall.sh and a small project to scan
#   theseus.toml      the recipe, from theseus.toml.in with both versions
#   the guest image   knossos-theseus-guest:local, from guest.Dockerfile
#
# Then:
#
#   theseus validate --recipe tests/theseus/theseus.toml
#   theseus run --headless --recipe tests/theseus/theseus.toml
#
# Set KNOSSOS_TEST_RELEASE_DIR to a directory that already holds this
# checkout's tarball and SHA256SUMS to skip the build, as tests/shell does.
#
# Needs: docker, curl, and what tools/release-tarball needs.
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
while [ $# -gt 0 ]; do
    case $1 in
        --base-version=*) BASE_VERSION=${1#--base-version=} ;;
        -h | --help)
            echo 'usage: sh tests/theseus/prepare.sh [--base-version=V]'
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

sed -e "s/@BASE_VERSION@/$BASE_VERSION/g" -e "s/@TARGET_VERSION@/$TARGET_VERSION/g" \
    "$HERE/theseus.toml.in" > "$HERE/theseus.toml"

echo "guest:  $IMAGE"
docker build --quiet --tag "$IMAGE" --file "$HERE/guest.Dockerfile" "$HERE" >/dev/null \
    || die 'the guest image build failed'

echo
echo 'Prepared. Run:'
echo '  theseus validate --recipe tests/theseus/theseus.toml'
echo '  theseus run --headless --recipe tests/theseus/theseus.toml'

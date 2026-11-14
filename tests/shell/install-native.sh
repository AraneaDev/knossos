#!/bin/sh
# install.sh, native path, against a local release directory. Every run uses a
# temp data dir and a temp bin dir and --no-plugin, so nothing registers with
# Claude Code and nothing touches the real home.
#
# The release is built once with tools/release-tarball. Set
# KNOSSOS_TEST_RELEASE_DIR to a directory that already holds one (the tarball
# and its SHA256SUMS) to skip the build.
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT="$HERE/../.."
INSTALL="$ROOT/install.sh"
failures=0
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; failures=$((failures + 1)); }

if [ -n "${KNOSSOS_TEST_RELEASE_DIR:-}" ]; then
    RELEASE=$KNOSSOS_TEST_RELEASE_DIR
else
    RELEASE="$WORK/release"
    if ! sh "$ROOT/tools/release-tarball" --out "$RELEASE" > "$WORK/build.log" 2>&1; then
        printf 'FAIL the release build failed\n'
        cat "$WORK/build.log"
        exit 1
    fi
fi
ASSET=$(find "$RELEASE" -name 'knossos-*.tar.gz' | head -n 1)
ASSET=${ASSET##*/}
VERSION=${ASSET#knossos-}
VERSION=${VERSION%%-*}

# install_into DIR [args]: run the installer against RELEASE with a data dir
# and bin dir under DIR, both with a space in the name.
run_install() {
    dir=$1; shift
    KNOSSOS_RELEASE_BASE="${BASE_OVERRIDE:-$RELEASE}" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE -u KNOSSOS_BIN_DIR \
        sh "$INSTALL" --no-plugin --data-dir="$dir/data dir" --bin-dir="$dir/bin dir" "$@" 2>&1
}

# ---- a fresh install -------------------------------------------------------

T1="$WORK/fresh"
mkdir -p "$T1"
out=$(run_install "$T1" --version="$VERSION"); status=$?
if [ "$status" -eq 0 ]; then pass 'a fresh install exits 0'; else fail "fresh install exited $status: $out"; fi

got=$(env -u KNOSSOS_DATA_DIR "$T1/bin dir/knossos" version 2>&1)
if [ "$got" = "Knossos $VERSION" ]; then pass 'the launcher reports the installed version'; else fail "knossos version said: $got"; fi

if [ -L "$T1/data dir/current" ] && [ -d "$T1/data dir/versions/$VERSION" ]; then
    pass 'current is a symlink to versions/<version>'
else
    fail 'current or versions/<version> is missing'
fi
if grep -q '"mode": "native"' "$T1/data dir/install.json" && grep -q "\"version\": \"$VERSION\"" "$T1/data dir/install.json"; then
    pass 'install.json records mode and version'
else
    fail "install.json: $(cat "$T1/data dir/install.json" 2>&1)"
fi
leftovers=$(find "$T1" -name '*.partial*' -o -name '*.tmp.*' | head -n 3)
if [ -z "$leftovers" ]; then pass 'no partial or temporary files are left'; else fail "left behind: $leftovers"; fi

# ---- a second run ----------------------------------------------------------

before=$(readlink "$T1/data dir/current")
out=$(run_install "$T1" --version="$VERSION"); status=$?
after=$(readlink "$T1/data dir/current")
got=$("$T1/bin dir/knossos" version 2>&1)
if [ "$status" -eq 0 ] && [ "$before" = "$after" ] && [ "$got" = "Knossos $VERSION" ]; then
    pass 'a second run is idempotent'
else
    fail "second run: status $status, $before then $after, version said $got, output $out"
fi

# Latest from a local directory, and a file:// base.
T1B="$WORK/latest"
mkdir -p "$T1B"
BASE_OVERRIDE="file://$RELEASE" run_install "$T1B" >/dev/null 2>&1; status=$?
got=$("$T1B/bin dir/knossos" version 2>&1)
if [ "$status" -eq 0 ] && [ "$got" = "Knossos $VERSION" ]; then
    pass 'resolves the latest version from a file:// release dir'
else
    fail "latest via file://: status $status, version said $got"
fi

# ---- a corrupted tarball ---------------------------------------------------

BAD="$WORK/bad release"
mkdir -p "$BAD"
cp "$RELEASE/SHA256SUMS" "$BAD/"
cp "$RELEASE/$ASSET" "$BAD/$ASSET"
printf 'corrupt' >> "$BAD/$ASSET"

T2="$WORK/corrupt"
mkdir -p "$T2/data dir/versions/0.0.1/bin"
ln -s versions/0.0.1 "$T2/data dir/current"
BASE_OVERRIDE=$BAD out=$(run_install "$T2" --version="$VERSION"); status=$?
case $out in *"checksum mismatch"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'a corrupted tarball is refused with the reason'; else fail "corrupt: status $status, output $out"; fi
if [ ! -e "$T2/data dir/versions/$VERSION" ] && [ "$(readlink "$T2/data dir/current")" = 'versions/0.0.1' ]; then
    pass 'a refused install leaves no new version and the old current'
else
    fail 'a refused install changed the data dir'
fi
if [ ! -e "$T2/bin dir/knossos" ]; then pass 'a refused install writes no launcher'; else fail 'launcher written despite the refusal'; fi

# ---- a missing platform asset ----------------------------------------------

GONE="$WORK/gone release"
mkdir -p "$GONE"
cp "$RELEASE/SHA256SUMS" "$GONE/"
T3="$WORK/missing"
mkdir -p "$T3"
BASE_OVERRIDE=$GONE out=$(run_install "$T3" --version="$VERSION"); status=$?
case $out in *"$ASSET"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$T3/data dir/versions" ]; then
    pass 'a missing asset names the asset and changes nothing'
else
    fail "missing asset: status $status, output $out"
fi

# SHA256SUMS without a line for this platform.
NOLINE="$WORK/noline"
mkdir -p "$NOLINE"
cp "$RELEASE/$ASSET" "$NOLINE/"
printf '%s  knossos-%s-other-arch.tar.gz\n' 0000 "$VERSION" > "$NOLINE/SHA256SUMS"
BASE_OVERRIDE=$NOLINE out=$(run_install "$WORK/noline-target" --version="$VERSION"); status=$?
case $out in *"no entry for $ASSET"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'a SHA256SUMS without this platform is refused'; else fail "no checksum line: status $status, output $out"; fi

# ---- dry run ---------------------------------------------------------------

T4="$WORK/dry"
out=$(run_install "$T4" --version="$VERSION" --dry-run); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$T4" ]; then pass '--dry-run creates nothing'; else fail "dry run: status $status, created: $(ls -A "$T4" 2>&1)"; fi
case $out in *"$VERSION"*"$ASSET"*) pass '--dry-run says what it would do' ;; *) fail "dry run output: $out" ;; esac

# ---- host without the runtimes ---------------------------------------------

# A PATH holding the system tools the installer needs, but no php and no
# container runtime.
BARE="$WORK/bare path"
mkdir -p "$BARE"
for tool in uname mktemp date cut sed tar cat rm mkdir mv ln cp dirname sort tail head wc chmod ls find grep tr node python3 sh; do
    found=$(command -v "$tool" 2>/dev/null || true)
    case $found in /*) ln -s "$found" "$BARE/$tool" ;; esac
done
T5="$WORK/nophp"
mkdir -p "$T5"
out=$(PATH=$BARE KNOSSOS_RELEASE_BASE=$RELEASE sh "$INSTALL" --no-plugin --data-dir="$T5/data" --bin-dir="$T5/bin" --version="$VERSION" 2>&1); status=$?
case $out in *PHP*"php not found"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$T5/data" ]; then
    pass 'without php and a container runtime it exits non-zero naming PHP'
else
    fail "no php: status $status, output $out"
fi

# PHP present but too old counts as missing.
OLD="$WORK/old php"
mkdir -p "$OLD"
cp -P "$BARE"/* "$OLD"/
rm -f "$OLD/php"
printf '#!/bin/sh\necho 8.2.0\nexit 1\n' > "$OLD/php"
chmod +x "$OLD/php"
out=$(PATH=$OLD KNOSSOS_RELEASE_BASE=$RELEASE sh "$INSTALL" --no-plugin --data-dir="$T5/data" --bin-dir="$T5/bin" --version="$VERSION" 2>&1); status=$?
case $out in *"PHP 8.3 or newer (found 8.2.0)"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'a PHP that is too old is reported with its version'; else fail "old php: status $status, output $out"; fi

# ---- container mode --------------------------------------------------------

out=$(run_install "$WORK/container" --mode=container --version="$VERSION"); status=$?
case $out in *"container mode is not available"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/container" ]; then
    pass '--mode=container is refused in this version'
else
    fail "container mode: status $status, output $out"
fi

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi

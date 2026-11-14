#!/bin/sh
# knossos self-update, end to end: a release built by tools/release-tarball is
# installed with install.sh, a newer one is made by repacking it with a higher
# version, and the installed launcher updates, rolls back and refuses. Every
# run uses temp data and bin directories and a local release directory, so
# there is no network and nothing touches the real home.
#
# Set KNOSSOS_TEST_RELEASE_DIR to a directory that already holds a release
# (tarball and SHA256SUMS) to skip the build.
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT="$HERE/../.."
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
OLD=${ASSET#knossos-}
OLD=${OLD%%-*}
PLATFORM=${ASSET#knossos-"$OLD"-}
NEW=99.0.0

sha_of() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d ' ' -f 1; else shasum -a 256 "$1" | cut -d ' ' -f 1; fi
}

# REL: the old release plus a repacked newer one, with one SHA256SUMS for both.
REL="$WORK/rel dir"
mkdir -p "$REL" "$WORK/x"
cp "$RELEASE/$ASSET" "$REL/"
tar -xzf "$RELEASE/$ASSET" -C "$WORK/x"
mv "$WORK/x/knossos-$OLD" "$WORK/x/knossos-$NEW"
printf '%s\n' "$NEW" > "$WORK/x/knossos-$NEW/VERSION"
sed "s/'$OLD'/'$NEW'/" "$WORK/x/knossos-$NEW/src/Runtime/Version.php" > "$WORK/Version.php"
cp "$WORK/Version.php" "$WORK/x/knossos-$NEW/src/Runtime/Version.php"
NEW_ASSET="knossos-$NEW-$PLATFORM"
tar -czf "$REL/$NEW_ASSET" -C "$WORK/x" "knossos-$NEW"
{
    printf '%s  %s\n' "$(sha_of "$REL/$ASSET")" "$ASSET"
    printf '%s  %s\n' "$(sha_of "$REL/$NEW_ASSET")" "$NEW_ASSET"
} > "$REL/SHA256SUMS"
# Only the old release is "published" for the install; the newer one appears after.
mv "$REL/$NEW_ASSET" "$WORK/$NEW_ASSET"

DATA="$WORK/data dir"
BIN="$WORK/bin dir"
if ! KNOSSOS_RELEASE_BASE="$REL" env -u KNOSSOS_BIN_DIR \
    sh "$ROOT/install.sh" --no-plugin --no-roots --data-dir="$DATA" --bin-dir="$BIN" --version="$OLD" > "$WORK/install.log" 2>&1 </dev/null; then
    printf 'FAIL the install failed\n'
    cat "$WORK/install.log"
    exit 1
fi

# kn ARGS: the installed launcher, against the local release directory, with
# the graph in a directory of its own. The launcher finds the release through
# the install directory it was written for, so a KNOSSOS_DATA_DIR elsewhere
# changes where the graph goes and nothing else.
GRAPH="$WORK/graph dir"
mkdir -p "$GRAPH"
kn() {
    KNOSSOS_RELEASE_BASE="$REL" KNOSSOS_DATA_DIR="$GRAPH" KNOSSOS_ROOTS_FILE="$GRAPH/roots.json" "$BIN/knossos" "$@" 2>&1 </dev/null
}

out=$(kn self-update --check); status=$?
if [ "$status" -eq 0 ]; then pass '--check with nothing newer exits 0'; else fail "--check, nothing newer: $status, $out"; fi

mv "$WORK/$NEW_ASSET" "$REL/"
out=$(kn self-update --check); status=$?
case $out in *"$NEW is available"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 10 ] && [ "$said" = yes ]; then pass '--check with a newer release exits 10 and names it'; else fail "--check, newer: $status, $out"; fi
if grep -q "\"latest\": \"$NEW\"" "$DATA/update-check.json" 2>/dev/null && [ ! -e "$GRAPH/update-check.json" ]; then
    pass '--check writes update-check.json to the install directory, not the graph directory'
else
    fail 'no update-check.json in the install directory, or one in the graph directory'
fi

out=$(kn session-brief "$WORK"); status=$?
case $out in *"knossos $NEW is available (you have $OLD): run knossos self-update"*) pass 'the session brief carries the update line' ;; *) fail "brief: $status, $out" ;; esac
kn doctor --json >/dev/null
if [ -f "$GRAPH/knossos.sqlite" ] && [ ! -e "$DATA/knossos.sqlite" ]; then pass 'the graph is kept in KNOSSOS_DATA_DIR, apart from the release'; else fail "graph location: $(find "$GRAPH" "$DATA" -maxdepth 1 | tr '\n' ' ')"; fi

out=$(kn self-update); status=$?
got=$(kn version)
if [ "$status" -eq 0 ] && [ "$got" = "Knossos $NEW" ] && [ "$(readlink "$DATA/current")" = "versions/$NEW" ]; then
    pass 'self-update installs the newer release and switches current'
else
    fail "update: $status, $out, version said $got"
fi
if grep -q "\"version\": \"$NEW\"" "$DATA/install.json" && [ -d "$DATA/versions/$OLD" ] && [ ! -e "$GRAPH/versions" ] && [ ! -e "$GRAPH/current" ]; then pass 'install.json follows and the previous version is kept'; else fail 'install.json or the kept version is wrong'; fi
leftovers=$(find "$DATA" -name '*.partial*' -o -name '*.tmp.*' -o -name 'self-update.tmp' | head -n 3)
if [ -z "$leftovers" ]; then pass 'no partial or temporary files are left'; else fail "left behind: $leftovers"; fi

out=$(kn session-brief "$WORK")
case $out in *"self-update"*) fail "the update line is still there after updating: $out" ;; *) pass 'the update line is gone once current' ;; esac

out=$(kn self-update); status=$?
case $out in *"already the latest"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'a second self-update says it is already the latest'; else fail "second update: $status, $out"; fi

out=$(kn self-update --rollback); status=$?
got=$(kn version)
if [ "$status" -eq 0 ] && [ "$got" = "Knossos $OLD" ]; then pass '--rollback switches back to the kept version'; else fail "rollback: $status, $out, version said $got"; fi

# A corrupted download changes nothing. The kept copy is removed first: a
# complete version on disk is reused without a download.
rm -rf "${DATA:?}/versions/$NEW" "$DATA/previous-version"
printf 'corrupt' >> "$REL/$NEW_ASSET"
out=$(kn self-update --version="$NEW"); status=$?
case $out in *"Checksum mismatch"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ "$(kn version)" = "Knossos $OLD" ]; then
    pass 'a checksum mismatch is refused and current stays'
else
    fail "corrupt: $status, $out"
fi

# A source checkout refuses, and the hook's quiet check stays silent.
out=$(env KNOSSOS_DATA_DIR="$WORK/other-data" php "$ROOT/bin/knossos" self-update 2>&1 </dev/null); status=$?
case $out in *"source checkout: update it with git pull"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'a source checkout is refused with the git message'; else fail "checkout: $status, $out"; fi
out=$(env KNOSSOS_DATA_DIR="$WORK/other-data" php "$ROOT/bin/knossos" self-update --check --quiet 2>&1 </dev/null); status=$?
if [ "$status" -eq 0 ] && [ -z "$out" ] && [ ! -e "$WORK/other-data" ]; then pass 'a quiet check in a checkout prints nothing and writes nothing'; else fail "quiet checkout: $status, $out"; fi

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi

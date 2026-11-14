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
INSTALL="${KNOSSOS_TEST_INSTALL:-$ROOT/install.sh}"
failures=0
WORK=$(mktemp -d)
trap 'chmod -R u+rwx "$WORK" 2>/dev/null; rm -rf "$WORK"' EXIT

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
case $(uname -s) in Linux) HOST_OS=linux ;; Darwin) HOST_OS=darwin ;; *) HOST_OS=unknown ;; esac
case $(uname -m) in x86_64 | amd64) HOST_ARCH=x86_64 ;; arm64 | aarch64) HOST_ARCH=arm64 ;; *) HOST_ARCH=unknown ;; esac

# install_into DIR [args]: run the installer against RELEASE with a data dir
# and bin dir under DIR, both with a space in the name.
run_install() {
    dir=$1; shift
    KNOSSOS_RELEASE_BASE="${BASE_OVERRIDE:-$RELEASE}" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE -u KNOSSOS_BIN_DIR \
        sh "$INSTALL" --no-plugin --data-dir="$dir/data dir" --bin-dir="$dir/bin dir" "$@" 2>&1 </dev/null
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
(BASE_OVERRIDE="file://$RELEASE" run_install "$T1B" >/dev/null 2>&1); status=$?
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
out=$(BASE_OVERRIDE=$BAD run_install "$T2" --version="$VERSION"); status=$?
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
out=$(BASE_OVERRIDE=$GONE run_install "$T3" --version="$VERSION"); status=$?
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
out=$(BASE_OVERRIDE=$NOLINE run_install "$WORK/noline-target" --version="$VERSION"); status=$?
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

# ---- a script cut off mid-download -----------------------------------------

# Under `curl | sh` a dropped connection delivers a prefix of the script. Cut
# at several line counts, it must create nothing and never produce `current`.
total=$(wc -l < "$INSTALL" | tr -d ' ')
cut_ok=yes
for n in $((total / 4)) $((total / 2)) $((total * 3 / 4)) $((total - 1)); do
    T6="$WORK/cut-$n"
    mkdir -p "$T6"
    head -n "$n" "$INSTALL" | KNOSSOS_RELEASE_BASE=$RELEASE sh -s -- --no-plugin --data-dir="$T6/data" --bin-dir="$T6/bin" --version="$VERSION" >/dev/null 2>&1
    if [ -e "$T6/data" ] || [ -e "$T6/bin" ]; then cut_ok=no; printf 'cut at %s lines created: %s\n' "$n" "$(find "$T6" -mindepth 1 | head -n 3 | tr '\n' ' ')"; fi
done
if [ "$cut_ok" = yes ]; then pass 'a script cut off at 25%, 50%, 75% and all but its last line creates nothing'; else fail 'a truncated script did something'; fi

# ---- helpers for tampered releases -----------------------------------------

# make_release DIR STUB: a release directory whose bin/knossos is the given
# stub script, repacked from the real tarball with a matching SHA256SUMS.
make_release() {
    rdir=$1
    mkdir -p "$rdir" "$rdir.x"
    tar -xzf "$RELEASE/$ASSET" -C "$rdir.x"
    printf '%s\n' "$2" > "$rdir.x/knossos-$VERSION/bin/knossos"
    chmod +x "$rdir.x/knossos-$VERSION/bin/knossos"
    pack_release "$rdir"
}

# pack_release DIR: repack DIR.x and write the matching SHA256SUMS line.
pack_release() {
    tar -czf "$1/$ASSET" -C "$1.x" "knossos-$VERSION"
    if command -v sha256sum >/dev/null 2>&1; then sum=$(sha256sum "$1/$ASSET" | cut -d ' ' -f 1); else sum=$(shasum -a 256 "$1/$ASSET" | cut -d ' ' -f 1); fi
    printf '%s  %s\n' "$sum" "$ASSET" > "$1/SHA256SUMS"
}

# link_release DIR NAME TARGET...: the real release with extra links, in pairs.
link_release() {
    rdir=$1; shift
    mkdir -p "$rdir" "$rdir.x"
    tar -xzf "$RELEASE/$ASSET" -C "$rdir.x"
    while [ "$#" -ge 2 ]; do
        mkdir -p "$(dirname "$rdir.x/knossos-$VERSION/$1")"
        ln -s "$2" "$rdir.x/knossos-$VERSION/$1"
        shift 2
    done
    pack_release "$rdir"
}

# The stub's doctor writes into whatever data dir it is given and exits with
# DOCTOR_EXIT; its version fails once install.json exists when asked to.
STUB_BODY="#!/bin/sh
case \"\$1\" in
    doctor) printf touched > \"\$KNOSSOS_DATA_DIR/knossos.sqlite\"; exit \"\${DOCTOR_EXIT:-0}\" ;;
    version) if [ -n \"\${STUB_FAIL_AFTER_SWITCH:-}\" ] && [ -f \"\$KNOSSOS_DATA_DIR/install.json\" ]; then exit 1; fi; echo 'Knossos $VERSION' ;;
esac"
make_release "$WORK/stub release" "$STUB_BODY"

# ---- the live database is never touched by doctor --------------------------

T7="$WORK/livedb"
mkdir -p "$T7/data dir"
printf 'KNOWN-DATABASE-BYTES' > "$T7/data dir/knossos.sqlite"
cp "$T7/data dir/knossos.sqlite" "$WORK/sqlite.before"
out=$(BASE_OVERRIDE="$WORK/stub release" run_install "$T7" --version="$VERSION"); status=$?
if [ "$status" -eq 0 ] && cmp -s "$T7/data dir/knossos.sqlite" "$WORK/sqlite.before"; then
    pass 'a successful install leaves an existing knossos.sqlite byte for byte alone'
else
    fail "live database after a successful install: status $status, output $out"
fi

T8="$WORK/livedb-fail"
mkdir -p "$T8/data dir"
cp "$WORK/sqlite.before" "$T8/data dir/knossos.sqlite"
out=$(BASE_OVERRIDE="$WORK/stub release" DOCTOR_EXIT=1 run_install "$T8" --version="$VERSION"); status=$?
if [ "$status" -ne 0 ] && cmp -s "$T8/data dir/knossos.sqlite" "$WORK/sqlite.before" && [ ! -e "$T8/data dir/current" ]; then
    pass 'an install that fails doctor leaves the database alone and switches nothing'
else
    fail "live database after a failed doctor: status $status, output $out"
fi

T9="$WORK/livedb-fresh"
mkdir -p "$T9"
(BASE_OVERRIDE="$WORK/stub release" run_install "$T9" --version="$VERSION" >/dev/null 2>&1)
run_install "$T1B" --version="$VERSION" >/dev/null 2>&1
if [ ! -e "$T9/data dir/knossos.sqlite" ] && [ ! -e "$T1B/data dir/knossos.sqlite" ]; then
    pass 'a fresh install leaves no knossos.sqlite in the data dir'
else
    fail 'a fresh install created knossos.sqlite'
fi

# ---- rollback after the switch ---------------------------------------------

T10="$WORK/rollback"
mkdir -p "$T10/data dir/versions/0.0.1/bin" "$T10/bin dir"
ln -s versions/0.0.1 "$T10/data dir/current"
printf '{"mode": "native", "version": "0.0.1"}\n' > "$T10/data dir/install.json"
cp "$T10/data dir/install.json" "$WORK/install.json.before"
printf '#!/bin/sh\necho OLD LAUNCHER\n' > "$T10/bin dir/knossos"
cp "$T10/bin dir/knossos" "$WORK/launcher.before"
out=$(BASE_OVERRIDE="$WORK/stub release" STUB_FAIL_AFTER_SWITCH=1 run_install "$T10" --version="$VERSION"); status=$?
if [ "$status" -ne 0 ] && [ "$(readlink "$T10/data dir/current")" = 'versions/0.0.1' ] \
    && cmp -s "$T10/data dir/install.json" "$WORK/install.json.before" \
    && cmp -s "$T10/bin dir/knossos" "$WORK/launcher.before"; then
    pass 'a failure after the switch restores current, install.json and the launcher'
else
    fail "rollback: status $status, current $(readlink "$T10/data dir/current"), output $out"
fi

# No previous install: the rollback removes what the install created.
T11="$WORK/rollback-fresh"
mkdir -p "$T11"
out=$(BASE_OVERRIDE="$WORK/stub release" STUB_FAIL_AFTER_SWITCH=1 run_install "$T11" --version="$VERSION"); status=$?
if [ "$status" -ne 0 ] && [ ! -e "$T11/data dir/current" ] && [ ! -e "$T11/data dir/install.json" ] && [ ! -e "$T11/bin dir/knossos" ]; then
    pass 'a failure after the switch on a fresh host leaves no current, install.json or launcher'
else
    fail "fresh rollback: status $status, output $out"
fi

# A release without a launcher template is refused before anything switches.
NOTPL="$WORK/notemplate"
mkdir -p "$NOTPL" "$NOTPL.x"
tar -xzf "$RELEASE/$ASSET" -C "$NOTPL.x"
rm -rf "$NOTPL.x/knossos-$VERSION/launcher"
tar -czf "$NOTPL/$ASSET" -C "$NOTPL.x" "knossos-$VERSION"
if command -v sha256sum >/dev/null 2>&1; then sum=$(sha256sum "$NOTPL/$ASSET" | cut -d ' ' -f 1); else sum=$(shasum -a 256 "$NOTPL/$ASSET" | cut -d ' ' -f 1); fi
printf '%s  %s\n' "$sum" "$ASSET" > "$NOTPL/SHA256SUMS"
T12="$WORK/notemplate-target"
mkdir -p "$T12/data dir/versions/0.0.1"
ln -s versions/0.0.1 "$T12/data dir/current"
out=$(BASE_OVERRIDE=$NOTPL run_install "$T12" --version="$VERSION"); status=$?
case $out in *"no launcher template"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ "$(readlink "$T12/data dir/current")" = 'versions/0.0.1' ]; then
    pass 'a release without a launcher template is refused before the switch'
else
    fail "no template: status $status, output $out"
fi

# ---- versions are validated ------------------------------------------------

T13="$WORK/badversion"
out=$(run_install "$T13" --version='../x'); status=$?
case $out in *"not a valid version"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$T13" ]; then pass "--version='../x' is refused"; else fail "bad version: status $status, output $out"; fi

GARBAGE="$WORK/garbage release"
mkdir -p "$GARBAGE"
: > "$GARBAGE/knossos-a..b-$HOST_OS-$HOST_ARCH.tar.gz"
out=$(BASE_OVERRIDE=$GARBAGE run_install "$WORK/garbage-target"); status=$?
case $out in *"not a valid version"*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ] && [ ! -e "$WORK/garbage-target" ]; then pass 'a latest that resolves to garbage is refused'; else fail "garbage latest: status $status, output $out"; fi

# ---- an apostrophe in the data dir -----------------------------------------

T14="$WORK/it's here"
mkdir -p "$T14"
out=$(run_install "$T14" --version="$VERSION"); status=$?
got=$(env -u KNOSSOS_DATA_DIR "$T14/bin dir/knossos" version 2>&1)
if [ "$status" -eq 0 ] && [ "$got" = "Knossos $VERSION" ]; then pass 'a data dir with an apostrophe works'; else fail "apostrophe: status $status, version said $got, output $out"; fi

# ---- the roots file --------------------------------------------------------

# The directory the installer runs from is granted, and what was there stays.
T15="$WORK/roots"
mkdir -p "$T15/data dir" "$T15/project"
printf '{"roots": ["/existing/project"]}\n' > "$T15/data dir/roots.json"
out=$(cd "$T15/project" && run_install "$T15" --version="$VERSION"); status=$?
if [ "$status" -eq 0 ] && grep -q '/existing/project' "$T15/data dir/roots.json" && grep -qF "$T15/project" "$T15/data dir/roots.json"; then
    pass 'the working directory is merged into the roots file, not written over it'
else
    fail "roots merge: status $status, file: $(cat "$T15/data dir/roots.json"), output $out"
fi

T16="$WORK/noroots"
mkdir -p "$T16/project"
out=$(cd "$T16/project" && run_install "$T16" --version="$VERSION" --no-roots); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$T16/data dir/roots.json" ]; then pass '--no-roots writes no roots file'; else fail "--no-roots: status $status, output $out"; fi

# Piped from the home directory or /: nothing is granted, and it says so.
T17="$WORK/home skip"
mkdir -p "$T17/home"
out=$(cd "$T17/home" && HOME="$T17/home" KNOSSOS_RELEASE_BASE=$RELEASE env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE sh "$INSTALL" --no-plugin --data-dir="$T17/data" --bin-dir="$T17/bin" --version="$VERSION" 2>&1 </dev/null); status=$?
case $out in *"no project root granted"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ ! -e "$T17/data/roots.json" ]; then pass 'run from the home directory without a terminal, no root is granted and it says so'; else fail "home skip: status $status, output $out"; fi

T18="$WORK/slash skip"
mkdir -p "$T18"
out=$(cd / && run_install "$T18" --version="$VERSION"); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$T18/data dir/roots.json" ]; then pass 'run from / without a terminal, no root is granted'; else fail "slash skip: status $status, output $out"; fi

# KNOSSOS_ALLOW_ROOT still overrides.
T19="$WORK/allow"
mkdir -p "$T19"
out=$(cd / && KNOSSOS_ALLOW_ROOT="$T19/chosen" run_install "$T19" --version="$VERSION"); status=$?
if [ "$status" -eq 0 ] && grep -qF "$T19/chosen" "$T19/data dir/roots.json"; then pass 'KNOSSOS_ALLOW_ROOT overrides the skip'; else fail "allow root: status $status, output $out"; fi

# ---- an interrupt after the switch (N1) -------------------------------------

# The stub's version call, made by the installed launcher after the switch,
# marks that the switch happened and then lingers; the installer is sent TERM
# in the middle of it. Everything must go back, with no temporary file left.
SLOW_BODY="#!/bin/sh
case \"\$1\" in
    doctor) exit 0 ;;
    version) if [ -f \"\$KNOSSOS_DATA_DIR/install.json\" ] && [ -n \"\${STUB_MARK:-}\" ]; then : > \"\$STUB_MARK\"; sleep 2; fi; echo 'Knossos $VERSION' ;;
esac"
make_release "$WORK/slow release" "$SLOW_BODY"
T20="$WORK/interrupt"
mkdir -p "$T20/data dir/versions/0.0.1/bin" "$T20/bin dir"
ln -s versions/0.0.1 "$T20/data dir/current"
printf '{"mode": "native", "version": "0.0.1"}\n' > "$T20/data dir/install.json"
cp "$T20/data dir/install.json" "$WORK/install.json.before20"
printf '#!/bin/sh\necho OLD LAUNCHER\n' > "$T20/bin dir/knossos"
cp "$T20/bin dir/knossos" "$WORK/launcher.before20"
KNOSSOS_RELEASE_BASE="$WORK/slow release" STUB_MARK="$T20/mark" env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE -u KNOSSOS_BIN_DIR \
    sh "$INSTALL" --no-plugin --data-dir="$T20/data dir" --bin-dir="$T20/bin dir" --version="$VERSION" > "$WORK/interrupt.out" 2>&1 </dev/null &
installer=$!
waited=0
while [ ! -e "$T20/mark" ] && [ "$waited" -lt 100 ]; do sleep 0.1; waited=$((waited + 1)); done
kill -TERM "$installer" 2>/dev/null
wait "$installer" 2>/dev/null
status=$?
left=$(find "$T20/bin dir" "$T20/data dir" -name '*.tmp.*' | head -n 3)
if [ -e "$T20/mark" ] && [ "$status" -ne 0 ] && [ "$(readlink "$T20/data dir/current")" = 'versions/0.0.1' ] \
    && cmp -s "$T20/data dir/install.json" "$WORK/install.json.before20" \
    && cmp -s "$T20/bin dir/knossos" "$WORK/launcher.before20" && [ -z "$left" ]; then
    pass 'an interrupt after the switch restores the previous state and leaves no temporary file'
else
    fail "interrupt: status $status, mark $([ -e "$T20/mark" ] && echo yes || echo no), current $(readlink "$T20/data dir/current"), left: $left"
fi

# ---- links that point outside the release ------------------------------------

# Each hostile release is the real one plus links, repacked with a matching
# checksum, so the installer refuses at the link check and not earlier.
# expect_link_refusal NAME MEMBER ARGS...
expect_link_refusal() {
    label=$1; member=$2; shift 2
    tdir="$WORK/links-$label"
    link_release "$tdir/rel" "$@"
    mkdir -p "$tdir/home"
    out=$(BASE_OVERRIDE="$tdir/rel" run_install "$tdir/home" --version="$VERSION"); status=$?
    left=$(find "$tdir/home" -name '*.partial*' -o -name current | head -n 3)
    case $out in *"$member"*) named=1 ;; *) named=0 ;; esac
    if [ "$status" -ne 0 ] && [ "$named" = 1 ] && [ -z "$left" ] && [ ! -e "$tdir/home/data dir/versions/$VERSION" ]; then
        pass "a release with $label is refused, names the member and leaves nothing"
    else
        fail "$label: status $status, named $named, left: $left, output: $out"
    fi
}
expect_link_refusal 'a link to /etc' evil evil /etc
expect_link_refusal 'a chain of links' d/B d/S .. d/B S/../../etc
expect_link_refusal 'a dangling link that climbs' g g nothere/../../etc
expect_link_refusal 'a link to itself' self self self

T24="$WORK/links-ok"
mkdir -p "$T24"
link_release "$T24/rel" h nothere/../bin
out=$(BASE_OVERRIDE="$T24/rel" run_install "$T24" --version="$VERSION"); status=$?
if [ "$status" -eq 0 ] && [ -L "$T24/data dir/current" ]; then pass 'a dangling link that stays inside the release is accepted'; else fail "inside link: $status $out"; fi

# ---- a directory the release makes unreadable --------------------------------

# tar cannot store a directory it cannot read, so the archive is written with
# tarfile: a mode-0111 directory holding a link out of the release. The
# installer must refuse it and still remove everything it unpacked.
T25="$WORK/unreadable"
mkdir -p "$T25/rel" "$T25/home"
cat > "$T25/pack.py" <<'PY'
import sys, tarfile
src, dst, prefix = sys.argv[1:4]
with tarfile.open(src, "r:gz") as old, tarfile.open(dst, "w:gz") as new:
    for member in old.getmembers():
        new.addfile(member, old.extractfile(member) if member.isfile() else None)
    hid = tarfile.TarInfo(prefix + "/hid")
    hid.type, hid.mode = tarfile.DIRTYPE, 0o111
    new.addfile(hid)
    evil = tarfile.TarInfo(prefix + "/hid/evil")
    evil.type, evil.linkname = tarfile.SYMTYPE, "/etc"
    new.addfile(evil)
PY
python3 -I "$T25/pack.py" "$RELEASE/$ASSET" "$T25/rel/$ASSET" "knossos-$VERSION"
if command -v sha256sum >/dev/null 2>&1; then sum=$(sha256sum "$T25/rel/$ASSET" | cut -d ' ' -f 1); else sum=$(shasum -a 256 "$T25/rel/$ASSET" | cut -d ' ' -f 1); fi
printf '%s  %s\n' "$sum" "$ASSET" > "$T25/rel/SHA256SUMS"
out=$(BASE_OVERRIDE="$T25/rel" run_install "$T25/home" --version="$VERSION"); status=$?
left=$(find "$T25/home" \( -name '*.partial*' -o -name current \) 2>/dev/null | head -n 3)
case $out in *hid*) named=1 ;; *) named=0 ;; esac
if [ "$status" -ne 0 ] && [ "$named" = 1 ] && [ -z "$left" ]; then
    pass 'a release with an unreadable directory is refused, names it and leaves no partial unpack'
else
    fail "unreadable directory: status $status, named $named, left: $left, output: $out"
fi

# ---- a cut that keeps main but loses its arguments (N2) ---------------------

# `main` without "$@" would run with no arguments: the defaults, under the
# caller's HOME. The brace group has no closing brace in a cut copy, so the
# shell must refuse to run any of it.
T21="$WORK/cut-main"
mkdir -p "$T21/home"
total=$(wc -l < "$INSTALL" | tr -d ' ')
{ head -n $((total - 2)) "$INSTALL"; printf 'main'; } \
    | HOME="$T21/home" KNOSSOS_RELEASE_BASE=$RELEASE sh -s -- --no-plugin --data-dir="$T21/data" --version="$VERSION" >/dev/null 2>&1
{ head -n $((total - 2)) "$INSTALL"; printf 'main "$@"; exit'; } \
    | HOME="$T21/home" KNOSSOS_RELEASE_BASE=$RELEASE sh -s -- --no-plugin --data-dir="$T21/data" --version="$VERSION" >/dev/null 2>&1
if [ -z "$(ls -A "$T21/home")" ] && [ ! -e "$T21/data" ]; then
    pass 'a script cut between main and its arguments, or just before the closing brace, runs nothing'
else
    fail "cut at main created: $(find "$T21" -mindepth 1 | head -n 3 | tr '\n' ' ')"
fi
case $(tail -n 2 "$INSTALL" | tr '\n' ' ') in
    'main "$@"; exit } ') pass 'the script ends with main "$@"; exit and the closing brace' ;;
    *) fail "unexpected end of install.sh: $(tail -n 2 "$INSTALL")" ;;
esac

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi

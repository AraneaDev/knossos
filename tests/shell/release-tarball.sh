#!/bin/sh
# tools/release-tarball must build a complete, self-contained release: the
# right top directory and version, no development files, a checksum that
# verifies, and a tree that runs doctor and scans a project on its own.
# The build is slow (composer, npm, cargo), so it happens once.
set -u
# shellcheck source=tests/shell/lib/scratch.sh
. "$(dirname -- "$0")/lib/scratch.sh"

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT="$HERE/../.."
failures=0
WORK=$(mktemp -d)

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; failures=$((failures + 1)); }

OUT="$WORK/release out"
if ! sh "$ROOT/tools/release-tarball" --out "$OUT" > "$WORK/build.log" 2>&1; then
    printf 'FAIL the build failed\n'
    cat "$WORK/build.log"
    exit 1
fi

VERSION=$(php "$ROOT/bin/knossos" version | sed -n 's/^Knossos //p')
case $(uname -s) in Linux) os=linux ;; Darwin) os=darwin ;; *) os=unknown ;; esac
case $(uname -m) in x86_64 | amd64) arch=x86_64 ;; arm64 | aarch64) arch=arm64 ;; *) arch=unknown ;; esac
ASSET="knossos-$VERSION-$os-$arch.tar.gz"

if [ -f "$OUT/$ASSET" ]; then pass "builds $ASSET"; else fail "missing $OUT/$ASSET"; exit 1; fi

mkdir "$WORK/unpacked"
tar -xzf "$OUT/$ASSET" -C "$WORK/unpacked"
tops=$(ls "$WORK/unpacked")
if [ "$tops" = "knossos-$VERSION" ]; then pass 'one top directory named knossos-<version>'; else fail "top level is: $tops"; fi
TREE="$WORK/unpacked/knossos-$VERSION"

if [ "$(cat "$TREE/VERSION")" = "$(php "$TREE/bin/knossos" version | sed -n 's/^Knossos //p')" ]; then
    pass 'VERSION equals bin/knossos version'
else
    fail 'VERSION differs from bin/knossos version'
fi

absent=''
for path in tests docs coverage .git workers/typescript/vitest.config.js; do
    [ -e "$TREE/$path" ] && absent="$absent $path"
done
if [ -z "$absent" ]; then pass 'tests, tools, docs and coverage are absent'; else fail "present, but must not ship:$absent"; fi

# The only file under tools/ is the module the web and tui entry points load.
tools_files=$(find "$TREE/tools" -type f 2>/dev/null | sed "s|^$TREE/||")
if [ "$tools_files" != 'tools/ts-resolve.mjs' ]; then
    fail "tools/ holds more than ts-resolve.mjs: $tools_files"
else
    pass 'tools/ holds nothing but ts-resolve.mjs'
fi

present=''
for path in bin/knossos src migrations vendor/autoload.php workers/php/vendor/autoload.php \
    workers/typescript/node_modules/typescript workers/python/bin workers/rust/bin/knossos-rust-worker \
    hooks skills plugins .claude-plugin composer.json LICENSE README.md launcher/knossos; do
    [ -e "$TREE/$path" ] || present="$present $path"
done
if [ -z "$present" ]; then pass 'the runtime files are present'; else fail "missing:$present"; fi
if [ -x "$TREE/launcher/knossos" ] && [ -x "$TREE/launcher/mcp-serve" ] && cmp -s "$TREE/launcher/mcp-serve" "$ROOT/tools/mcp-serve"; then
    pass 'the launcher template and the mcp-serve wrapper ship, executable'
else
    fail 'launcher/knossos or launcher/mcp-serve is missing, not executable or not the one in tools/'
fi

# Production dependencies only: PHPUnit and vitest are dev dependencies.
if [ ! -e "$TREE/vendor/phpunit" ] && [ ! -e "$TREE/workers/typescript/node_modules/vitest" ]; then
    pass 'no dev dependencies'
else
    fail 'a dev dependency shipped'
fi

if (cd "$OUT" && { sha256sum -c SHA256SUMS >/dev/null 2>&1 || shasum -a 256 -c SHA256SUMS >/dev/null 2>&1; }); then
    pass 'SHA256SUMS verifies'
else
    fail 'SHA256SUMS does not verify'
fi

lines=$(wc -l < "$OUT/SHA256SUMS" | tr -d ' ')
if [ "$lines" -eq 1 ]; then pass 'SHA256SUMS holds one line for one asset'; else fail "SHA256SUMS has $lines lines"; fi

DATA="$WORK/data dir"
mkdir -p "$DATA"
if KNOSSOS_DATA_DIR="$DATA" php "$TREE/bin/knossos" doctor --json > "$WORK/doctor.json" 2>&1; then
    pass 'doctor --json exits 0 from the unpacked tree'
else
    fail "doctor failed: $(head -c 600 "$WORK/doctor.json")"
fi

cp -R "$ROOT/tests/Fixtures/php-signatures" "$WORK/project"
if KNOSSOS_DATA_DIR="$DATA" php "$TREE/bin/knossos" scan "$WORK/project" > "$WORK/scan.log" 2>&1 \
    && KNOSSOS_DATA_DIR="$DATA" php "$TREE/bin/knossos" find-component "$WORK/project" 'App\Finder::find' --json > "$WORK/find.json" 2>&1 \
    && grep -qF '"signature":"find(id: int): ?App\\Model\\User"' "$WORK/find.json"; then
    pass 'scans a project and find-component returns the signature of App\Finder::find'
else
    fail "scan or find-component failed: $(head -c 600 "$WORK/scan.log") $(head -c 600 "$WORK/find.json" 2>/dev/null)"
fi

# No host user names in the archive: owner and group are numeric zero.
owner=$(tar -tvzf "$OUT/$ASSET" | sed -n 1p | awk '{ if ($2 == "0/0" || ($2 == "0" && $3 == "0")) print "zero"; else print $2 " " $3 }')
if [ "$owner" = zero ]; then pass 'the archive records numeric owner 0'; else fail "the archive records owner: $owner"; fi

# A source side that fails stops the build instead of leaving a partial tree.
STUB="$WORK/failing tar"
mkdir -p "$STUB"
REAL_TAR=$(command -v tar)
printf '#!/bin/sh\ncase " $* " in *" -cf "*) exit 3 ;; esac\nexec "%s" "$@"\n' "$REAL_TAR" > "$STUB/tar"
chmod +x "$STUB/tar"
if PATH="$STUB:$PATH" sh "$ROOT/tools/release-tarball" --out "$WORK/failed out" > "$WORK/failed.log" 2>&1; then
    fail 'the build succeeded although archiving a source path failed'
else
    pass 'a failing source-side tar stops the build'
fi

printf 'tarball: %s bytes\n' "$(wc -c < "$OUT/$ASSET" | tr -d ' ')"

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi
